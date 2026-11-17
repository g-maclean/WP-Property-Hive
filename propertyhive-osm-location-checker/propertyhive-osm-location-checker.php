<?php
/**
 * Plugin Name: Property Hive OSM Location Checker
 * Description: Uses OpenStreetMap (Nominatim) to look up each property's neighbourhood / suburb and compares it against what has been entered manually (Location taxonomy and custom fields such as Suburb and Region), to see how reliably these could be assigned automatically.
 * Version: 1.1.3
 * Author: Property Hive
 * Requires at least: 5.6
 * Requires PHP: 7.2
 * Text Domain: ph-osm-location-checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PH_OSM_Location_Checker {

	const VERSION     = '1.1.3';
	const DB_VERSION  = '2';
	const OPTION      = 'phosm_settings';
	const PAGE_SLUG   = 'ph-osm-location-checker';
	const NONCE       = 'phosm_nonce';
	const LOCK_OPTION = 'phosm_lock';
	const STALE       = 'phosm_stale';

	/**
	 * OSM address fields we look at, most specific first. This is also the order used
	 * when picking a value automatically for "name" style targets.
	 */
	public static $fields = array(
		'neighbourhood',
		'suburb',
		'quarter',
		'residential',
		'hamlet',
		'village',
		'borough',
		'city_district',
		'town',
		'city',
		'municipality',
		'county',
	);

	/** Extra derived feature used when predicting group targets (e.g. Region). */
	const POSTCODE_FEATURE = 'postcode_district';

	/** The fields this experiment is really about. */
	public static $primary_fields = array( 'neighbourhood', 'suburb' );

	private static $instance = null;

	/** @var array|null normalised name => term ids */
	private $term_index = null;
	/** @var array term id => WP_Term */
	private $terms = array();
	/** @var array meta key => array( normalised => display ) */
	private $vocab = array();
	/** @var array Details of the last OSM request, for the test tool. */
	private $last_request = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( __FILE__, array( __CLASS__, 'install' ) );

		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 99 );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );

		add_action( 'wp_ajax_phosm_step', array( $this, 'ajax_step' ) );
		add_action( 'wp_ajax_phosm_stats', array( $this, 'ajax_stats' ) );
		add_action( 'wp_ajax_phosm_test', array( $this, 'ajax_test' ) );

		add_action( 'admin_post_phosm_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_phosm_reset', array( $this, 'handle_reset' ) );
		add_action( 'admin_post_phosm_retry_errors', array( $this, 'handle_retry_errors' ) );
		add_action( 'admin_post_phosm_recompare', array( $this, 'handle_recompare' ) );
		add_action( 'admin_post_phosm_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_post_phosm_export', array( $this, 'handle_export' ) );
	}

	/* ---------------------------------------------------------------------
	 * Install / tables
	 * ------------------------------------------------------------------- */

	public static function results_table() {
		global $wpdb;
		return $wpdb->prefix . 'phosm_results';
	}

	public static function compare_table() {
		global $wpdb;
		return $wpdb->prefix . 'phosm_compare';
	}

	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'phosm_log';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// v1 stored comparison columns on the results table; the structure changed, so start that table fresh.
		if ( '1' === get_option( 'phosm_db_version' ) ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::results_table() ); // phpcs:ignore
		}

		$charset = $wpdb->get_charset_collate();
		$results = self::results_table();
		$compare = self::compare_table();
		$log     = self::log_table();

		dbDelta( "CREATE TABLE {$results} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			property_id bigint(20) unsigned NOT NULL,
			status varchar(10) NOT NULL DEFAULT 'ok',
			source varchar(20) NOT NULL DEFAULT '',
			lat decimal(10,7) NULL,
			lng decimal(10,7) NULL,
			osm_neighbourhood varchar(191) NOT NULL DEFAULT '',
			osm_suburb varchar(191) NOT NULL DEFAULT '',
			osm_postcode varchar(20) NOT NULL DEFAULT '',
			osm_display_name text NULL,
			osm_address longtext NULL,
			error text NULL,
			checked_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY property_id (property_id),
			KEY status (status)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$compare} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			property_id bigint(20) unsigned NOT NULL,
			target varchar(64) NOT NULL,
			current_value text NULL,
			suggested varchar(255) NOT NULL DEFAULT '',
			suggested_field varchar(30) NOT NULL DEFAULT '',
			matched_field varchar(30) NOT NULL DEFAULT '',
			outcome varchar(20) NOT NULL DEFAULT '',
			details longtext NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY property_target (property_id,target),
			KEY target_outcome (target,outcome)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			logged_at datetime NOT NULL,
			level varchar(10) NOT NULL DEFAULT 'info',
			property_id bigint(20) unsigned NOT NULL DEFAULT 0,
			message text NOT NULL,
			PRIMARY KEY  (id),
			KEY logged_at (logged_at)
		) {$charset};" );

		update_option( 'phosm_db_version', self::DB_VERSION, false );
	}

	public function maybe_upgrade() {
		if ( get_option( 'phosm_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/* ---------------------------------------------------------------------
	 * Settings & targets
	 * ------------------------------------------------------------------- */

	public static function defaults() {
		return array(
			'endpoint'         => 'https://nominatim.openstreetmap.org',
			'email'            => '',
			'language'         => 'en',
			'delay'            => 1.5,
			'address_fallback' => 1,
			'on_market_only'   => 0,
			'statuses'         => array( 'publish' ),
			'location_target'  => 1,
			'meta_targets'     => "_suburb|name|Suburb|suburb\n_region|group|Region",
		);
	}

	public static function settings() {
		$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$settings['delay'] = max( self::min_delay( $settings['endpoint'] ), (float) $settings['delay'] );
		if ( empty( $settings['statuses'] ) || ! is_array( $settings['statuses'] ) ) {
			$settings['statuses'] = array( 'publish' );
		}
		return $settings;
	}

	/**
	 * What we compare OSM against.
	 *
	 * Types:
	 *  - taxonomy: the Property Hive Location taxonomy.
	 *  - name:     free-text meta (e.g. _suburb) compared directly with OSM area names.
	 *  - group:    meta holding a grouping OSM doesn't know about (e.g. _region = "Birmingham South").
	 *              A mapping OSM area → group is learned from existing data and checked with leave-one-out.
	 *
	 * @return array key => array( 'type' => ..., 'label' => ... )
	 */
	public static function targets() {
		$settings = self::settings();
		$targets  = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $settings['meta_targets'] ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			$key   = preg_replace( '/[^A-Za-z0-9_\-]/', '', $parts[0] ?? '' );
			if ( '' === $key || 'location' === $key ) {
				continue;
			}
			$type  = in_array( $parts[1] ?? '', array( 'name', 'group' ), true ) ? $parts[1] : 'name';
			$label = ( isset( $parts[2] ) && '' !== $parts[2] ) ? $parts[2] : $key;
			// Optional 4th part: which OSM fields may be used to pick the value, in priority order.
			$allowed = array_merge( self::$fields, array( self::POSTCODE_FEATURE ) );
			$fields  = array_values( array_intersect( array_map( 'trim', explode( ',', strtolower( $parts[3] ?? '' ) ) ), $allowed ) );
			$targets[ $key ] = array( 'type' => $type, 'label' => $label, 'fields' => $fields );
		}
		if ( $settings['location_target'] ) {
			$targets['location'] = array( 'type' => 'taxonomy', 'label' => __( 'Location (taxonomy)', 'ph-osm-location-checker' ), 'fields' => array() );
		}
		return $targets;
	}

	/**
	 * The public Nominatim service allows an absolute maximum of 1 request per second.
	 * A self-hosted instance can go faster.
	 */
	public static function min_delay( $endpoint ) {
		$host = (string) wp_parse_url( $endpoint, PHP_URL_HOST );
		return ( false !== stripos( $host, 'openstreetmap.org' ) ) ? 1.0 : 0.1;
	}

	public function handle_save_settings() {
		$this->check_admin_request( 'phosm_save_settings' );

		$endpoint = esc_url_raw( trim( wp_unslash( $_POST['endpoint'] ?? '' ) ) );
		if ( '' === $endpoint ) {
			$endpoint = self::defaults()['endpoint'];
		}

		$statuses = array_map( 'sanitize_key', (array) ( $_POST['statuses'] ?? array() ) );
		$statuses = array_values( array_intersect( $statuses, array( 'publish', 'private', 'draft', 'pending', 'future' ) ) );

		update_option(
			self::OPTION,
			array(
				'endpoint'         => untrailingslashit( $endpoint ),
				'email'            => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
				'language'         => sanitize_text_field( wp_unslash( $_POST['language'] ?? 'en' ) ),
				'delay'            => max( self::min_delay( $endpoint ), (float) ( $_POST['delay'] ?? 1.5 ) ),
				'address_fallback' => empty( $_POST['address_fallback'] ) ? 0 : 1,
				'on_market_only'   => empty( $_POST['on_market_only'] ) ? 0 : 1,
				'statuses'         => $statuses ? $statuses : array( 'publish' ),
				'location_target'  => empty( $_POST['location_target'] ) ? 0 : 1,
				'meta_targets'     => sanitize_textarea_field( wp_unslash( $_POST['meta_targets'] ?? '' ) ),
			),
			false
		);
		update_option( self::STALE, 1, false );

		$this->redirect( 'settings', 'settings_saved' );
	}

	/* ---------------------------------------------------------------------
	 * Admin page
	 * ------------------------------------------------------------------- */

	public function dependency_notice() {
		if ( ! current_user_can( 'activate_plugins' ) || taxonomy_exists( 'location' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Property Hive OSM Location Checker requires Property Hive to be active.', 'ph-osm-location-checker' ) . '</p></div>';
	}

	private static function capability() {
		return apply_filters( 'phosm_capability', 'manage_propertyhive' );
	}

	public function admin_menu() {
		$parent = class_exists( 'PropertyHive' ) ? 'propertyhive' : 'tools.php';
		$cap    = class_exists( 'PropertyHive' ) ? self::capability() : 'manage_options';

		$hook = add_submenu_page(
			$parent,
			__( 'OSM Location Checker', 'ph-osm-location-checker' ),
			__( 'OSM Location Checker', 'ph-osm-location-checker' ),
			$cap,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		add_action( 'admin_print_scripts-' . $hook, array( $this, 'enqueue' ) );
	}

	public function enqueue() {
		$url = plugin_dir_url( __FILE__ );
		wp_enqueue_style( 'phosm-admin', $url . 'assets/admin.css', array(), self::VERSION );
		wp_enqueue_script( 'phosm-admin', $url . 'assets/admin.js', array( 'jquery' ), self::VERSION, true );
		wp_localize_script(
			'phosm-admin',
			'phosm',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'running'  => __( 'Running…', 'ph-osm-location-checker' ),
					'stopping' => __( 'Stopping after the current property…', 'ph-osm-location-checker' ),
					'finished' => __( 'Finished', 'ph-osm-location-checker' ),
					'stats'    => __( 'Updating statistics…', 'ph-osm-location-checker' ),
				),
			)
		);
	}

	private static function page_url( $tab = 'run', $args = array() ) {
		$parent = class_exists( 'PropertyHive' ) ? 'admin.php' : 'tools.php';
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG, 'tab' => $tab ), $args ), admin_url( $parent ) );
	}

	private function current_target() {
		$targets = self::targets();
		$target  = sanitize_text_field( wp_unslash( $_REQUEST['target'] ?? '' ) );
		if ( isset( $targets[ $target ] ) ) {
			return $target;
		}
		return $targets ? (string) key( $targets ) : '';
	}

	public function render_page() {
		if ( ! $this->user_can() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'ph-osm-location-checker' ) );
		}

		$tab  = sanitize_key( $_GET['tab'] ?? 'run' );
		$tabs = array(
			'run'      => __( 'Run & Stats', 'ph-osm-location-checker' ),
			'results'  => __( 'Results', 'ph-osm-location-checker' ),
			'log'      => __( 'Log', 'ph-osm-location-checker' ),
			'settings' => __( 'Settings', 'ph-osm-location-checker' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'run';
		}

		echo '<div class="wrap phosm-wrap">';
		echo '<h1>' . esc_html__( 'OSM Location Checker', 'ph-osm-location-checker' ) . '</h1>';

		$this->render_admin_message();

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( self::page_url( $key ) ),
				$key === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		call_user_func( array( $this, 'render_tab_' . $tab ) );

		echo '</div>';
	}

	private function render_admin_message() {
		$messages = array(
			'settings_saved' => __( 'Settings saved.', 'ph-osm-location-checker' ),
			'reset'          => __( 'All results cleared. The next run will start from the beginning.', 'ph-osm-location-checker' ),
			'retry_errors'   => __( 'Errored properties have been queued to be checked again.', 'ph-osm-location-checker' ),
			'recompared'     => __( 'Results re-compared against the current data using the cached OSM data.', 'ph-osm-location-checker' ),
			'log_cleared'    => __( 'Log cleared.', 'ph-osm-location-checker' ),
		);
		$key = sanitize_key( $_GET['phosm_msg'] ?? '' );
		if ( isset( $messages[ $key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
		}
	}

	private function admin_post_form( $action, $label, $class = 'button', $confirm = '' ) {
		printf(
			'<form method="post" action="%s" class="phosm-inline-form"%s>',
			esc_url( admin_url( 'admin-post.php' ) ),
			$confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\');"' : ''
		);
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	private function render_tab_run() {
		$counts   = $this->queue_counts();
		$settings = self::settings();
		$targets  = self::targets();
		$target   = $this->current_target();
		?>
		<div class="phosm-card">
			<h2><?php esc_html_e( 'Run lookup', 'ph-osm-location-checker' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: seconds */
					esc_html__( 'Each property is looked up one at a time with at least %s seconds between OSM requests, in line with the Nominatim usage policy. Runs are resumable: properties already looked up are skipped, so you can run in short bursts.', 'ph-osm-location-checker' ),
					esc_html( number_format_i18n( $settings['delay'], 1 ) )
				);
				?>
			</p>

			<p class="phosm-counts">
				<?php esc_html_e( 'Properties in scope:', 'ph-osm-location-checker' ); ?> <strong id="phosm-total"><?php echo esc_html( number_format_i18n( $counts['total'] ) ); ?></strong>
				&nbsp;|&nbsp; <?php esc_html_e( 'Looked up:', 'ph-osm-location-checker' ); ?> <strong id="phosm-checked"><?php echo esc_html( number_format_i18n( $counts['checked'] ) ); ?></strong>
				&nbsp;|&nbsp; <?php esc_html_e( 'Remaining:', 'ph-osm-location-checker' ); ?> <strong id="phosm-remaining"><?php echo esc_html( number_format_i18n( $counts['remaining'] ) ); ?></strong>
			</p>

			<div class="phosm-controls">
				<label><?php esc_html_e( 'Run for', 'ph-osm-location-checker' ); ?>
					<input type="number" id="phosm-minutes" min="1" max="240" value="10" class="small-text"> <?php esc_html_e( 'minutes', 'ph-osm-location-checker' ); ?>
				</label>
				<label><?php esc_html_e( 'or at most', 'ph-osm-location-checker' ); ?>
					<input type="number" id="phosm-max" min="1" value="500" class="small-text"> <?php esc_html_e( 'properties', 'ph-osm-location-checker' ); ?>
				</label>
				<button type="button" class="button button-primary" id="phosm-start" <?php disabled( 0 === $counts['remaining'] ); ?>><?php esc_html_e( 'Start', 'ph-osm-location-checker' ); ?></button>
				<button type="button" class="button" id="phosm-stop" disabled><?php esc_html_e( 'Stop', 'ph-osm-location-checker' ); ?></button>
			</div>

			<div class="phosm-progress" hidden>
				<div class="phosm-progress-bar"><span style="width:0%"></span></div>
				<p id="phosm-status"></p>
				<ol id="phosm-live-log" class="phosm-live-log"></ol>
			</div>

			<div class="phosm-maintenance">
				<?php
				$this->admin_post_form( 'phosm_recompare', __( 'Re-compare using cached OSM data', 'ph-osm-location-checker' ) );
				$this->admin_post_form( 'phosm_retry_errors', __( 'Retry errored properties', 'ph-osm-location-checker' ) );
				$this->admin_post_form( 'phosm_export', __( 'Export results (CSV)', 'ph-osm-location-checker' ) );
				$this->admin_post_form( 'phosm_reset', __( 'Clear all results', 'ph-osm-location-checker' ), 'button button-link-delete', __( 'Clear all results? Every property will need looking up on OSM again.', 'ph-osm-location-checker' ) );
				?>
			</div>
			<p class="description"><?php esc_html_e( '“Re-compare” makes no OSM requests: use it after editing suburbs/regions/Locations to see how the numbers change.', 'ph-osm-location-checker' ); ?></p>
		</div>

		<div class="phosm-card">
			<h2><?php esc_html_e( 'Test a lookup', 'ph-osm-location-checker' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Look up a single property or coordinate and see exactly what OSM returns. Nothing is saved.', 'ph-osm-location-checker' ); ?></p>
			<div class="phosm-controls">
				<label><?php esc_html_e( 'Property ID', 'ph-osm-location-checker' ); ?> <input type="number" id="phosm-test-id" class="small-text"></label>
				<?php esc_html_e( 'or', 'ph-osm-location-checker' ); ?>
				<label><?php esc_html_e( 'Lat', 'ph-osm-location-checker' ); ?> <input type="text" id="phosm-test-lat" class="regular-text phosm-coord"></label>
				<label><?php esc_html_e( 'Lng', 'ph-osm-location-checker' ); ?> <input type="text" id="phosm-test-lng" class="regular-text phosm-coord"></label>
				<button type="button" class="button" id="phosm-test"><?php esc_html_e( 'Test', 'ph-osm-location-checker' ); ?></button>
			</div>
			<pre id="phosm-test-output" class="phosm-test-output" hidden></pre>
		</div>

		<div class="phosm-card">
			<?php if ( count( $targets ) > 1 ) : ?>
				<ul class="subsubsub phosm-targets">
					<?php
					$links = array();
					foreach ( $targets as $key => $t ) {
						$links[] = sprintf(
							'<li><a href="%s"%s>%s</a></li>',
							esc_url( self::page_url( 'run', array( 'target' => $key ) ) ),
							$key === $target ? ' class="current"' : '',
							esc_html( $t['label'] )
						);
					}
					echo implode( ' | ', $links ); // phpcs:ignore -- escaped above
					?>
				</ul>
				<br class="clear">
			<?php endif; ?>
			<div id="phosm-stats" data-target="<?php echo esc_attr( $target ); ?>">
				<?php $this->render_stats( $target ); ?>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Outcomes
	 * ------------------------------------------------------------------- */

	public static function outcome_labels( $type = '' ) {
		$all = array(
			'match'         => array( __( 'Match', 'ph-osm-location-checker' ), __( 'What we would assign automatically equals what was entered.', 'ph-osm-location-checker' ) ),
			'partial'       => array( __( 'Partial match', 'ph-osm-location-checker' ), __( 'An OSM area matches what was entered, but a different (more specific) OSM field would have been picked first.', 'ph-osm-location-checker' ) ),
			'broader'       => array( __( 'Broader', 'ph-osm-location-checker' ), __( 'We would pick a parent of the assigned Location (e.g. town instead of suburb).', 'ph-osm-location-checker' ) ),
			'narrower'      => array( __( 'Narrower', 'ph-osm-location-checker' ), __( 'We would pick a child of the assigned Location (more specific than what was entered).', 'ph-osm-location-checker' ) ),
			'mismatch'      => array( __( 'Mismatch', 'ph-osm-location-checker' ), __( 'We would assign something different to what was entered.', 'ph-osm-location-checker' ) ),
			'no_term'       => array( __( 'Unknown value', 'ph-osm-location-checker' ), __( 'OSM returned a neighbourhood/suburb, but it is not an existing value and none of the OSM areas match what was entered.', 'ph-osm-location-checker' ) ),
			'no_prediction' => array( __( 'No prediction', 'ph-osm-location-checker' ), __( 'None of this property’s OSM areas have been seen on another property with this field filled in.', 'ph-osm-location-checker' ) ),
			'no_osm_data'   => array( __( 'No OSM data', 'ph-osm-location-checker' ), __( 'OSM returned no usable neighbourhood/suburb/area.', 'ph-osm-location-checker' ) ),
			'unassigned'    => array( __( 'Empty', 'ph-osm-location-checker' ), __( 'Nothing entered on the property yet. Not counted in accuracy; shows what would be assigned.', 'ph-osm-location-checker' ) ),
			'error'         => array( __( 'Error', 'ph-osm-location-checker' ), __( 'Lookup failed (no coordinates/address, or OSM request error).', 'ph-osm-location-checker' ) ),
		);
		$by_type = array(
			'taxonomy' => array( 'match', 'partial', 'broader', 'narrower', 'mismatch', 'no_term', 'no_osm_data', 'unassigned', 'error' ),
			'name'     => array( 'match', 'partial', 'mismatch', 'no_term', 'no_osm_data', 'unassigned', 'error' ),
			'group'    => array( 'match', 'mismatch', 'no_prediction', 'unassigned', 'error' ),
		);
		if ( isset( $by_type[ $type ] ) ) {
			return array_intersect_key( $all, array_flip( $by_type[ $type ] ) );
		}
		return $all;
	}

	/* ---------------------------------------------------------------------
	 * Stats
	 * ------------------------------------------------------------------- */

	public function ajax_stats() {
		$this->check_ajax();
		$target = $this->current_target();
		ob_start();
		$this->render_stats( $target );
		wp_send_json_success( array( 'html' => ob_get_clean(), 'counts' => $this->queue_counts() ) );
	}

	private function pct( $part, $whole ) {
		return $whole ? number_format_i18n( 100 * $part / $whole, 1 ) . '%' : '–';
	}

	private function render_stats( $target ) {
		global $wpdb;

		if ( get_option( self::STALE ) ) {
			$this->recompute_all();
		}

		$targets = self::targets();
		echo '<h2>' . esc_html__( 'Statistics', 'ph-osm-location-checker' ) . ( isset( $targets[ $target ] ) ? ': ' . esc_html( $targets[ $target ]['label'] ) : '' ) . '</h2>';

		if ( ! isset( $targets[ $target ] ) ) {
			echo '<p>' . esc_html__( 'No fields are configured to compare against. See Settings.', 'ph-osm-location-checker' ) . '</p>';
			return;
		}
		$type = $targets[ $target ]['type'];

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT current_value, suggested, suggested_field, matched_field, outcome, details FROM ' . self::compare_table() . ' WHERE target = %s', $target ), ARRAY_A ); // phpcs:ignore

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No results yet. Run a lookup to see statistics.', 'ph-osm-location-checker' ) . '</p>';
			return;
		}

		$labels         = self::outcome_labels( $type );
		$outcomes       = array_fill_keys( array_keys( $labels ), 0 );
		$matched_fields = array();
		$field_stats    = array();
		$mismatch_pairs = array();
		$unknown_values = array();
		$suggest_empty  = array();

		foreach ( $rows as $row ) {
			$outcomes[ $row['outcome'] ] = ( $outcomes[ $row['outcome'] ] ?? 0 ) + 1;

			if ( $row['matched_field'] ) {
				$matched_fields[ $row['matched_field'] ] = ( $matched_fields[ $row['matched_field'] ] ?? 0 ) + 1;
			}

			$detail = json_decode( (string) $row['details'], true );
			$fields = is_array( $detail ) && ! empty( $detail['fields'] ) ? $detail['fields'] : array();

			if ( ! in_array( $row['outcome'], array( 'unassigned', 'error' ), true ) ) {
				foreach ( $fields as $f => $info ) {
					if ( ! isset( $field_stats[ $f ] ) ) {
						$field_stats[ $f ] = array( 'returned' => 0, 'known' => 0, 'correct' => 0 );
					}
					if ( 'group' === $type ) {
						// "returned" = a prediction was possible, "correct" = prediction right.
						if ( ! empty( $info['predicted'] ) ) {
							$field_stats[ $f ]['returned']++;
							if ( ! empty( $info['correct'] ) ) {
								$field_stats[ $f ]['correct']++;
							}
						}
					} else {
						$field_stats[ $f ]['returned']++;
						if ( ! empty( $info['known'] ) ) {
							$field_stats[ $f ]['known']++;
						}
						if ( ! empty( $info['correct'] ) ) {
							$field_stats[ $f ]['correct']++;
						}
					}
				}
			}

			if ( 'mismatch' === $row['outcome'] ) {
				$key = $row['current_value'] . ' → ' . $row['suggested'];
				$mismatch_pairs[ $key ] = ( $mismatch_pairs[ $key ] ?? 0 ) + 1;
			}

			if ( 'group' !== $type && in_array( $row['outcome'], array( 'no_term', 'mismatch', 'partial', 'broader', 'unassigned' ), true ) ) {
				foreach ( self::$primary_fields as $f ) {
					if ( ! empty( $fields[ $f ] ) && empty( $fields[ $f ]['known'] ) ) {
						$key = $fields[ $f ]['value'] . ' (' . $f . ')';
						$unknown_values[ $key ] = ( $unknown_values[ $key ] ?? 0 ) + 1;
					}
				}
			}

			if ( 'unassigned' === $row['outcome'] && $row['suggested'] ) {
				$suggest_empty[ $row['suggested'] ] = ( $suggest_empty[ $row['suggested'] ] ?? 0 ) + 1;
			}
		}

		$total     = count( $rows );
		$evaluable = $total - ( $outcomes['unassigned'] ?? 0 ) - ( $outcomes['error'] ?? 0 );
		$exact     = $outcomes['match'] ?? 0;
		$agrees    = $exact + ( $outcomes['partial'] ?? 0 );
		?>
		<div class="phosm-headline">
			<div><span class="phosm-big"><?php echo esc_html( $this->pct( $exact, $evaluable ) ); ?></span><?php esc_html_e( 'Automatic assignment would match exactly', 'ph-osm-location-checker' ); ?></div>
			<?php if ( 'group' !== $type ) : ?>
				<div><span class="phosm-big"><?php echo esc_html( $this->pct( $agrees, $evaluable ) ); ?></span><?php esc_html_e( 'An OSM area matches what was entered (any field)', 'ph-osm-location-checker' ); ?></div>
			<?php else : ?>
				<div><span class="phosm-big"><?php echo esc_html( $this->pct( $outcomes['no_prediction'], $evaluable ) ); ?></span><?php esc_html_e( 'Could not predict', 'ph-osm-location-checker' ); ?></div>
			<?php endif; ?>
			<?php if ( 'taxonomy' === $type ) : ?>
				<div><span class="phosm-big"><?php echo esc_html( $this->pct( $agrees + $outcomes['broader'] + $outcomes['narrower'], $evaluable ) ); ?></span><?php esc_html_e( 'Same or parent/child Location', 'ph-osm-location-checker' ); ?></div>
			<?php endif; ?>
			<div><span class="phosm-big"><?php echo esc_html( $this->pct( $outcomes['mismatch'], $evaluable ) ); ?></span><?php esc_html_e( 'Would assign the wrong value', 'ph-osm-location-checker' ); ?></div>
		</div>
		<p class="description">
			<?php
			printf(
				/* translators: 1: total checked, 2: evaluable count */
				esc_html__( '%1$s properties looked up; %2$s had a value entered and a successful lookup, and are used for the percentages above.', 'ph-osm-location-checker' ),
				esc_html( number_format_i18n( $total ) ),
				esc_html( number_format_i18n( $evaluable ) )
			);
			if ( 'group' === $type ) {
				echo ' ' . esc_html__( 'This field is not something OSM knows about, so a mapping from OSM area → value is learned from your existing properties. Each property is predicted using only the other properties (leave-one-out), so these figures are an honest estimate of how automation would perform on new properties.', 'ph-osm-location-checker' );
			}
			?>
		</p>

		<div class="phosm-grid">
			<div>
				<h3><?php esc_html_e( 'Outcomes', 'ph-osm-location-checker' ); ?></h3>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Outcome', 'ph-osm-location-checker' ); ?></th><th class="num"><?php esc_html_e( 'Properties', 'ph-osm-location-checker' ); ?></th><th class="num">%</th></tr></thead>
					<tbody>
					<?php foreach ( $labels as $key => $label ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( self::page_url( 'results', array( 'target' => $target, 'outcome' => $key ) ) ); ?>"><span class="phosm-badge phosm-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label[0] ); ?></span></a><br><small><?php echo esc_html( $label[1] ); ?></small></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $outcomes[ $key ] ?? 0 ) ); ?></td>
							<td class="num"><?php echo esc_html( $this->pct( $outcomes[ $key ] ?? 0, $total ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div>
				<?php if ( 'group' === $type ) : ?>
					<h3><?php esc_html_e( 'How well does each OSM field predict this?', 'ph-osm-location-checker' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Coverage = how often a prediction could be made from that field alone. Accuracy = how often that prediction was right. Fields are tried in order of accuracy when assigning.', 'ph-osm-location-checker' ); ?></p>
					<table class="widefat striped">
						<thead><tr>
							<th><?php esc_html_e( 'Predict from', 'ph-osm-location-checker' ); ?></th>
							<th class="num"><?php esc_html_e( 'Coverage', 'ph-osm-location-checker' ); ?></th>
							<th class="num"><?php esc_html_e( 'Correct', 'ph-osm-location-checker' ); ?></th>
							<th class="num"><?php esc_html_e( 'Accuracy', 'ph-osm-location-checker' ); ?></th>
						</tr></thead>
						<tbody>
						<?php
						uasort(
							$field_stats,
							function ( $a, $b ) {
								$aa = $a['returned'] ? $a['correct'] / $a['returned'] : 0;
								$bb = $b['returned'] ? $b['correct'] / $b['returned'] : 0;
								return $bb <=> $aa;
							}
						);
						foreach ( $field_stats as $f => $s ) :
							if ( ! $s['returned'] ) {
								continue;
							}
							?>
							<tr>
								<td><code><?php echo esc_html( $f ); ?></code></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $s['returned'] ) ); ?> <small>(<?php echo esc_html( $this->pct( $s['returned'], $evaluable ) ); ?>)</small></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $s['correct'] ) ); ?></td>
								<td class="num"><strong><?php echo esc_html( $this->pct( $s['correct'], $s['returned'] ) ); ?></strong></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<h3><?php esc_html_e( 'How useful is each OSM field?', 'ph-osm-location-checker' ); ?></h3>
					<p class="description"><?php esc_html_e( 'For properties with a value entered: how often OSM returned each field, how often that OSM value is an existing value, and how often it equals what was entered on the property.', 'ph-osm-location-checker' ); ?></p>
					<table class="widefat striped">
						<thead><tr>
							<th><?php esc_html_e( 'OSM field', 'ph-osm-location-checker' ); ?></th>
							<th class="num"><?php esc_html_e( 'Returned', 'ph-osm-location-checker' ); ?></th>
							<th class="num"><?php esc_html_e( 'Existing value', 'ph-osm-location-checker' ); ?></th>
							<th class="num"><?php esc_html_e( 'Equals entered', 'ph-osm-location-checker' ); ?></th>
							<th class="num"><?php esc_html_e( 'Accuracy when returned', 'ph-osm-location-checker' ); ?></th>
						</tr></thead>
						<tbody>
						<?php
						foreach ( self::$fields as $f ) :
							if ( empty( $field_stats[ $f ]['returned'] ) ) {
								continue;
							}
							$s = $field_stats[ $f ];
							?>
							<tr<?php echo in_array( $f, self::$primary_fields, true ) ? ' class="phosm-primary"' : ''; ?>>
								<td><code><?php echo esc_html( $f ); ?></code></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $s['returned'] ) ); ?> <small>(<?php echo esc_html( $this->pct( $s['returned'], $evaluable ) ); ?>)</small></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $s['known'] ) ); ?></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $s['correct'] ) ); ?></td>
								<td class="num"><strong><?php echo esc_html( $this->pct( $s['correct'], $s['returned'] ) ); ?></strong></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php if ( $matched_fields && 'group' !== $type ) : ?>
					<h3><?php esc_html_e( 'First OSM field that matched what was entered', 'ph-osm-location-checker' ); ?></h3>
					<table class="widefat striped">
						<tbody>
						<?php
						arsort( $matched_fields );
						foreach ( $matched_fields as $f => $n ) :
							?>
							<tr><td><code><?php echo esc_html( $f ); ?></code></td><td class="num"><?php echo esc_html( number_format_i18n( $n ) ); ?></td><td class="num"><?php echo esc_html( $this->pct( $n, $agrees ) ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( 'group' === $type ) : ?>
			<?php $this->render_group_mapping( $target ); ?>
		<?php endif; ?>

		<div class="phosm-grid">
			<?php
			$this->render_top_table( __( 'Most common mismatches (entered → would assign)', 'ph-osm-location-checker' ), $mismatch_pairs );
			if ( 'group' !== $type ) {
				$this->render_top_table( __( 'OSM neighbourhoods/suburbs that are not an existing value', 'ph-osm-location-checker' ), $unknown_values, 'taxonomy' === $type ? __( 'Creating these Locations (or aliasing them) would improve coverage.', 'ph-osm-location-checker' ) : __( 'Likely spelling/naming differences, or areas not used yet.', 'ph-osm-location-checker' ) );
			}
			$this->render_top_table( __( 'What would be assigned to properties with nothing entered', 'ph-osm-location-checker' ), $suggest_empty );
			?>
		</div>
		<?php
	}

	private function render_group_mapping( $target ) {
		$map = get_option( 'phosm_group_map_' . md5( $target ) );
		if ( empty( $map['values'] ) ) {
			return;
		}
		$values = $map['values'];
		uasort(
			$values,
			function ( $a, $b ) {
				return array_sum( $b['counts'] ) <=> array_sum( $a['counts'] );
			}
		);
		?>
		<h3>
			<?php
			printf(
				/* translators: %s: OSM field */
				esc_html__( 'Learned mapping (from %s)', 'ph-osm-location-checker' ),
				'<code>' . esc_html( $map['field'] ) . '</code>'
			);
			?>
		</h3>
		<p class="description"><?php esc_html_e( 'The most accurate OSM field, and which value existing properties in each area have. Low agreement means that area straddles more than one value and automation there will be unreliable.', 'ph-osm-location-checker' ); ?></p>
		<div class="phosm-scroll">
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'OSM area', 'ph-osm-location-checker' ); ?></th>
					<th><?php esc_html_e( 'Would assign', 'ph-osm-location-checker' ); ?></th>
					<th class="num"><?php esc_html_e( 'Properties', 'ph-osm-location-checker' ); ?></th>
					<th class="num"><?php esc_html_e( 'Agreement', 'ph-osm-location-checker' ); ?></th>
					<th><?php esc_html_e( 'Other values seen', 'ph-osm-location-checker' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $values as $v ) :
					$counts = $v['counts'];
					arsort( $counts );
					$top    = (string) key( $counts );
					$n      = array_sum( $counts );
					$share  = $n ? $counts[ $top ] / $n : 0;
					$others = array();
					foreach ( array_slice( $counts, 1, null, true ) as $name => $c ) {
						$others[] = $name . ' (' . $c . ')';
					}
					?>
					<tr class="<?php echo $share < 0.8 ? 'phosm-ambiguous' : ''; ?>">
						<td><?php echo esc_html( $v['display'] ); ?></td>
						<td><?php echo esc_html( $top ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $n ) ); ?></td>
						<td class="num"><?php echo esc_html( $this->pct( $counts[ $top ], $n ) ); ?></td>
						<td><small><?php echo esc_html( implode( ', ', $others ) ); ?></small></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_top_table( $title, $data, $description = '' ) {
		if ( empty( $data ) ) {
			return;
		}
		arsort( $data );
		$data = array_slice( $data, 0, 25, true );
		echo '<div><h3>' . esc_html( $title ) . '</h3>';
		if ( $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '<table class="widefat striped"><tbody>';
		foreach ( $data as $label => $n ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td class="num">' . esc_html( number_format_i18n( $n ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/* ---------------------------------------------------------------------
	 * Results tab
	 * ------------------------------------------------------------------- */

	private function render_tab_results() {
		global $wpdb;

		if ( get_option( self::STALE ) ) {
			$this->recompute_all();
		}

		$targets = self::targets();
		$target  = $this->current_target();
		if ( ! $target ) {
			echo '<p>' . esc_html__( 'No fields are configured to compare against. See Settings.', 'ph-osm-location-checker' ) . '</p>';
			return;
		}
		$type     = $targets[ $target ]['type'];
		$labels   = self::outcome_labels( $type );
		$outcome  = sanitize_key( $_GET['outcome'] ?? '' );
		$search   = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 50;

		$results = self::results_table();
		$compare = self::compare_table();

		$where  = array( 'c.target = %s' );
		$params = array( $target );
		if ( isset( $labels[ $outcome ] ) ) {
			$where[]  = 'c.outcome = %s';
			$params[] = $outcome;
		}
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(p.post_title LIKE %s OR r.osm_neighbourhood LIKE %s OR r.osm_suburb LIKE %s OR c.current_value LIKE %s OR c.suggested LIKE %s OR r.osm_postcode LIKE %s)';
			$params  = array_merge( $params, array_fill( 0, 6, $like ) );
		}
		$where_sql = implode( ' AND ', $where );
		$from      = "{$compare} c INNER JOIN {$results} r ON r.property_id = c.property_id LEFT JOIN {$wpdb->posts} p ON p.ID = c.property_id";

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$from} WHERE {$where_sql}", $params ) ); // phpcs:ignore
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT c.*, r.source, r.lat, r.lng, r.osm_neighbourhood, r.osm_suburb, r.osm_postcode, r.osm_address, r.error, p.post_title FROM {$from} WHERE {$where_sql} ORDER BY r.checked_at DESC, r.id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $paged - 1 ) * $per_page ) ) ) ); // phpcs:ignore
		?>
		<form method="get" class="phosm-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<input type="hidden" name="tab" value="results">
			<select name="target" onchange="this.form.outcome.value='';this.form.submit()">
				<?php foreach ( $targets as $key => $t ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $target, $key ); ?>><?php echo esc_html( $t['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="outcome">
				<option value=""><?php esc_html_e( 'All outcomes', 'ph-osm-location-checker' ); ?></option>
				<?php foreach ( $labels as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $outcome, $key ); ?>><?php echo esc_html( $label[0] ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search property, area or value', 'ph-osm-location-checker' ); ?>">
			<button class="button"><?php esc_html_e( 'Filter', 'ph-osm-location-checker' ); ?></button>
			<span class="phosm-total"><?php echo esc_html( sprintf( _n( '%s result', '%s results', $total, 'ph-osm-location-checker' ), number_format_i18n( $total ) ) ); ?></span>
		</form>

		<table class="widefat striped phosm-results">
			<thead><tr>
				<th><?php esc_html_e( 'Property', 'ph-osm-location-checker' ); ?></th>
				<th><?php esc_html_e( 'OSM neighbourhood', 'ph-osm-location-checker' ); ?></th>
				<th><?php esc_html_e( 'OSM suburb', 'ph-osm-location-checker' ); ?></th>
				<th><?php esc_html_e( 'Other OSM areas', 'ph-osm-location-checker' ); ?></th>
				<th><?php echo esc_html( sprintf( __( 'Entered %s', 'ph-osm-location-checker' ), $targets[ $target ]['label'] ) ); ?></th>
				<th><?php esc_html_e( 'Would assign', 'ph-osm-location-checker' ); ?></th>
				<th><?php esc_html_e( 'Outcome', 'ph-osm-location-checker' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No results.', 'ph-osm-location-checker' ); ?></td></tr>
			<?php endif; ?>
			<?php
			foreach ( $rows as $row ) :
				$address = json_decode( (string) $row->osm_address, true );
				$other   = array();
				if ( is_array( $address ) ) {
					foreach ( self::$fields as $f ) {
						if ( ! in_array( $f, self::$primary_fields, true ) && ! empty( $address[ $f ] ) && is_string( $address[ $f ] ) ) {
							$other[] = $address[ $f ] . ' (' . $f . ')';
						}
					}
				}
				if ( $row->osm_postcode ) {
					$other[] = $row->osm_postcode . ' (postcode)';
				}
				$label = $labels[ $row->outcome ][0] ?? $row->outcome;
				?>
				<tr>
					<td>
						<a href="<?php echo esc_url( get_edit_post_link( $row->property_id ) ?: '#' ); ?>"><?php echo esc_html( $row->post_title ? $row->post_title : '#' . $row->property_id ); ?></a>
						<br><small><?php echo esc_html( $row->source ); ?><?php echo null !== $row->lat ? ' · ' . esc_html( $row->lat . ', ' . $row->lng ) : ''; ?></small>
					</td>
					<td><?php echo esc_html( $row->osm_neighbourhood ); ?></td>
					<td><?php echo esc_html( $row->osm_suburb ); ?></td>
					<td><small><?php echo esc_html( implode( ', ', $other ) ); ?></small></td>
					<td><?php echo esc_html( $row->current_value ); ?></td>
					<td><?php echo esc_html( $row->suggested ); ?><?php echo $row->suggested_field ? '<br><small>' . esc_html( sprintf( __( 'from %s', 'ph-osm-location-checker' ), $row->suggested_field ) ) . '</small>' : ''; ?></td>
					<td>
						<span class="phosm-badge phosm-<?php echo esc_attr( $row->outcome ); ?>"><?php echo esc_html( $label ); ?></span>
						<?php if ( $row->matched_field && 'group' !== $type ) : ?><br><small><?php echo esc_html( sprintf( __( 'matched on %s', 'ph-osm-location-checker' ), $row->matched_field ) ); ?></small><?php endif; ?>
						<?php if ( $row->error ) : ?><br><small class="phosm-error-text"><?php echo esc_html( $row->error ); ?></small><?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
	}

	/* ---------------------------------------------------------------------
	 * Log tab
	 * ------------------------------------------------------------------- */

	private function render_tab_log() {
		global $wpdb;
		$table = self::log_table();
		$level = sanitize_key( $_GET['level'] ?? '' );
		$sql   = "SELECT * FROM {$table}";
		if ( in_array( $level, array( 'info', 'warning', 'error' ), true ) ) {
			$sql .= $wpdb->prepare( ' WHERE level = %s', $level );
		}
		$rows = $wpdb->get_results( $sql . ' ORDER BY id DESC LIMIT 1000' ); // phpcs:ignore
		?>
		<div class="phosm-log-actions">
			<form method="get" class="phosm-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="hidden" name="tab" value="log">
				<select name="level" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'All levels', 'ph-osm-location-checker' ); ?></option>
					<?php foreach ( array( 'info', 'warning', 'error' ) as $l ) : ?>
						<option value="<?php echo esc_attr( $l ); ?>" <?php selected( $level, $l ); ?>><?php echo esc_html( ucfirst( $l ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>
			<?php $this->admin_post_form( 'phosm_clear_log', __( 'Clear log', 'ph-osm-location-checker' ), 'button button-link-delete', __( 'Clear the log?', 'ph-osm-location-checker' ) ); ?>
			<span class="description"><?php esc_html_e( 'Showing the latest 1,000 entries.', 'ph-osm-location-checker' ); ?></span>
		</div>
		<table class="widefat striped phosm-log">
			<thead><tr>
				<th><?php esc_html_e( 'Time', 'ph-osm-location-checker' ); ?></th>
				<th><?php esc_html_e( 'Level', 'ph-osm-location-checker' ); ?></th>
				<th><?php esc_html_e( 'Property', 'ph-osm-location-checker' ); ?></th>
				<th><?php esc_html_e( 'Message', 'ph-osm-location-checker' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'The log is empty.', 'ph-osm-location-checker' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<tr class="phosm-log-<?php echo esc_attr( $row->level ); ?>">
					<td><?php echo esc_html( get_date_from_gmt( $row->logged_at, 'Y-m-d H:i:s' ) ); ?></td>
					<td><?php echo esc_html( $row->level ); ?></td>
					<td><?php echo $row->property_id ? '<a href="' . esc_url( get_edit_post_link( $row->property_id ) ?: '#' ) . '">#' . esc_html( $row->property_id ) . '</a>' : '–'; ?></td>
					<td><?php echo esc_html( $row->message ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Settings tab
	 * ------------------------------------------------------------------- */

	private function render_tab_settings() {
		$s = self::settings();
		$statuses = array(
			'publish' => __( 'Published', 'ph-osm-location-checker' ),
			'private' => __( 'Private', 'ph-osm-location-checker' ),
			'draft'   => __( 'Draft', 'ph-osm-location-checker' ),
			'pending' => __( 'Pending', 'ph-osm-location-checker' ),
			'future'  => __( 'Scheduled', 'ph-osm-location-checker' ),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="phosm_save_settings">
			<?php wp_nonce_field( 'phosm_save_settings' ); ?>
			<h2><?php esc_html_e( 'What to compare against', 'ph-osm-location-checker' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="phosm-meta-targets"><?php esc_html_e( 'Property fields', 'ph-osm-location-checker' ); ?></label></th>
					<td>
						<textarea id="phosm-meta-targets" name="meta_targets" rows="4" class="large-text code"><?php echo esc_textarea( $s['meta_targets'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'One per line: meta_key|type|Label. Type is:', 'ph-osm-location-checker' ); ?><br>
							<code>name</code> – <?php esc_html_e( 'the field holds an area name (e.g. _suburb = "Kings Norton") and is compared directly with OSM neighbourhood/suburb etc.', 'ph-osm-location-checker' ); ?><br>
							<code>group</code> – <?php esc_html_e( 'the field holds your own grouping that OSM does not know (e.g. _region = "Birmingham South"). The plugin learns which group each OSM area / postcode district belongs to from your existing properties and tests that.', 'ph-osm-location-checker' ); ?><br>
							<?php esc_html_e( 'Optional 4th part: the OSM fields allowed to supply the value, comma separated, in priority order, e.g. _suburb|name|Suburb|suburb or …|suburb,neighbourhood. Leave it off to try every OSM field from most specific (neighbourhood) to least (city). For group fields, postcode_district is also available.', 'ph-osm-location-checker' ); ?><br>
							<?php esc_html_e( 'Multi-select and comma-separated values are supported. Changing this needs no new OSM lookups.', 'ph-osm-location-checker' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Location taxonomy', 'ph-osm-location-checker' ); ?></th>
					<td><label><input type="checkbox" name="location_target" value="1" <?php checked( $s['location_target'] ); ?>> <?php esc_html_e( 'Also compare against the Property Hive Location taxonomy', 'ph-osm-location-checker' ); ?></label></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'OSM lookup', 'ph-osm-location-checker' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="phosm-endpoint"><?php esc_html_e( 'Nominatim URL', 'ph-osm-location-checker' ); ?></label></th>
					<td><input type="url" id="phosm-endpoint" name="endpoint" class="regular-text" value="<?php echo esc_attr( $s['endpoint'] ); ?>">
						<p class="description"><?php esc_html_e( 'Default is the public OSM service. A self-hosted Nominatim instance can be used instead and allows a shorter delay.', 'ph-osm-location-checker' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="phosm-email"><?php esc_html_e( 'Contact email', 'ph-osm-location-checker' ); ?></label></th>
					<td><input type="email" id="phosm-email" name="email" class="regular-text" value="<?php echo esc_attr( $s['email'] ); ?>">
						<p class="description"><?php esc_html_e( 'Optional. Sent with requests so the OSM operators can contact you about bulk usage, as the Nominatim policy recommends.', 'ph-osm-location-checker' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="phosm-delay"><?php esc_html_e( 'Delay between requests', 'ph-osm-location-checker' ); ?></label></th>
					<td><input type="number" id="phosm-delay" name="delay" step="0.1" min="0.1" class="small-text" value="<?php echo esc_attr( $s['delay'] ); ?>"> <?php esc_html_e( 'seconds', 'ph-osm-location-checker' ); ?>
						<p class="description"><?php esc_html_e( 'Enforced server-side across all browser tabs. Minimum 1 second for the public OSM service.', 'ph-osm-location-checker' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="phosm-language"><?php esc_html_e( 'Result language', 'ph-osm-location-checker' ); ?></label></th>
					<td><input type="text" id="phosm-language" name="language" class="small-text" value="<?php echo esc_attr( $s['language'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Missing coordinates', 'ph-osm-location-checker' ); ?></th>
					<td><label><input type="checkbox" name="address_fallback" value="1" <?php checked( $s['address_fallback'] ); ?>> <?php esc_html_e( 'Search by address/postcode when a property has no latitude/longitude', 'ph-osm-location-checker' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Properties to check', 'ph-osm-location-checker' ); ?></th>
					<td>
						<?php foreach ( $statuses as $key => $label ) : ?>
							<label class="phosm-check"><input type="checkbox" name="statuses[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $s['statuses'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
						<br><label><input type="checkbox" name="on_market_only" value="1" <?php checked( $s['on_market_only'] ); ?>> <?php esc_html_e( 'On market only', 'ph-osm-location-checker' ); ?></label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Queue
	 * ------------------------------------------------------------------- */

	private function scope_sql() {
		global $wpdb;
		$s            = self::settings();
		$placeholders = implode( ',', array_fill( 0, count( $s['statuses'] ), '%s' ) );
		$sql          = $wpdb->prepare( "p.post_type = 'property' AND p.post_status IN ({$placeholders})", $s['statuses'] ); // phpcs:ignore
		if ( $s['on_market_only'] ) {
			$sql .= " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} om WHERE om.post_id = p.ID AND om.meta_key = '_on_market' AND om.meta_value = 'yes')";
		}
		return $sql;
	}

	private function queue_counts() {
		global $wpdb;
		$scope   = $this->scope_sql();
		$table   = self::results_table();
		$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$scope}" ); // phpcs:ignore
		$checked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$table} r ON r.property_id = p.ID WHERE {$scope}" ); // phpcs:ignore
		return array(
			'total'     => $total,
			'checked'   => $checked,
			'remaining' => max( 0, $total - $checked ),
		);
	}

	private function next_property_id() {
		global $wpdb;
		$scope = $this->scope_sql();
		$table = self::results_table();
		return (int) $wpdb->get_var( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$table} r ON r.property_id = p.ID WHERE {$scope} AND r.id IS NULL ORDER BY p.ID ASC LIMIT 1" ); // phpcs:ignore
	}

	/* ---------------------------------------------------------------------
	 * AJAX: test a single lookup (nothing saved)
	 * ------------------------------------------------------------------- */

	public function ajax_test() {
		$this->check_ajax();

		$property_id = absint( $_POST['property_id'] ?? 0 );
		$out         = array();
		if ( $property_id ) {
			if ( 'property' !== get_post_type( $property_id ) ) {
				wp_send_json_error( array( 'message' => 'Post #' . $property_id . ' is not a property.' ) );
			}
			$lat_raw = get_post_meta( $property_id, '_latitude', true );
			$lng_raw = get_post_meta( $property_id, '_longitude', true );
			$out['property'] = array(
				'title'      => get_the_title( $property_id ),
				'_latitude'  => $lat_raw,
				'_longitude' => $lng_raw,
				'address'    => $this->address_query( $property_id ),
			);
			foreach ( self::targets() as $key => $t ) {
				if ( 'taxonomy' !== $t['type'] ) {
					$out['property'][ $key ] = implode( ', ', self::meta_values( $property_id, $key ) );
				}
			}
		} else {
			$lat_raw = wp_unslash( $_POST['lat'] ?? '' );
			$lng_raw = wp_unslash( $_POST['lng'] ?? '' );
		}

		$lat = self::parse_coord( $lat_raw );
		$lng = self::parse_coord( $lng_raw );
		$out['parsed_coordinates'] = array( 'lat' => $lat, 'lng' => $lng );

		if ( null === $lat || null === $lng || ( 0.0 === $lat && 0.0 === $lng ) || abs( $lat ) > 90 || abs( $lng ) > 180 ) {
			$out['error'] = 'No valid latitude/longitude' . ( $property_id ? ' on this property – a run would use the address search fallback (if enabled).' : '.' );
			wp_send_json_success( $out );
		}

		$response              = $this->nominatim( 'reverse', array( 'lat' => $lat, 'lon' => $lng, 'zoom' => 18 ) );
		$out['request']        = $this->last_request;
		if ( isset( $out['request']['body'] ) ) {
			$decoded = json_decode( $out['request']['body'], true );
			$out['request']['body'] = null !== $decoded ? $decoded : mb_substr( $out['request']['body'], 0, 2000 );
		}

		if ( is_wp_error( $response ) ) {
			$out['error'] = $response->get_error_message();
			wp_send_json_success( $out );
		}
		if ( isset( $response['error'] ) ) {
			$out['error'] = 'OSM: ' . ( is_string( $response['error'] ) ? $response['error'] : wp_json_encode( $response['error'] ) );
		}

		$address               = isset( $response['address'] ) && is_array( $response['address'] ) ? $response['address'] : array();
		$out['osm_areas_used'] = $this->osm_values( $address );
		if ( ! empty( $address['postcode'] ) ) {
			$out['osm_areas_used'][ self::POSTCODE_FEATURE ] = self::postcode_district( $address['postcode'] );
		}
		if ( ! $out['osm_areas_used'] ) {
			$out['note'] = 'OSM returned an address but none of the area fields this plugin uses (' . implode( ', ', self::$fields ) . ').';
		}

		if ( $property_id ) {
			foreach ( self::targets() as $key => $t ) {
				if ( 'taxonomy' === $t['type'] ) {
					$c = $this->compare_taxonomy( $property_id, $address, $t['fields'] );
				} elseif ( 'name' === $t['type'] ) {
					$c = $this->compare_name( $property_id, $key, $address, $t['fields'] );
				} else {
					continue; // group fields need the whole data set; see the stats page.
				}
				unset( $c['details'] );
				$out['comparison'][ $t['label'] ] = $c;
			}
		}

		wp_send_json_success( $out );
	}

	/** Accept "52.48", " 52,48 " (decimal comma) etc. Returns float or null. */
	public static function parse_coord( $value ) {
		$value = trim( str_replace( ',', '.', (string) $value ) );
		return is_numeric( $value ) ? (float) $value : null;
	}

	/* ---------------------------------------------------------------------
	 * AJAX step: process exactly one property
	 * ------------------------------------------------------------------- */

	private function acquire_lock() {
		if ( add_option( self::LOCK_OPTION, time(), '', 'no' ) ) {
			return true;
		}
		$held_since = (int) get_option( self::LOCK_OPTION );
		if ( $held_since && $held_since < time() - 90 ) {
			delete_option( self::LOCK_OPTION );
			return add_option( self::LOCK_OPTION, time(), '', 'no' );
		}
		return false;
	}

	private function release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	public function ajax_step() {
		$this->check_ajax();

		if ( ! $this->acquire_lock() ) {
			wp_send_json_success( array( 'busy' => true, 'message' => __( 'Another lookup is in progress (another tab?). Waiting…', 'ph-osm-location-checker' ) ) );
		}

		try {
			$property_id = $this->next_property_id();
			if ( ! $property_id ) {
				$this->release_lock();
				wp_send_json_success( array( 'done' => true, 'counts' => $this->queue_counts() ) );
			}

			$result = $this->process_property( $property_id );
		} catch ( Exception $e ) {
			$this->release_lock();
			$this->log( 'error', $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		$this->release_lock();

		wp_send_json_success(
			array(
				'done'        => false,
				'halt'        => ! empty( $result['halt'] ),
				'property_id' => $property_id,
				'title'       => get_the_title( $property_id ),
				'outcome'     => $result['outcome'],
				'message'     => $result['message'],
				'counts'      => $this->queue_counts(),
			)
		);
	}

	/**
	 * Look a property up on OSM, store the response, and compare.
	 *
	 * @return array{outcome:string,message:string,halt?:bool}
	 */
	public function process_property( $property_id ) {
		$settings = self::settings();
		$lat      = self::parse_coord( get_post_meta( $property_id, '_latitude', true ) );
		$lng      = self::parse_coord( get_post_meta( $property_id, '_longitude', true ) );
		$has_ll   = null !== $lat && null !== $lng && ( 0.0 !== $lat || 0.0 !== $lng ) && abs( $lat ) <= 90 && abs( $lng ) <= 180;

		$row = array(
			'property_id' => $property_id,
			'status'      => 'ok',
			'source'      => '',
			'lat'         => $has_ll ? $lat : null,
			'lng'         => $has_ll ? $lng : null,
		);

		if ( $has_ll ) {
			$row['source'] = 'coordinates';
			$response      = $this->nominatim(
				'reverse',
				array(
					'lat'  => $lat,
					'lon'  => $lng,
					'zoom' => 18,
				)
			);
		} elseif ( $settings['address_fallback'] && ( $query = $this->address_query( $property_id ) ) ) {
			$row['source'] = 'address';
			$params        = array( 'q' => $query, 'limit' => 1 );
			$country       = get_post_meta( $property_id, '_address_country', true );
			if ( $country && 2 === strlen( $country ) ) {
				$params['countrycodes'] = strtolower( $country );
			}
			$response = $this->nominatim( 'search', $params );
			if ( ! is_wp_error( $response ) ) {
				$response = is_array( $response ) && isset( $response[0] ) ? $response[0] : array();
				if ( empty( $response ) ) {
					$response = new WP_Error( 'not_found', sprintf( 'Address not found: %s', $query ) );
				} else {
					$row['lat'] = isset( $response['lat'] ) ? (float) $response['lat'] : null;
					$row['lng'] = isset( $response['lon'] ) ? (float) $response['lon'] : null;
				}
			}
		} else {
			$response = new WP_Error( 'no_location', 'Property has no latitude/longitude' . ( $settings['address_fallback'] ? ' or address' : '' ) );
		}

		if ( is_wp_error( $response ) ) {
			$halt = in_array( $response->get_error_code(), array( 'rate_limited', 'blocked' ), true );
			if ( $halt ) {
				// Don't store a result, so it is retried on the next run.
				$this->log( 'error', 'OSM refused the request, stopping run: ' . $response->get_error_message(), $property_id );
			} else {
				$row['status'] = 'error';
				$row['error']  = $response->get_error_message();
				$this->save_result( $row );
				$this->compare_property( $property_id );
				$this->log( 'error', $response->get_error_message(), $property_id );
			}
			return array( 'outcome' => 'error', 'message' => $response->get_error_message(), 'halt' => $halt );
		}

		$address = ( ! isset( $response['error'] ) && isset( $response['address'] ) && is_array( $response['address'] ) ) ? $response['address'] : array();
		if ( isset( $response['error'] ) ) {
			$this->log( 'warning', 'OSM returned: ' . ( is_string( $response['error'] ) ? $response['error'] : wp_json_encode( $response['error'] ) ), $property_id );
		}

		$row['osm_display_name']  = $response['display_name'] ?? '';
		$row['osm_address']       = wp_json_encode( $address );
		$row['osm_neighbourhood'] = isset( $address['neighbourhood'] ) ? (string) $address['neighbourhood'] : '';
		$row['osm_suburb']        = isset( $address['suburb'] ) ? (string) $address['suburb'] : '';
		$row['osm_postcode']      = isset( $address['postcode'] ) ? substr( (string) $address['postcode'], 0, 20 ) : '';
		$this->save_result( $row );

		$compared = $this->compare_property( $property_id );
		update_option( self::STALE, 1, false ); // group targets depend on all properties

		$parts   = array( sprintf( 'OSM (%s): neighbourhood "%s", suburb "%s"', $row['source'], $row['osm_neighbourhood'], $row['osm_suburb'] ) );
		$targets = self::targets();
		$worst   = 'match';
		foreach ( $compared as $key => $c ) {
			$parts[] = sprintf(
				'%s: "%s" → %s',
				$targets[ $key ]['label'],
				$c['current_value'],
				$c['outcome'] . ( $c['suggested'] ? ' ("' . $c['suggested'] . '")' : '' )
			);
			if ( 'match' !== $c['outcome'] ) {
				$worst = $c['outcome'];
			}
		}
		$message = implode( ' | ', $parts );
		$this->log( 'mismatch' === $worst || 'no_osm_data' === $worst ? 'warning' : 'info', $message, $property_id );

		return array( 'outcome' => $worst, 'message' => $message );
	}

	private function address_query( $property_id ) {
		$parts = array();
		foreach ( array( '_address_street', '_address_two', '_address_three', '_address_four', '_address_postcode' ) as $key ) {
			$value = trim( (string) get_post_meta( $property_id, $key, true ) );
			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * Throttled request to Nominatim.
	 *
	 * @return array|WP_Error Decoded JSON.
	 */
	private function nominatim( $endpoint, $params ) {
		$settings = self::settings();

		$params = array_merge(
			array(
				'format'          => 'jsonv2',
				'addressdetails'  => 1,
				'accept-language' => $settings['language'],
			),
			$params
		);
		if ( $settings['email'] ) {
			$params['email'] = $settings['email'];
		}

		$url = add_query_arg( array_map( 'rawurlencode', $params ), $settings['endpoint'] . '/' . $endpoint );

		$this->throttle( $settings['delay'] );

		$this->last_request = array( 'url' => $url );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => 'PropertyHive-OSM-Location-Checker/' . self::VERSION . ' (' . home_url() . ')',
				'headers'    => array( 'Referer' => home_url() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->last_request['error'] = $response->get_error_message();
			return new WP_Error( 'request_failed', 'Request to OSM failed: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$this->last_request['http_code'] = $code;
		$this->last_request['body']      = $body;
		$snippet = trim( mb_substr( wp_strip_all_tags( $body ), 0, 200 ) );

		if ( 429 === $code ) {
			return new WP_Error( 'rate_limited', 'HTTP 429 Too Many Requests from OSM. ' . $snippet );
		}
		if ( 403 === $code || 418 === $code ) {
			return new WP_Error( 'blocked', 'HTTP ' . $code . ' from OSM – access blocked. Check the usage policy and contact email. ' . $snippet );
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'http_' . $code, 'HTTP ' . $code . ' from OSM. ' . $snippet );
		}

		$data = json_decode( $body, true );
		if ( null === $data ) {
			return new WP_Error( 'bad_json', 'Invalid JSON returned by OSM: ' . $snippet );
		}
		return $data;
	}

	/**
	 * Make sure we never send requests faster than $delay seconds, regardless of how
	 * many browser tabs/users are running the process.
	 */
	private function throttle( $delay ) {
		global $wpdb;
		$last = (float) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'phosm_last_request' ) );
		$wait = $last + $delay - microtime( true );
		if ( $wait > 0 ) {
			usleep( (int) ceil( min( $wait, 10 ) * 1000000 ) );
		}
		update_option( 'phosm_last_request', (string) microtime( true ), false );
	}

	/* ---------------------------------------------------------------------
	 * Comparison helpers
	 * ------------------------------------------------------------------- */

	public static function normalise( $name ) {
		$name = html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' );
		$name = remove_accents( $name );
		$name = strtolower( $name );
		$name = str_replace( array( '&', '+' ), ' and ', $name );
		$name = preg_replace( "/['’`]/u", '', $name );
		$name = preg_replace( '/[^a-z0-9]+/', ' ', $name );
		$name = preg_replace( '/\bst\b/', 'saint', $name );
		$name = preg_replace( '/^the /', '', trim( $name ) );
		return trim( preg_replace( '/\s+/', ' ', $name ) );
	}

	/** UK-style outward code ("B30 2AB" → "B30"); otherwise the first token. */
	public static function postcode_district( $postcode ) {
		$pc = strtoupper( preg_replace( '/\s+/', '', (string) $postcode ) );
		if ( '' === $pc ) {
			return '';
		}
		if ( preg_match( '/^([A-Z]{1,2}[0-9][0-9A-Z]?)([0-9][A-Z]{2})?$/', $pc, $m ) ) {
			return $m[1];
		}
		$parts = preg_split( '/\s+/', trim( (string) $postcode ) );
		return strtoupper( $parts[0] );
	}

	/** OSM area values for a property, most specific first: field => display value. */
	private function osm_values( $address ) {
		$osm = array();
		foreach ( self::$fields as $field ) {
			if ( ! empty( $address[ $field ] ) && is_string( $address[ $field ] ) ) {
				$osm[ $field ] = trim( $address[ $field ] );
			}
		}
		return $osm;
	}

	/**
	 * Split a stored meta value into individual values: multi-selects/serialized arrays, and
	 * text fields holding several areas, e.g. "Birmingham City Centre, Jewellery Quarter".
	 */
	public static function split_values( $raw ) {
		$values = array();
		foreach ( (array) maybe_unserialize( $raw ) as $v ) {
			if ( ! is_scalar( $v ) ) {
				continue;
			}
			$separators = (string) apply_filters( 'phosm_value_separators', ',;|' );
			$parts      = '' === $separators ? array( (string) $v ) : preg_split( '/[' . preg_quote( $separators, '/' ) . ']+/', (string) $v );
			foreach ( $parts as $part ) {
				$part = trim( $part );
				if ( '' !== $part ) {
					$values[] = $part;
				}
			}
		}
		return $values;
	}

	/** All values of a meta key on a property. */
	public static function meta_values( $property_id, $key ) {
		$values = array();
		foreach ( (array) get_post_meta( $property_id, $key, false ) as $raw ) {
			$values = array_merge( $values, self::split_values( $raw ) );
		}
		return array_values( array_unique( $values ) );
	}

	private function load_terms() {
		if ( null !== $this->term_index ) {
			return;
		}
		$this->term_index = array();
		$terms            = get_terms( array( 'taxonomy' => 'location', 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			return;
		}
		foreach ( $terms as $term ) {
			$this->terms[ $term->term_id ]   = $term;
			$this->term_index[ self::normalise( $term->name ) ][] = $term->term_id;
		}
		/**
		 * Add aliases: array( 'osm name' => term_id ) so OSM names that differ from your Location names still match.
		 */
		foreach ( (array) apply_filters( 'phosm_location_aliases', array() ) as $alias => $term_id ) {
			$this->term_index[ self::normalise( $alias ) ][] = (int) $term_id;
		}
	}

	/** Every distinct value used for a "name" meta field across all properties. */
	private function load_vocab( $key ) {
		global $wpdb;
		if ( isset( $this->vocab[ $key ] ) ) {
			return $this->vocab[ $key ];
		}
		$vocab = array();
		$raws  = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = 'property'", $key ) );
		foreach ( $raws as $raw ) {
			foreach ( self::split_values( $raw ) as $v ) {
				if ( '' !== ( $n = self::normalise( $v ) ) && ! isset( $vocab[ $n ] ) ) {
					$vocab[ $n ] = $v;
				}
			}
		}
		/** Aliases: array( 'osm name' => 'your value' ). */
		foreach ( (array) apply_filters( 'phosm_meta_aliases', array(), $key ) as $alias => $value ) {
			$vocab[ self::normalise( $alias ) ] = $value;
		}
		$this->vocab[ $key ] = $vocab;
		return $vocab;
	}

	private function term_ancestors( $term_id ) {
		$ancestors = array();
		$guard     = 0;
		while ( isset( $this->terms[ $term_id ] ) && $this->terms[ $term_id ]->parent && $guard++ < 20 ) {
			$term_id     = (int) $this->terms[ $term_id ]->parent;
			$ancestors[] = $term_id;
		}
		return $ancestors;
	}

	private function term_path( $term_id ) {
		$names = array();
		foreach ( array_reverse( array_merge( array( $term_id ), $this->term_ancestors( $term_id ) ) ) as $id ) {
			if ( isset( $this->terms[ $id ] ) ) {
				$names[] = $this->terms[ $id ]->name;
			}
		}
		return implode( ' › ', $names );
	}

	/**
	 * Find the Location term for an OSM value. Where several Locations share a name
	 * (e.g. "Newtown" under two towns), prefer the one whose parent appears elsewhere in the OSM address.
	 */
	private function find_term( $value, $all_values ) {
		$key = self::normalise( $value );
		if ( '' === $key || empty( $this->term_index[ $key ] ) ) {
			return 0;
		}
		$candidates = array_values( array_unique( $this->term_index[ $key ] ) );
		if ( 1 === count( $candidates ) ) {
			return $candidates[0];
		}
		$others = array_map( array( __CLASS__, 'normalise' ), $all_values );
		foreach ( $candidates as $id ) {
			foreach ( $this->term_ancestors( $id ) as $ancestor ) {
				if ( isset( $this->terms[ $ancestor ] ) && in_array( self::normalise( $this->terms[ $ancestor ]->name ), $others, true ) ) {
					return $id;
				}
			}
		}
		return $candidates[0];
	}

	/* ---------------------------------------------------------------------
	 * Comparison: taxonomy
	 * ------------------------------------------------------------------- */

	public function compare_taxonomy( $property_id, $address, $use_fields = array() ) {
		$this->load_terms();
		$osm = $this->osm_values( $address );

		$assigned = wp_get_object_terms( $property_id, 'location', array( 'fields' => 'ids' ) );
		$assigned = is_wp_error( $assigned ) ? array() : array_map( 'intval', $assigned );

		$assigned_ancestors = array();
		foreach ( $assigned as $id ) {
			$assigned_ancestors = array_merge( $assigned_ancestors, $this->term_ancestors( $id ) );
		}

		$fields          = array();
		$suggested_id    = 0;
		$suggested_field = '';
		$matched_field   = '';
		foreach ( $osm as $field => $value ) {
			$term_id = $this->find_term( $value, $osm );
			$correct = $term_id && in_array( $term_id, $assigned, true );
			$fields[ $field ] = array( 'value' => $value, 'known' => (bool) $term_id, 'correct' => $correct );
			if ( $term_id && ! $suggested_id && ( ! $use_fields || in_array( $field, $use_fields, true ) ) ) {
				$suggested_id    = $term_id;
				$suggested_field = $field;
			}
			if ( $correct && ! $matched_field ) {
				$matched_field = $field;
			}
		}

		if ( empty( $assigned ) ) {
			$outcome = 'unassigned';
		} elseif ( $suggested_id && in_array( $suggested_id, $assigned, true ) ) {
			$outcome = 'match';
		} elseif ( $matched_field ) {
			$outcome = 'partial';
		} elseif ( $suggested_id && in_array( $suggested_id, $assigned_ancestors, true ) ) {
			$outcome = 'broader';
		} elseif ( $suggested_id && array_intersect( $assigned, $this->term_ancestors( $suggested_id ) ) ) {
			$outcome = 'narrower';
		} elseif ( $suggested_id ) {
			$outcome = 'mismatch';
		} elseif ( array_intersect( self::$primary_fields, array_keys( $osm ) ) ) {
			$outcome = 'no_term';
		} else {
			$outcome = 'no_osm_data';
		}

		return array(
			'current_value'   => implode( ', ', array_map( array( $this, 'term_path' ), $assigned ) ),
			'suggested'       => $suggested_id ? $this->term_path( $suggested_id ) : '',
			'suggested_field' => $suggested_field,
			'matched_field'   => $matched_field,
			'outcome'         => $outcome,
			'details'         => wp_json_encode( array( 'fields' => $fields ) ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Comparison: free-text name field (e.g. _suburb)
	 * ------------------------------------------------------------------- */

	public function compare_name( $property_id, $key, $address, $use_fields = array() ) {
		$vocab   = $this->load_vocab( $key );
		$osm     = $this->osm_values( $address );
		$current = self::meta_values( $property_id, $key );
		$cur_n   = array_map( array( __CLASS__, 'normalise' ), $current );

		$fields          = array();
		$suggested       = '';
		$suggested_field = '';
		$matched_field   = '';
		foreach ( $osm as $field => $value ) {
			$n       = self::normalise( $value );
			$known   = isset( $vocab[ $n ] );
			$correct = in_array( $n, $cur_n, true );
			$fields[ $field ] = array( 'value' => $value, 'known' => $known, 'correct' => $correct );
			if ( $correct && ! $matched_field ) {
				$matched_field = $field;
			}
		}

		// Pick the value automation would write: the first allowed OSM field (in the configured order,
		// or most specific first) whose value is already in use on another property.
		$order = $use_fields ? $use_fields : array_keys( $osm );
		foreach ( $order as $field ) {
			if ( isset( $fields[ $field ] ) && $fields[ $field ]['known'] ) {
				$suggested       = $vocab[ self::normalise( $osm[ $field ] ) ];
				$suggested_field = $field;
				break;
			}
		}

		// Nothing allowed is an existing value: automation would write the raw OSM value.
		if ( '' === $suggested ) {
			foreach ( ( $use_fields ? $use_fields : self::$primary_fields ) as $f ) {
				if ( isset( $osm[ $f ] ) ) {
					$suggested       = $osm[ $f ];
					$suggested_field = $f . ' (new)';
					break;
				}
			}
		}

		$sugg_n = self::normalise( $suggested );
		if ( empty( $current ) ) {
			$outcome = 'unassigned';
		} elseif ( '' !== $sugg_n && in_array( $sugg_n, $cur_n, true ) ) {
			$outcome = 'match';
		} elseif ( $matched_field ) {
			$outcome = 'partial';
		} elseif ( '' !== $suggested && false === strpos( $suggested_field, '(new)' ) ) {
			$outcome = 'mismatch';
		} elseif ( '' !== $suggested ) {
			$outcome = 'no_term';
		} else {
			$outcome = 'no_osm_data';
		}

		return array(
			'current_value'   => implode( ', ', $current ),
			'suggested'       => $suggested,
			'suggested_field' => $suggested_field,
			'matched_field'   => $matched_field,
			'outcome'         => $outcome,
			'details'         => wp_json_encode( array( 'fields' => $fields ) ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Comparison: group field (e.g. _region) – learned mapping with leave-one-out
	 * ------------------------------------------------------------------- */

	/**
	 * @param string $key   Meta key.
	 * @param array  $items property_id => array( 'features' => array( field => display ), 'current' => array( values ) )
	 * @return array property_id => compare row
	 */
	public function compare_group( $key, $items, $use_fields = array() ) {
		// counts[feature][normalised value][group display] = n.
		$counts   = array();
		$displays = array();
		foreach ( $items as $item ) {
			foreach ( $item['features'] as $f => $value ) {
				$n = self::normalise( $value );
				if ( '' === $n ) {
					continue;
				}
				$displays[ $f ][ $n ] = $value;
				foreach ( $item['current'] as $g ) {
					$counts[ $f ][ $n ][ $g ] = ( $counts[ $f ][ $n ][ $g ] ?? 0 ) + 1;
				}
			}
		}

		// Predict for one item from one feature, excluding the item's own contribution.
		$predict = function ( $item, $f ) use ( &$counts ) {
			if ( ! isset( $item['features'][ $f ] ) ) {
				return '';
			}
			$n = self::normalise( $item['features'][ $f ] );
			if ( empty( $counts[ $f ][ $n ] ) ) {
				return '';
			}
			$c = $counts[ $f ][ $n ];
			foreach ( $item['current'] as $g ) {
				$c[ $g ]--;
			}
			$c = array_filter( $c );
			if ( ! $c ) {
				return '';
			}
			arsort( $c );
			return (string) key( $c );
		};

		// Leave-one-out accuracy for each feature, to decide the order in which features are tried.
		$feature_names = array_merge( self::$fields, array( self::POSTCODE_FEATURE ) );
		$preds         = array();
		$acc           = array();
		$assigned_n    = 0;
		foreach ( $items as $pid => $item ) {
			if ( empty( $item['current'] ) ) {
				continue;
			}
			$assigned_n++;
			foreach ( $feature_names as $f ) {
				$p = $predict( $item, $f );
				$preds[ $pid ][ $f ] = $p;
				if ( '' !== $p ) {
					$acc[ $f ]['n']       = ( $acc[ $f ]['n'] ?? 0 ) + 1;
					$acc[ $f ]['correct'] = ( $acc[ $f ]['correct'] ?? 0 ) + ( in_array( $p, $item['current'], true ) ? 1 : 0 );
				}
			}
		}

		$min_support = max( 3, (int) floor( $assigned_n * 0.02 ) );
		$order       = array();
		foreach ( $acc as $f => $a ) {
			if ( $a['n'] >= $min_support ) {
				$order[ $f ] = $a['correct'] / $a['n'] + $a['n'] / 1e9; // tie-break on coverage
			}
		}
		arsort( $order );
		$order = array_keys( $order );
		if ( $use_fields ) {
			$order = array_values( array_intersect( $use_fields, $order ) ); // fixed order, as configured
		}

		// Remember the learned mapping for the best feature, to show on the stats page.
		if ( $order ) {
			$best   = $order[0];
			$values = array();
			foreach ( $counts[ $best ] as $n => $c ) {
				$values[] = array( 'display' => $displays[ $best ][ $n ], 'counts' => $c );
			}
			update_option( 'phosm_group_map_' . md5( $key ), array( 'field' => $best, 'values' => $values ), false );
		} else {
			delete_option( 'phosm_group_map_' . md5( $key ) );
		}

		$out = array();
		foreach ( $items as $pid => $item ) {
			$fields          = array();
			$suggested       = '';
			$suggested_field = '';
			$matched_field   = '';
			foreach ( $order as $f ) {
				// Properties with nothing entered contribute nothing, so a normal prediction is already "leave-one-out".
				$p = $preds[ $pid ][ $f ] ?? $predict( $item, $f );
				if ( '' === $p ) {
					continue;
				}
				$correct          = in_array( $p, $item['current'], true );
				$fields[ $f ]     = array( 'value' => $item['features'][ $f ], 'predicted' => $p, 'correct' => $correct );
				if ( '' === $suggested ) {
					$suggested       = $p;
					$suggested_field = $f;
				}
				if ( $correct && ! $matched_field ) {
					$matched_field = $f;
				}
			}

			if ( empty( $item['current'] ) ) {
				$outcome = 'unassigned';
			} elseif ( '' === $suggested ) {
				$outcome = 'no_prediction';
			} elseif ( in_array( $suggested, $item['current'], true ) ) {
				$outcome = 'match';
			} else {
				$outcome = 'mismatch';
			}

			$out[ $pid ] = array(
				'current_value'   => implode( ', ', $item['current'] ),
				'suggested'       => $suggested,
				'suggested_field' => $suggested_field,
				'matched_field'   => $matched_field,
				'outcome'         => $outcome,
				'details'         => wp_json_encode( array( 'fields' => $fields ) ),
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Persisting comparisons
	 * ------------------------------------------------------------------- */

	private function get_result( $property_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::results_table() . ' WHERE property_id = %d', $property_id ) ); // phpcs:ignore
	}

	private function error_compare_row( $current ) {
		return array(
			'current_value'   => $current,
			'suggested'       => '',
			'suggested_field' => '',
			'matched_field'   => '',
			'outcome'         => 'error',
			'details'         => '',
		);
	}

	/**
	 * Compare one property against all non-group targets (group targets need every property, see recompute_all).
	 *
	 * @return array target => compare row
	 */
	private function compare_property( $property_id ) {
		$result  = $this->get_result( $property_id );
		$address = $result ? json_decode( (string) $result->osm_address, true ) : array();
		$address = is_array( $address ) ? $address : array();
		$is_err  = ! $result || 'error' === $result->status;
		$out     = array();

		foreach ( self::targets() as $key => $t ) {
			if ( 'group' === $t['type'] ) {
				if ( $is_err ) {
					$this->save_compare( $property_id, $key, $this->error_compare_row( implode( ', ', self::meta_values( $property_id, $key ) ) ) );
				}
				continue;
			}
			if ( $is_err ) {
				$row = $this->error_compare_row( 'taxonomy' === $t['type'] ? '' : implode( ', ', self::meta_values( $property_id, $key ) ) );
			} elseif ( 'taxonomy' === $t['type'] ) {
				$row = $this->compare_taxonomy( $property_id, $address, $t['fields'] );
			} else {
				$row = $this->compare_name( $property_id, $key, $address, $t['fields'] );
			}
			$this->save_compare( $property_id, $key, $row );
			$out[ $key ] = $row;
		}
		return $out;
	}

	/**
	 * Recompute every comparison from the cached OSM data. No OSM requests.
	 */
	public function recompute_all() {
		global $wpdb;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}

		$compare = self::compare_table();
		$results = self::results_table();
		$targets = self::targets();

		// Drop comparisons for removed targets / properties no longer looked up.
		if ( $targets ) {
			$in = implode( ',', array_fill( 0, count( $targets ), '%s' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$compare} WHERE target NOT IN ({$in})", array_keys( $targets ) ) ); // phpcs:ignore
		} else {
			$wpdb->query( "DELETE FROM {$compare}" ); // phpcs:ignore
		}
		$wpdb->query( "DELETE c FROM {$compare} c LEFT JOIN {$results} r ON r.property_id = c.property_id WHERE r.id IS NULL" ); // phpcs:ignore

		$rows = $wpdb->get_results( "SELECT property_id, status, osm_address, osm_postcode FROM {$results}" ); // phpcs:ignore
		$ids  = array_map( 'intval', wp_list_pluck( $rows, 'property_id' ) );
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			update_meta_cache( 'post', $chunk );
			update_object_term_cache( $chunk, 'property' );
		}
		$this->vocab      = array();
		$targets          = self::targets();
		$this->term_index = null;

		$group_items = array();
		foreach ( $rows as $r ) {
			$pid = (int) $r->property_id;
			$this->compare_property( $pid );

			if ( 'ok' !== $r->status ) {
				continue;
			}
			$address  = json_decode( (string) $r->osm_address, true );
			$features = $this->osm_values( is_array( $address ) ? $address : array() );
			$postcode = $r->osm_postcode ? $r->osm_postcode : get_post_meta( $pid, '_address_postcode', true );
			if ( '' !== ( $district = self::postcode_district( $postcode ) ) ) {
				$features[ self::POSTCODE_FEATURE ] = $district;
			}
			foreach ( $targets as $key => $t ) {
				if ( 'group' === $t['type'] ) {
					$group_items[ $key ][ $pid ] = array( 'features' => $features, 'current' => self::meta_values( $pid, $key ) );
				}
			}
		}

		foreach ( $group_items as $key => $items ) {
			foreach ( $this->compare_group( $key, $items, $targets[ $key ]['fields'] ) as $pid => $row ) {
				$this->save_compare( $pid, $key, $row );
			}
		}

		delete_option( self::STALE );
		return count( $rows );
	}

	private function save_result( $row ) {
		global $wpdb;
		$row['checked_at'] = current_time( 'mysql', true );
		$columns = array( 'property_id', 'status', 'source', 'lat', 'lng', 'osm_neighbourhood', 'osm_suburb', 'osm_postcode', 'osm_display_name', 'osm_address', 'error', 'checked_at' );
		$wpdb->delete( self::results_table(), array( 'property_id' => $row['property_id'] ) );
		$wpdb->insert( self::results_table(), array_intersect_key( $row, array_flip( $columns ) ) );
	}

	private function save_compare( $property_id, $target, $row ) {
		global $wpdb;
		$wpdb->replace(
			self::compare_table(),
			array(
				'property_id'     => $property_id,
				'target'          => $target,
				'current_value'   => $row['current_value'],
				'suggested'       => mb_substr( (string) $row['suggested'], 0, 255 ),
				'suggested_field' => $row['suggested_field'],
				'matched_field'   => $row['matched_field'],
				'outcome'         => $row['outcome'],
				'details'         => $row['details'],
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Log
	 * ------------------------------------------------------------------- */

	public function log( $level, $message, $property_id = 0 ) {
		global $wpdb;
		$wpdb->insert(
			self::log_table(),
			array(
				'logged_at'   => current_time( 'mysql', true ),
				'level'       => $level,
				'property_id' => (int) $property_id,
				'message'     => $message,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * admin-post handlers
	 * ------------------------------------------------------------------- */

	public function handle_reset() {
		global $wpdb;
		$this->check_admin_request( 'phosm_reset' );
		$wpdb->query( 'TRUNCATE TABLE ' . self::results_table() ); // phpcs:ignore
		$wpdb->query( 'TRUNCATE TABLE ' . self::compare_table() ); // phpcs:ignore
		$this->log( 'info', 'All results cleared by ' . wp_get_current_user()->user_login );
		$this->redirect( 'run', 'reset' );
	}

	public function handle_retry_errors() {
		global $wpdb;
		$this->check_admin_request( 'phosm_retry_errors' );
		$compare = self::compare_table();
		$results = self::results_table();
		$wpdb->query( "DELETE c FROM {$compare} c INNER JOIN {$results} r ON r.property_id = c.property_id WHERE r.status = 'error'" ); // phpcs:ignore
		$n = $wpdb->delete( $results, array( 'status' => 'error' ) );
		$this->log( 'info', sprintf( '%d errored properties queued for retry', (int) $n ) );
		$this->redirect( 'run', 'retry_errors' );
	}

	public function handle_recompare() {
		$this->check_admin_request( 'phosm_recompare' );
		$n = $this->recompute_all();
		$this->log( 'info', sprintf( 'Re-compared %d properties against current data (no OSM requests made)', $n ) );
		$this->redirect( 'run', 'recompared' );
	}

	public function handle_clear_log() {
		global $wpdb;
		$this->check_admin_request( 'phosm_clear_log' );
		$wpdb->query( 'TRUNCATE TABLE ' . self::log_table() ); // phpcs:ignore
		$this->redirect( 'log', 'log_cleared' );
	}

	public function handle_export() {
		global $wpdb;
		$this->check_admin_request( 'phosm_export' );

		if ( get_option( self::STALE ) ) {
			$this->recompute_all();
		}

		$targets  = self::targets();
		$results  = self::results_table();
		$rows     = $wpdb->get_results( "SELECT r.*, p.post_title FROM {$results} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.property_id ORDER BY r.property_id", ARRAY_A ); // phpcs:ignore
		$compared = array();
		foreach ( $wpdb->get_results( 'SELECT property_id, target, current_value, suggested, suggested_field, matched_field, outcome FROM ' . self::compare_table(), ARRAY_A ) as $c ) { // phpcs:ignore
			$compared[ $c['property_id'] ][ $c['target'] ] = $c;
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=osm-location-check-' . gmdate( 'Y-m-d' ) . '.csv' );

		$header = array( 'property_id', 'title', 'source', 'lat', 'lng', 'osm_neighbourhood', 'osm_suburb', 'osm_postcode', 'osm_other_areas', 'osm_display_name', 'error' );
		foreach ( $targets as $t ) {
			foreach ( array( 'entered', 'would_assign', 'from_field', 'matched_field', 'outcome' ) as $col ) {
				$header[] = $t['label'] . ' ' . $col;
			}
		}

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, $header );
		foreach ( $rows as $r ) {
			$address = json_decode( (string) $r['osm_address'], true );
			$other   = array();
			if ( is_array( $address ) ) {
				foreach ( self::$fields as $f ) {
					if ( ! in_array( $f, self::$primary_fields, true ) && ! empty( $address[ $f ] ) && is_string( $address[ $f ] ) ) {
						$other[] = $f . '=' . $address[ $f ];
					}
				}
			}
			$line = array( $r['property_id'], $r['post_title'], $r['source'], $r['lat'], $r['lng'], $r['osm_neighbourhood'], $r['osm_suburb'], $r['osm_postcode'], implode( '; ', $other ), $r['osm_display_name'], $r['error'] );
			foreach ( array_keys( $targets ) as $key ) {
				$c = $compared[ $r['property_id'] ][ $key ] ?? array();
				foreach ( array( 'current_value', 'suggested', 'suggested_field', 'matched_field', 'outcome' ) as $col ) {
					$line[] = $c[ $col ] ?? '';
				}
			}
			// Guard against spreadsheet formula injection.
			$line = array_map(
				function ( $v ) {
					$v = (string) $v;
					return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@' ), true ) && ! is_numeric( $v ) ) ? "'" . $v : $v;
				},
				$line
			);
			fputcsv( $out, $line );
		}
		fclose( $out );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private function user_can() {
		return current_user_can( self::capability() ) || current_user_can( 'manage_options' );
	}

	private function check_ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! $this->user_can() ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
		}
	}

	private function check_admin_request( $action ) {
		check_admin_referer( $action );
		if ( ! $this->user_can() ) {
			wp_die( esc_html__( 'Permission denied', 'ph-osm-location-checker' ) );
		}
	}

	private function redirect( $tab, $msg ) {
		wp_safe_redirect( self::page_url( $tab, array( 'phosm_msg' => $msg ) ) );
		exit;
	}
}

PH_OSM_Location_Checker::instance();
