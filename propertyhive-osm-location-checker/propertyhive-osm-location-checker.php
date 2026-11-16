<?php
/**
 * Plugin Name: Property Hive OSM Location Checker
 * Description: Uses OpenStreetMap (Nominatim) to look up each property's neighbourhood / suburb and compares it against the Location already assigned in Property Hive, to see how reliably Locations could be assigned automatically.
 * Version: 1.0.0
 * Author: Property Hive
 * Requires at least: 5.6
 * Requires PHP: 7.2
 * Text Domain: ph-osm-location-checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PH_OSM_Location_Checker {

	const VERSION     = '1.0.0';
	const DB_VERSION  = '1';
	const OPTION      = 'phosm_settings';
	const PAGE_SLUG   = 'ph-osm-location-checker';
	const NONCE       = 'phosm_nonce';
	const LOCK_OPTION = 'phosm_lock';

	/**
	 * OSM address fields we look at, in the order we'd use them when picking a Location automatically.
	 * Most specific first.
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

	/** The fields this experiment is really about. */
	public static $primary_fields = array( 'neighbourhood', 'suburb' );

	private static $instance = null;

	/** @var array|null normalised name => term ids */
	private $term_index = null;
	/** @var array term id => WP_Term */
	private $terms = array();

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

	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'phosm_log';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$results = self::results_table();
		$log     = self::log_table();

		dbDelta( "CREATE TABLE {$results} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			property_id bigint(20) unsigned NOT NULL,
			source varchar(20) NOT NULL DEFAULT '',
			lat decimal(10,7) NULL,
			lng decimal(10,7) NULL,
			osm_neighbourhood varchar(191) NOT NULL DEFAULT '',
			osm_suburb varchar(191) NOT NULL DEFAULT '',
			osm_other text NULL,
			osm_display_name text NULL,
			osm_address longtext NULL,
			assigned_term_ids varchar(255) NOT NULL DEFAULT '',
			assigned_terms text NULL,
			suggested_term_id bigint(20) unsigned NOT NULL DEFAULT 0,
			suggested_term varchar(255) NOT NULL DEFAULT '',
			suggested_field varchar(30) NOT NULL DEFAULT '',
			matched_field varchar(30) NOT NULL DEFAULT '',
			outcome varchar(20) NOT NULL DEFAULT '',
			error text NULL,
			checked_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY property_id (property_id),
			KEY outcome (outcome)
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
	 * Settings
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
			),
			false
		);

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
				),
			)
		);
	}

	private static function page_url( $tab = 'run', $args = array() ) {
		$parent = class_exists( 'PropertyHive' ) ? 'admin.php' : 'tools.php';
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG, 'tab' => $tab ), $args ), admin_url( $parent ) );
	}

	public function render_page() {
		if ( ! current_user_can( self::capability() ) && ! current_user_can( 'manage_options' ) ) {
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
			'recompared'     => __( 'Results re-compared against the current Locations using the cached OSM data.', 'ph-osm-location-checker' ),
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
		?>
		<div class="phosm-card">
			<h2><?php esc_html_e( 'Run lookup', 'ph-osm-location-checker' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: seconds */
					esc_html__( 'Each property is looked up one at a time with at least %s seconds between OSM requests, in line with the Nominatim usage policy. Runs are resumable: properties already checked are skipped, so you can run in short bursts.', 'ph-osm-location-checker' ),
					esc_html( number_format_i18n( $settings['delay'], 1 ) )
				);
				?>
			</p>

			<p class="phosm-counts">
				<?php esc_html_e( 'Properties in scope:', 'ph-osm-location-checker' ); ?> <strong id="phosm-total"><?php echo esc_html( number_format_i18n( $counts['total'] ) ); ?></strong>
				&nbsp;|&nbsp; <?php esc_html_e( 'Checked:', 'ph-osm-location-checker' ); ?> <strong id="phosm-checked"><?php echo esc_html( number_format_i18n( $counts['checked'] ) ); ?></strong>
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
				$this->admin_post_form( 'phosm_reset', __( 'Clear all results', 'ph-osm-location-checker' ), 'button button-link-delete', __( 'Clear all results? Every property will need looking up again.', 'ph-osm-location-checker' ) );
				?>
			</div>
			<p class="description"><?php esc_html_e( '“Re-compare” makes no OSM requests: use it after you add or rename Locations to see how the numbers change.', 'ph-osm-location-checker' ); ?></p>
		</div>

		<div class="phosm-card" id="phosm-stats">
			<?php $this->render_stats(); ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Stats
	 * ------------------------------------------------------------------- */

	public static function outcome_labels() {
		return array(
			'match'       => array( __( 'Match', 'ph-osm-location-checker' ), __( 'The Location we would auto-assign is one already assigned.', 'ph-osm-location-checker' ) ),
			'partial'     => array( __( 'Partial match', 'ph-osm-location-checker' ), __( 'An OSM value matches an assigned Location, but a different (more specific) field would have been picked first.', 'ph-osm-location-checker' ) ),
			'broader'     => array( __( 'Broader', 'ph-osm-location-checker' ), __( 'We would pick a parent of the assigned Location (e.g. town instead of suburb).', 'ph-osm-location-checker' ) ),
			'narrower'    => array( __( 'Narrower', 'ph-osm-location-checker' ), __( 'We would pick a child of the assigned Location (more specific than what was entered).', 'ph-osm-location-checker' ) ),
			'mismatch'    => array( __( 'Mismatch', 'ph-osm-location-checker' ), __( 'We would pick an existing Location that differs from the one assigned.', 'ph-osm-location-checker' ) ),
			'no_term'     => array( __( 'No matching Location', 'ph-osm-location-checker' ), __( 'OSM returned a neighbourhood/suburb but no Location with that name exists.', 'ph-osm-location-checker' ) ),
			'no_osm_data' => array( __( 'No OSM data', 'ph-osm-location-checker' ), __( 'OSM returned no usable neighbourhood/suburb/area.', 'ph-osm-location-checker' ) ),
			'unassigned'  => array( __( 'No Location assigned', 'ph-osm-location-checker' ), __( 'Property has no Location yet. Not counted in accuracy; shows what would be assigned.', 'ph-osm-location-checker' ) ),
			'error'       => array( __( 'Error', 'ph-osm-location-checker' ), __( 'Lookup failed (no coordinates/address, or OSM request error).', 'ph-osm-location-checker' ) ),
		);
	}

	public function ajax_stats() {
		$this->check_ajax();
		ob_start();
		$this->render_stats();
		wp_send_json_success( array( 'html' => ob_get_clean(), 'counts' => $this->queue_counts() ) );
	}

	private function pct( $part, $whole ) {
		return $whole ? number_format_i18n( 100 * $part / $whole, 1 ) . '%' : '–';
	}

	private function render_stats() {
		global $wpdb;
		$table = self::results_table();
		$rows  = $wpdb->get_results( "SELECT outcome, suggested_field, matched_field, suggested_term, assigned_terms, osm_neighbourhood, osm_suburb, osm_other, osm_address FROM {$table}", ARRAY_A ); // phpcs:ignore

		echo '<h2>' . esc_html__( 'Statistics', 'ph-osm-location-checker' ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No results yet. Run a lookup to see statistics.', 'ph-osm-location-checker' ) . '</p>';
			return;
		}

		$labels   = self::outcome_labels();
		$outcomes = array_fill_keys( array_keys( $labels ), 0 );
		$matched_fields = array();
		$field_stats    = array();
		foreach ( self::$fields as $f ) {
			$field_stats[ $f ] = array( 'returned' => 0, 'has_term' => 0, 'assigned' => 0 );
		}
		$mismatch_pairs = array();
		$missing_terms  = array();
		$suggest_new    = array();

		foreach ( $rows as $row ) {
			$outcomes[ $row['outcome'] ] = ( $outcomes[ $row['outcome'] ] ?? 0 ) + 1;

			if ( $row['matched_field'] ) {
				$matched_fields[ $row['matched_field'] ] = ( $matched_fields[ $row['matched_field'] ] ?? 0 ) + 1;
			}

			$detail = json_decode( (string) $row['osm_other'], true );
			if ( is_array( $detail ) && ! empty( $detail['fields'] ) && 'unassigned' !== $row['outcome'] ) {
				foreach ( $detail['fields'] as $f => $info ) {
					if ( ! isset( $field_stats[ $f ] ) ) {
						continue;
					}
					$field_stats[ $f ]['returned']++;
					if ( ! empty( $info['term_id'] ) ) {
						$field_stats[ $f ]['has_term']++;
					}
					if ( ! empty( $info['assigned'] ) ) {
						$field_stats[ $f ]['assigned']++;
					}
				}
			}

			if ( 'mismatch' === $row['outcome'] ) {
				$key = $row['assigned_terms'] . ' → ' . $row['suggested_term'];
				$mismatch_pairs[ $key ] = ( $mismatch_pairs[ $key ] ?? 0 ) + 1;
			}

			if ( in_array( $row['outcome'], array( 'no_term', 'mismatch', 'partial', 'broader', 'unassigned' ), true ) && is_array( $detail ) && ! empty( $detail['fields'] ) ) {
				foreach ( self::$primary_fields as $f ) {
					if ( ! empty( $detail['fields'][ $f ] ) && empty( $detail['fields'][ $f ]['term_id'] ) ) {
						$key = $detail['fields'][ $f ]['value'] . ' (' . $f . ')';
						$missing_terms[ $key ] = ( $missing_terms[ $key ] ?? 0 ) + 1;
					}
				}
			}

			if ( 'unassigned' === $row['outcome'] && $row['suggested_term'] ) {
				$suggest_new[ $row['suggested_term'] ] = ( $suggest_new[ $row['suggested_term'] ] ?? 0 ) + 1;
			}
		}

		$total       = count( $rows );
		$evaluable   = $total - $outcomes['unassigned'] - $outcomes['error'];
		$exact       = $outcomes['match'];
		$agrees      = $outcomes['match'] + $outcomes['partial'];
		$related     = $agrees + $outcomes['broader'] + $outcomes['narrower'];

		?>
		<div class="phosm-headline">
			<div><span class="phosm-big"><?php echo esc_html( $this->pct( $exact, $evaluable ) ); ?></span><?php esc_html_e( 'Auto-assign would match exactly', 'ph-osm-location-checker' ); ?></div>
			<div><span class="phosm-big"><?php echo esc_html( $this->pct( $agrees, $evaluable ) ); ?></span><?php esc_html_e( 'OSM agrees with an assigned Location (any field)', 'ph-osm-location-checker' ); ?></div>
			<div><span class="phosm-big"><?php echo esc_html( $this->pct( $related, $evaluable ) ); ?></span><?php esc_html_e( 'Same or parent/child Location', 'ph-osm-location-checker' ); ?></div>
			<div><span class="phosm-big"><?php echo esc_html( $this->pct( $outcomes['mismatch'], $evaluable ) ); ?></span><?php esc_html_e( 'Would assign the wrong Location', 'ph-osm-location-checker' ); ?></div>
		</div>
		<p class="description">
			<?php
			printf(
				/* translators: 1: total checked, 2: evaluable count */
				esc_html__( '%1$s properties checked; %2$s had a Location assigned and a successful lookup, and are used for the percentages above.', 'ph-osm-location-checker' ),
				esc_html( number_format_i18n( $total ) ),
				esc_html( number_format_i18n( $evaluable ) )
			);
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
							<td><a href="<?php echo esc_url( self::page_url( 'results', array( 'outcome' => $key ) ) ); ?>"><span class="phosm-badge phosm-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label[0] ); ?></span></a><br><small><?php echo esc_html( $label[1] ); ?></small></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $outcomes[ $key ] ?? 0 ) ); ?></td>
							<td class="num"><?php echo esc_html( $this->pct( $outcomes[ $key ] ?? 0, $total ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div>
				<h3><?php esc_html_e( 'How useful is each OSM field?', 'ph-osm-location-checker' ); ?></h3>
				<p class="description"><?php esc_html_e( 'For properties with a Location assigned: how often OSM returned each field, how often that value exists as a Location, and how often it is one of the property’s assigned Locations.', 'ph-osm-location-checker' ); ?></p>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'OSM field', 'ph-osm-location-checker' ); ?></th>
						<th class="num"><?php esc_html_e( 'Returned', 'ph-osm-location-checker' ); ?></th>
						<th class="num"><?php esc_html_e( 'Location exists', 'ph-osm-location-checker' ); ?></th>
						<th class="num"><?php esc_html_e( 'Is assigned', 'ph-osm-location-checker' ); ?></th>
						<th class="num"><?php esc_html_e( 'Accuracy when returned', 'ph-osm-location-checker' ); ?></th>
					</tr></thead>
					<tbody>
					<?php
					foreach ( $field_stats as $f => $s ) :
						if ( ! $s['returned'] ) {
							continue;
						}
						?>
						<tr<?php echo in_array( $f, self::$primary_fields, true ) ? ' class="phosm-primary"' : ''; ?>>
							<td><code><?php echo esc_html( $f ); ?></code></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $s['returned'] ) ); ?> <small>(<?php echo esc_html( $this->pct( $s['returned'], $evaluable ) ); ?>)</small></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $s['has_term'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $s['assigned'] ) ); ?></td>
							<td class="num"><strong><?php echo esc_html( $this->pct( $s['assigned'], $s['returned'] ) ); ?></strong></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $matched_fields ) : ?>
					<h3><?php esc_html_e( 'First OSM field that matched the assigned Location', 'ph-osm-location-checker' ); ?></h3>
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

		<div class="phosm-grid">
			<?php
			$this->render_top_table( __( 'Most common mismatches (assigned → would assign)', 'ph-osm-location-checker' ), $mismatch_pairs );
			$this->render_top_table( __( 'OSM neighbourhoods/suburbs with no matching Location', 'ph-osm-location-checker' ), $missing_terms, __( 'Creating these Locations (or aliasing them) would improve coverage.', 'ph-osm-location-checker' ) );
			$this->render_top_table( __( 'Suggested Locations for unassigned properties', 'ph-osm-location-checker' ), $suggest_new );
			?>
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
		$table    = self::results_table();
		$labels   = self::outcome_labels();
		$outcome  = sanitize_key( $_GET['outcome'] ?? '' );
		$search   = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 50;

		$where  = array( '1=1' );
		$params = array();
		if ( isset( $labels[ $outcome ] ) ) {
			$where[]  = 'r.outcome = %s';
			$params[] = $outcome;
		}
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(p.post_title LIKE %s OR r.osm_neighbourhood LIKE %s OR r.osm_suburb LIKE %s OR r.assigned_terms LIKE %s OR r.suggested_term LIKE %s)';
			$params   = array_merge( $params, array( $like, $like, $like, $like, $like ) );
		}
		$where_sql = implode( ' AND ', $where );
		$from      = "{$table} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.property_id";

		$count_sql = "SELECT COUNT(*) FROM {$from} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore

		$sql  = "SELECT r.*, p.post_title FROM {$from} WHERE {$where_sql} ORDER BY r.checked_at DESC, r.id DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, ( $paged - 1 ) * $per_page ) ) ) ); // phpcs:ignore
		?>
		<form method="get" class="phosm-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<input type="hidden" name="tab" value="results">
			<select name="outcome">
				<option value=""><?php esc_html_e( 'All outcomes', 'ph-osm-location-checker' ); ?></option>
				<?php foreach ( $labels as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $outcome, $key ); ?>><?php echo esc_html( $label[0] ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search property, area or Location', 'ph-osm-location-checker' ); ?>">
			<button class="button"><?php esc_html_e( 'Filter', 'ph-osm-location-checker' ); ?></button>
			<span class="phosm-total"><?php echo esc_html( sprintf( _n( '%s result', '%s results', $total, 'ph-osm-location-checker' ), number_format_i18n( $total ) ) ); ?></span>
		</form>

		<table class="widefat striped phosm-results">
			<thead><tr>
				<th><?php esc_html_e( 'Property', 'ph-osm-location-checker' ); ?></th>
				<th><?php esc_html_e( 'OSM neighbourhood', 'ph-osm-location-checker' ); ?></th>
				<th><?php esc_html_e( 'OSM suburb', 'ph-osm-location-checker' ); ?></th>
				<th><?php esc_html_e( 'Other OSM areas', 'ph-osm-location-checker' ); ?></th>
				<th><?php esc_html_e( 'Assigned Location(s)', 'ph-osm-location-checker' ); ?></th>
				<th><?php esc_html_e( 'Would assign', 'ph-osm-location-checker' ); ?></th>
				<th><?php esc_html_e( 'Outcome', 'ph-osm-location-checker' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No results.', 'ph-osm-location-checker' ); ?></td></tr>
			<?php endif; ?>
			<?php
			foreach ( $rows as $row ) :
				$detail = json_decode( (string) $row->osm_other, true );
				$other  = array();
				if ( is_array( $detail ) && ! empty( $detail['fields'] ) ) {
					foreach ( $detail['fields'] as $f => $info ) {
						if ( ! in_array( $f, self::$primary_fields, true ) ) {
							$other[] = $info['value'] . ' (' . $f . ')';
						}
					}
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
					<td><?php echo esc_html( $row->assigned_terms ); ?></td>
					<td><?php echo esc_html( $row->suggested_term ); ?><?php echo $row->suggested_field ? '<br><small>' . esc_html( $row->suggested_field ) . '</small>' : ''; ?></td>
					<td>
						<span class="phosm-badge phosm-<?php echo esc_attr( $row->outcome ); ?>"><?php echo esc_html( $label ); ?></span>
						<?php if ( $row->matched_field ) : ?><br><small><?php echo esc_html( sprintf( __( 'matched on %s', 'ph-osm-location-checker' ), $row->matched_field ) ); ?></small><?php endif; ?>
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

		$labels = self::outcome_labels();
		wp_send_json_success(
			array(
				'done'        => false,
				'halt'        => ! empty( $result['halt'] ),
				'property_id' => $property_id,
				'title'       => get_the_title( $property_id ),
				'outcome'     => $result['outcome'],
				'label'       => $labels[ $result['outcome'] ][0] ?? $result['outcome'],
				'message'     => $result['message'],
				'counts'      => $this->queue_counts(),
			)
		);
	}

	/**
	 * Look a property up and store the comparison.
	 *
	 * @return array{outcome:string,message:string,halt?:bool}
	 */
	public function process_property( $property_id ) {
		$settings = self::settings();
		$lat      = (float) get_post_meta( $property_id, '_latitude', true );
		$lng      = (float) get_post_meta( $property_id, '_longitude', true );
		$has_ll   = ( 0.0 !== $lat || 0.0 !== $lng ) && abs( $lat ) <= 90 && abs( $lng ) <= 180;

		$row = array(
			'property_id' => $property_id,
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
			$row  = array_merge( $row, $this->assigned_columns( $property_id ), array( 'outcome' => 'error', 'error' => $response->get_error_message() ) );
			if ( $halt ) {
				// Don't store a result, so it is retried on the next run.
				$this->log( 'error', 'OSM refused the request, stopping run: ' . $response->get_error_message(), $property_id );
			} else {
				$this->save_result( $row );
				$this->log( 'error', $response->get_error_message(), $property_id );
			}
			return array( 'outcome' => 'error', 'message' => $response->get_error_message(), 'halt' => $halt );
		}

		if ( isset( $response['error'] ) ) {
			$address = array();
			$row['osm_display_name'] = '';
		} else {
			$address = isset( $response['address'] ) && is_array( $response['address'] ) ? $response['address'] : array();
			$row['osm_display_name'] = $response['display_name'] ?? '';
		}

		$row['osm_address'] = wp_json_encode( $address );
		$row = array_merge( $row, $this->compare( $property_id, $address ) );
		$this->save_result( $row );

		$message = sprintf(
			'%s: neighbourhood "%s", suburb "%s" | assigned: %s | would assign: %s%s',
			$row['source'],
			$row['osm_neighbourhood'],
			$row['osm_suburb'],
			$row['assigned_terms'] ? $row['assigned_terms'] : '(none)',
			$row['suggested_term'] ? $row['suggested_term'] : '(nothing)',
			$row['suggested_field'] ? ' [' . $row['suggested_field'] . ']' : ''
		);
		$this->log( in_array( $row['outcome'], array( 'mismatch', 'no_osm_data' ), true ) ? 'warning' : 'info', $row['outcome'] . ' – ' . $message, $property_id );

		return array( 'outcome' => $row['outcome'], 'message' => $message );
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

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => 'PropertyHive-OSM-Location-Checker/' . self::VERSION . ' (' . home_url() . ')',
				'headers'    => array( 'Referer' => home_url() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $code ) {
			return new WP_Error( 'rate_limited', 'HTTP 429 Too Many Requests from OSM' );
		}
		if ( 403 === $code || 418 === $code ) {
			return new WP_Error( 'blocked', 'HTTP ' . $code . ' from OSM – access blocked. Check the usage policy and contact email.' );
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'http_' . $code, 'HTTP ' . $code . ' from OSM' );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( null === $data ) {
			return new WP_Error( 'bad_json', 'Invalid JSON returned by OSM' );
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
	 * Comparison
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

	private function assigned_columns( $property_id ) {
		$this->load_terms();
		$ids = wp_get_object_terms( $property_id, 'location', array( 'fields' => 'ids' ) );
		$ids = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
		return array(
			'assigned_term_ids' => implode( ',', $ids ),
			'assigned_terms'    => implode( ', ', array_map( array( $this, 'term_path' ), $ids ) ),
		);
	}

	/**
	 * Compare an OSM address against the property's assigned Locations.
	 */
	public function compare( $property_id, $address ) {
		$this->load_terms();

		$osm = array();
		foreach ( self::$fields as $field ) {
			if ( ! empty( $address[ $field ] ) && is_string( $address[ $field ] ) ) {
				$osm[ $field ] = trim( $address[ $field ] );
			}
		}

		$assigned_cols = $this->assigned_columns( $property_id );
		$assigned      = $assigned_cols['assigned_term_ids'] ? array_map( 'intval', explode( ',', $assigned_cols['assigned_term_ids'] ) ) : array();

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
			$fields[ $field ] = array(
				'value'    => $value,
				'term_id'  => $term_id,
				'assigned' => $term_id && in_array( $term_id, $assigned, true ),
			);
			if ( $term_id && ! $suggested_id ) {
				$suggested_id    = $term_id;
				$suggested_field = $field;
			}
			if ( $fields[ $field ]['assigned'] && ! $matched_field ) {
				$matched_field = $field;
			}
		}

		$has_primary = (bool) array_intersect( self::$primary_fields, array_keys( $osm ) );

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
		} elseif ( $has_primary ) {
			$outcome = 'no_term';
		} else {
			$outcome = 'no_osm_data';
		}

		return array_merge(
			$assigned_cols,
			array(
				'osm_neighbourhood' => $osm['neighbourhood'] ?? '',
				'osm_suburb'        => $osm['suburb'] ?? '',
				'osm_other'         => wp_json_encode( array( 'fields' => $fields ) ),
				'suggested_term_id' => $suggested_id,
				'suggested_term'    => $suggested_id ? $this->term_path( $suggested_id ) : '',
				'suggested_field'   => $suggested_field,
				'matched_field'     => $matched_field,
				'outcome'           => $outcome,
				'error'             => null,
			)
		);
	}

	private function save_result( $row ) {
		global $wpdb;
		$row['checked_at'] = current_time( 'mysql', true );
		$columns = array( 'property_id', 'source', 'lat', 'lng', 'osm_neighbourhood', 'osm_suburb', 'osm_other', 'osm_display_name', 'osm_address', 'assigned_term_ids', 'assigned_terms', 'suggested_term_id', 'suggested_term', 'suggested_field', 'matched_field', 'outcome', 'error', 'checked_at' );
		$data    = array_intersect_key( $row, array_flip( $columns ) );
		$wpdb->delete( self::results_table(), array( 'property_id' => $row['property_id'] ) );
		$wpdb->insert( self::results_table(), $data );
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
		$this->log( 'info', 'All results cleared by ' . wp_get_current_user()->user_login );
		$this->redirect( 'run', 'reset' );
	}

	public function handle_retry_errors() {
		global $wpdb;
		$this->check_admin_request( 'phosm_retry_errors' );
		$n = $wpdb->delete( self::results_table(), array( 'outcome' => 'error' ) );
		$this->log( 'info', sprintf( '%d errored properties queued for retry', (int) $n ) );
		$this->redirect( 'run', 'retry_errors' );
	}

	public function handle_recompare() {
		global $wpdb;
		$this->check_admin_request( 'phosm_recompare' );
		$table = self::results_table();
		$rows  = $wpdb->get_results( "SELECT id, property_id, osm_address FROM {$table} WHERE outcome <> 'error'" ); // phpcs:ignore
		foreach ( $rows as $row ) {
			$address = json_decode( (string) $row->osm_address, true );
			$data    = $this->compare( (int) $row->property_id, is_array( $address ) ? $address : array() );
			$wpdb->update( $table, $data, array( 'id' => $row->id ) );
		}
		$this->log( 'info', sprintf( 'Re-compared %d results against current Locations (no OSM requests made)', count( $rows ) ) );
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
		$table = self::results_table();
		$rows  = $wpdb->get_results( "SELECT r.*, p.post_title FROM {$table} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.property_id ORDER BY r.property_id", ARRAY_A ); // phpcs:ignore

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=osm-location-check-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'property_id', 'title', 'source', 'lat', 'lng', 'osm_neighbourhood', 'osm_suburb', 'osm_other_areas', 'osm_display_name', 'assigned_locations', 'would_assign', 'would_assign_from_field', 'matched_field', 'outcome', 'error', 'checked_at_utc' ) );
		foreach ( $rows as $r ) {
			$detail = json_decode( (string) $r['osm_other'], true );
			$other  = array();
			if ( is_array( $detail ) && ! empty( $detail['fields'] ) ) {
				foreach ( $detail['fields'] as $f => $info ) {
					if ( ! in_array( $f, self::$primary_fields, true ) ) {
						$other[] = $f . '=' . $info['value'];
					}
				}
			}
			$line = array( $r['property_id'], $r['post_title'], $r['source'], $r['lat'], $r['lng'], $r['osm_neighbourhood'], $r['osm_suburb'], implode( '; ', $other ), $r['osm_display_name'], $r['assigned_terms'], $r['suggested_term'], $r['suggested_field'], $r['matched_field'], $r['outcome'], $r['error'], $r['checked_at'] );
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
