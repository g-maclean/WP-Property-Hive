<?php
/**
 * Plugin Name: Property Hive Smart Location
 * Description: Fills a "smart suburb" field for each property using OpenStreetMap, with Claude AI as a fallback, compares it with the manually entered suburb, and can organise Location terms into a sensible hierarchy.
 * Version: 1.4.1
 * Author: Property Hive
 * Requires at least: 5.6
 * Requires PHP: 7.2
 * Text Domain: ph-smart-location
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-phsl-logger.php';
require_once __DIR__ . '/includes/class-phsl-organiser.php';

final class PH_Smart_Location {

	const VERSION       = '1.4.1';
	const DB_VERSION    = '6';
	const OPTION        = 'phsl_settings';
	const PAGE_SLUG     = 'ph-smart-location';
	const NONCE         = 'phsl_nonce';
	const LOCK_OPTION   = 'phsl_lock';
	const CRON_HOOK     = 'phsl_cron';
	const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
	const API_VERSION   = '2026-10-21';

	/**
	 * The plugin writes the suburb to its own field (default "_smart_suburb"); it never touches the
	 * Location taxonomy or the manually entered suburb. Fields are only filled when empty, so a
	 * value someone edits by hand is never overwritten.
	 *
	 * Tracking uses two small post meta keys:
	 * _phsl_status: assigned | suggested | not_found | error | skipped | retry
	 * _phsl:        flags: t (time), o (OSM: 1 suburb found / 0 none / x failed),
	 *               a (Claude: used | unsure | error | nokey), src (osm:suburb | claude),
	 *               cf (Claude confidence), n (retry count),
	 *               v (pending suggestion: n => suburb, nb => neighbourhood, q => quarter)
	 * Everything else goes to the log file.
	 */
	const META_STATUS = '_phsl_status';
	const META_STATE  = '_phsl';

	/** OSM area fields passed to Claude as context and logged. */
	public static $osm_context_fields = array( 'neighbourhood', 'suburb', 'quarter', 'residential', 'hamlet', 'village', 'borough', 'city_district', 'town', 'city', 'county' );

	public static $models = array(
		'claude-opus-5-5'   => 'Claude Opus 5.5 (most accurate)',
		'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
		'claude-haiku-5-5'  => 'Claude Haiku 5.5 (cheapest)',
	);

	/** Comparison values, in display order, with descriptions. */
	public static $comparisons = array(
		'match'         => 'Same suburb (ignoring case and punctuation), or one of several manual values',
		'near match'    => 'Same apart from a small spelling difference',
		'partial match' => 'One name contains the other, e.g. “Harborne” / “Harborne Village”',
		'mismatch'      => 'Both filled in, and different',
		'smart only'    => 'No manual suburb; a smart suburb was found',
		'manual only'   => 'Looked up, but no smart suburb was found',
		'not checked'   => 'Not looked up yet',
		'both empty'    => 'Neither has a value',
	);

	private static $instance = null;

	private $vocab    = null;
	private $last_osm = array();

	/** @var PHSL_Organiser */
	public $organiser;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );

		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( $this, 'cron_run' ) );

		// Property Hive Property Import add-on (every format) and the Property Sales Online add-on.
		foreach ( (array) apply_filters( 'phsl_import_hooks', array( 'propertyhive_property_imported', 'propertyhive_property_imported_property_sales_online_json' ) ) as $hook ) {
			add_action( $hook, array( $this, 'on_imported' ), 20, 1 );
		}

		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 99 );

		add_action( 'wp_ajax_phsl_step', array( $this, 'ajax_step' ) );
		add_action( 'wp_ajax_phsl_test', array( $this, 'ajax_test' ) );

		$this->organiser = new PHSL_Organiser( $this );

		foreach ( array( 'save_settings', 'apply', 'apply_all', 'retry', 'requeue', 'export', 'clear_log', 'download_log' ) as $action ) {
			add_action( 'admin_post_phsl_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	/* =====================================================================
	 * Install / upgrade
	 * =================================================================== */

	public static function activate() {
		if ( ! get_option( 'phsl_activated_at' ) ) {
			update_option( 'phsl_activated_at', current_time( 'mysql', true ), false );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'phsl_ten_minutes', self::CRON_HOOK );
		}
		if ( ! get_option( 'phsl_db_version' ) ) {
			update_option( 'phsl_db_version', self::DB_VERSION, false );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function maybe_upgrade() {
		global $wpdb;
		$version = get_option( 'phsl_db_version' );
		if ( $version && version_compare( $version, '2', '<' ) ) {
			// The first builds kept a database log and verbose post meta.
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}phsl_log" ); // phpcs:ignore
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s, %s)", '_phsl_status', '_phsl_result', '_phsl_attempted', '_phsl_tries' ) );
		}
		if ( $version && version_compare( $version, '5', '<' ) ) {
			// Earlier builds assigned the Location taxonomy. The suburb now goes to its own field,
			// so forget the old Location tracking and look properties up again for that field.
			// (Locations assigned or created by earlier builds are left exactly as they are.)
			delete_option( 'phsl_location_types' );
			delete_option( 'phsl_suburb_terms' );
			$n = $this->requeue( function () {
				return true;
			} );
			$this->log( 'info', sprintf( 'Upgraded: the suburb is now saved to the smart suburb field, not Locations. %d properties re-queued.', $n ) );
		}
		if ( $version && version_compare( $version, '6', '<' ) ) {
			// 1.4 saved to "smart_suburb" by default; the default is now "_smart_suburb".
			$saved = (array) get_option( self::OPTION, array() );
			if ( ! isset( $saved['smart_key'] ) || 'smart_suburb' === $saved['smart_key'] ) {
				$moved = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s", '_smart_suburb', 'smart_suburb' ) );
				if ( isset( $saved['smart_key'] ) ) {
					$saved['smart_key'] = '_smart_suburb';
					update_option( self::OPTION, $saved, false );
				}
				$this->log( 'info', sprintf( 'Upgraded: smart suburb field renamed from smart_suburb to _smart_suburb (%d values moved).', $moved ) );
			}
		}
		if ( self::DB_VERSION !== $version ) {
			update_option( 'phsl_db_version', self::DB_VERSION, false );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'phsl_ten_minutes', self::CRON_HOOK );
		}
	}

	public static function cron_schedules( $schedules ) {
		$schedules['phsl_ten_minutes'] = array( 'interval' => 600, 'display' => 'Every 10 minutes (Smart Location)' );
		return $schedules;
	}

	/* =====================================================================
	 * Settings
	 * =================================================================== */

	public static function defaults() {
		return array(
			'mode'                  => 'suggest',
			'on_import'             => 1,
			'auto_new'              => 1,
			'statuses'              => array( 'publish' ),
			'smart_key'             => '_smart_suburb',
			'manual_key'            => '_suburb',
			'neighbourhood_enabled' => 0,
			'neighbourhood_key'     => '_neighbourhood',
			'quarter_enabled'       => 0,
			'quarter_key'           => '_quarter',
			'use_claude'            => 1,
			'claude_model'          => 'claude-opus-5-5',
			'claude_api_key'        => '',
			'claude_min_confidence' => 'medium',
			'endpoint'              => 'https://nominatim.openstreetmap.org',
			'email'                 => '',
			'delay'                 => 1.5,
			'language'              => 'en',
			'address_fallback'      => 1,
		);
	}

	public static function settings() {
		$s = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$s['delay'] = max( self::min_delay( $s['endpoint'] ), (float) $s['delay'] );
		if ( empty( $s['statuses'] ) || ! is_array( $s['statuses'] ) ) {
			$s['statuses'] = array( 'publish' );
		}
		if ( ! isset( self::$models[ $s['claude_model'] ] ) ) {
			$s['claude_model'] = 'claude-opus-5-5';
		}
		return $s;
	}

	/** Enabled extra custom fields: OSM field => meta key. */
	private static function extra_fields() {
		$s   = self::settings();
		$out = array();
		foreach ( array( 'neighbourhood', 'quarter' ) as $f ) {
			if ( $s[ $f . '_enabled' ] ) {
				$out[ $f ] = $s[ $f . '_key' ];
			}
		}
		return $out;
	}

	public static function min_delay( $endpoint ) {
		$host = (string) wp_parse_url( $endpoint, PHP_URL_HOST );
		return ( false !== stripos( $host, 'openstreetmap.org' ) ) ? 1.0 : 0.1;
	}

	/** A PHSL_ANTHROPIC_API_KEY constant in wp-config.php wins over the settings field. */
	public static function api_key() {
		if ( defined( 'PHSL_ANTHROPIC_API_KEY' ) && PHSL_ANTHROPIC_API_KEY ) {
			return (string) PHSL_ANTHROPIC_API_KEY;
		}
		return (string) self::settings()['claude_api_key'];
	}

	public static function claude_enabled() {
		return self::settings()['use_claude'] && '' !== self::api_key();
	}

	public function handle_save_settings() {
		$this->check_admin_request( 'phsl_save_settings' );
		$p          = wp_unslash( $_POST );
		$old        = self::settings();
		$had_claude = self::claude_enabled();

		$endpoint = esc_url_raw( trim( $p['endpoint'] ?? '' ) );
		if ( '' === $endpoint ) {
			$endpoint = self::defaults()['endpoint'];
		}
		$statuses = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $p['statuses'] ?? array() ) ), array( 'publish', 'private', 'draft', 'pending', 'future' ) ) );
		$key_in   = trim( (string) ( $p['claude_api_key'] ?? '' ) );

		$new = array(
			'mode'                  => 'assign' === ( $p['mode'] ?? '' ) ? 'assign' : 'suggest',
			'on_import'             => empty( $p['on_import'] ) ? 0 : 1,
			'auto_new'              => empty( $p['auto_new'] ) ? 0 : 1,
			'statuses'              => $statuses ? $statuses : array( 'publish' ),
			'smart_key'             => self::sanitize_meta_key( $p['smart_key'] ?? '_smart_suburb', '_smart_suburb' ),
			'manual_key'            => self::sanitize_meta_key( $p['manual_key'] ?? '_suburb', '_suburb' ),
			'neighbourhood_enabled' => empty( $p['neighbourhood_enabled'] ) ? 0 : 1,
			'neighbourhood_key'     => self::sanitize_meta_key( $p['neighbourhood_key'] ?? '_neighbourhood', '_neighbourhood' ),
			'quarter_enabled'       => empty( $p['quarter_enabled'] ) ? 0 : 1,
			'quarter_key'           => self::sanitize_meta_key( $p['quarter_key'] ?? '_quarter', '_quarter' ),
			'use_claude'            => empty( $p['use_claude'] ) ? 0 : 1,
			'claude_model'          => isset( self::$models[ $p['claude_model'] ?? '' ] ) ? $p['claude_model'] : 'claude-opus-5-5',
			// Blank keeps the saved key (the field is never pre-filled); "-" removes it.
			'claude_api_key'        => '-' === $key_in ? '' : ( '' === $key_in ? $old['claude_api_key'] : sanitize_text_field( $key_in ) ),
			'claude_min_confidence' => in_array( $p['claude_min_confidence'] ?? '', array( 'high', 'medium', 'low' ), true ) ? $p['claude_min_confidence'] : 'medium',
			'endpoint'              => untrailingslashit( $endpoint ),
			'email'                 => sanitize_email( $p['email'] ?? '' ),
			'delay'                 => max( self::min_delay( $endpoint ), (float) ( $p['delay'] ?? 1.5 ) ),
			'language'              => sanitize_text_field( $p['language'] ?? 'en' ),
			'address_fallback'      => empty( $p['address_fallback'] ) ? 0 : 1,
		);
		update_option( self::OPTION, $new, false );

		$changes = array();
		foreach ( $new as $k => $v ) {
			if ( 'claude_api_key' !== $k && ( ! array_key_exists( $k, $old ) || $old[ $k ] != $v ) ) { // phpcs:ignore -- loose on purpose
				$changes[] = $k;
			}
		}
		if ( $changes ) {
			$this->log( 'info', 'Settings changed by ' . wp_get_current_user()->user_login . ': ' . implode( ', ', $changes ), 0, array_intersect_key( $new, array_flip( $changes ) ) );
		}

		// Claude has just become available: give it a go on properties that only missed out because there was no key.
		if ( ! $had_claude && self::claude_enabled() ) {
			$n = $this->requeue( function ( $status, $state ) {
				return 'not_found' === $status && 'nokey' === ( $state['a'] ?? '' );
			} );
			if ( $n ) {
				$this->log( 'info', sprintf( 'Claude is now configured: re-queued %d properties where Claude had been skipped for lack of an API key.', $n ) );
			}
		}

		$this->redirect( 'settings', 'settings_saved' );
	}

	private static function sanitize_meta_key( $key, $fallback ) {
		$key = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $key );
		return '' === $key ? $fallback : $key;
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	public static function normalise( $name ) {
		$name = html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' );
		$name = strtolower( remove_accents( $name ) );
		$name = str_replace( array( '&', '+' ), ' and ', $name );
		$name = preg_replace( "/['’`]/u", '', $name );
		$name = preg_replace( '/[^a-z0-9]+/', ' ', $name );
		$name = preg_replace( '/\bst\b/', 'saint', $name );
		$name = preg_replace( '/^the /', '', trim( $name ) );
		return trim( preg_replace( '/\s+/', ' ', $name ) );
	}

	public static function split_values( $raw ) {
		$values = array();
		foreach ( (array) maybe_unserialize( $raw ) as $v ) {
			if ( ! is_scalar( $v ) ) {
				continue;
			}
			foreach ( preg_split( '/[,;|]+/', (string) $v ) as $part ) {
				$part = trim( $part );
				if ( '' !== $part ) {
					$values[] = $part;
				}
			}
		}
		return $values;
	}

	public static function meta_values( $property_id, $key ) {
		$values = array();
		foreach ( (array) get_post_meta( $property_id, $key, false ) as $raw ) {
			$values = array_merge( $values, self::split_values( $raw ) );
		}
		return array_values( array_unique( $values ) );
	}

	public static function parse_coord( $value ) {
		$value = trim( str_replace( ',', '.', (string) $value ) );
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Compare the manually entered suburb(s) with the smart suburb.
	 *
	 * @param string[] $manual   Manual values (the field may hold several, comma separated).
	 * @param string   $smart    Smart suburb (saved, or a pending suggestion).
	 * @param bool     $checked  Whether the property has been looked up.
	 * @return string One of the keys of self::$comparisons.
	 */
	public static function compare( $manual, $smart, $checked ) {
		$manual = array_values( array_filter( array_map( array( __CLASS__, 'normalise' ), (array) $manual ) ) );
		$smart  = self::normalise( $smart );
		if ( '' === $smart ) {
			if ( ! $checked ) {
				return 'not checked';
			}
			return $manual ? 'manual only' : 'both empty';
		}
		if ( ! $manual ) {
			return 'smart only';
		}
		if ( in_array( $smart, $manual, true ) ) {
			return 'match';
		}
		// Small spelling slips only: 1 character for shorter names, 2 for 10+ letters
		// ("Stirchly", "Northfeild"), never different words ("Selly Oak" vs "Selly Park").
		foreach ( $manual as $m ) {
			$a   = str_replace( ' ', '', $m );
			$b   = str_replace( ' ', '', $smart );
			$len = min( strlen( $a ), strlen( $b ) );
			if ( $a === $b || ( $len >= 5 && levenshtein( $a, $b ) <= ( $len >= 10 ? 2 : 1 ) ) ) {
				return 'near match';
			}
		}
		foreach ( $manual as $m ) {
			if ( false !== strpos( ' ' . $m . ' ', ' ' . $smart . ' ' ) || false !== strpos( ' ' . $smart . ' ', ' ' . $m . ' ' ) ) {
				return 'partial match';
			}
		}
		return 'mismatch';
	}

	/** Spellings already used for suburbs (manual field first, then smart field): normalised => spelling. */
	private function vocab() {
		global $wpdb;
		if ( null !== $this->vocab ) {
			return $this->vocab;
		}
		$s     = self::settings();
		$vocab = array();
		foreach ( array( $s['manual_key'], $s['smart_key'] ) as $key ) {
			$raws = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = 'property' AND pm.meta_value <> ''", $key ) );
			foreach ( (array) $raws as $raw ) {
				foreach ( self::split_values( $raw ) as $v ) {
					$n = self::normalise( $v );
					if ( '' !== $n && ! isset( $vocab[ $n ] ) ) {
						$vocab[ $n ] = $v;
					}
				}
			}
		}
		return $this->vocab = $vocab;
	}

	private function smart_is_empty( $property_id ) {
		return ! self::meta_values( $property_id, self::settings()['smart_key'] );
	}

	/** Extra custom fields (neighbourhood, quarter) that are enabled and still empty: OSM field => meta key. */
	private function empty_extra_fields( $property_id ) {
		$out = array();
		foreach ( self::extra_fields() as $f => $key ) {
			if ( ! self::meta_values( $property_id, $key ) ) {
				$out[ $f ] = $key;
			}
		}
		return $out;
	}

	private function needs_work( $property_id ) {
		return $this->smart_is_empty( $property_id ) || $this->empty_extra_fields( $property_id );
	}

	/* =====================================================================
	 * State (post meta) – deliberately tiny
	 * =================================================================== */

	public static function get_state( $pid ) {
		$state = get_post_meta( $pid, self::META_STATE, true );
		return is_array( $state ) ? $state : array();
	}

	private function set_state( $pid, $status, $state ) {
		update_post_meta( $pid, self::META_STATUS, $status );
		update_post_meta( $pid, self::META_STATE, array_filter( $state, function ( $v ) {
			return null !== $v && '' !== $v && array() !== $v;
		} ) );
	}

	private function clear_state( $pid ) {
		delete_post_meta( $pid, self::META_STATUS );
		delete_post_meta( $pid, self::META_STATE );
	}

	/** Re-queue properties whose status/state match $filter. Returns how many. */
	private function requeue( $filter ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_STATUS ) );
		$n    = 0;
		foreach ( (array) $rows as $row ) {
			if ( $filter( $row->meta_value, self::get_state( (int) $row->post_id ) ) ) {
				$this->clear_state( (int) $row->post_id );
				$n++;
			}
		}
		return $n;
	}

	/* =====================================================================
	 * Candidates
	 * =================================================================== */

	private function meta_empty_sql( $key ) {
		global $wpdb;
		return $wpdb->prepare( "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} em WHERE em.post_id = p.ID AND em.meta_key = %s AND em.meta_value <> '' AND em.meta_value <> 'a:0:{}')", $key );
	}

	private function needs_sql() {
		$parts = array( $this->meta_empty_sql( self::settings()['smart_key'] ) );
		foreach ( self::extra_fields() as $key ) {
			$parts[] = $this->meta_empty_sql( $key );
		}
		return '(' . implode( ' OR ', $parts ) . ')';
	}

	private function scope_sql() {
		global $wpdb;
		$s  = self::settings();
		$in = implode( ',', array_fill( 0, count( $s['statuses'] ), '%s' ) );
		return $wpdb->prepare( "p.post_type = 'property' AND p.post_status IN ({$in})", $s['statuses'] ); // phpcs:ignore
	}

	/** Not yet processed, or waiting for a retry after a temporary failure. */
	private function pending_sql() {
		global $wpdb;
		return $wpdb->prepare( "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} sm WHERE sm.post_id = p.ID AND sm.meta_key = %s AND sm.meta_value <> 'retry')", self::META_STATUS );
	}

	private function candidates( $limit, $only_new ) {
		global $wpdb;
		$sql = "SELECT p.ID FROM {$wpdb->posts} p WHERE " . $this->scope_sql() . ' AND ' . $this->needs_sql() . ' AND ' . $this->pending_sql();
		if ( $only_new ) {
			$sql .= $wpdb->prepare( ' AND p.post_date_gmt >= %s', get_option( 'phsl_activated_at', current_time( 'mysql', true ) ) );
		}
		$sql .= $wpdb->prepare( ' ORDER BY p.ID DESC LIMIT %d', $limit );
		return array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore
	}

	private function counts() {
		global $wpdb;
		$scope = $this->scope_sql();
		$out   = array(
			'total'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$scope}" ), // phpcs:ignore
			'missing_smart' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$scope} AND " . $this->meta_empty_sql( self::settings()['smart_key'] ) ), // phpcs:ignore
			'to_process'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$scope} AND " . $this->needs_sql() . ' AND ' . $this->pending_sql() ), // phpcs:ignore
		);
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS status, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value", self::META_STATUS ) ) as $row ) {
			$out[ 'status_' . $row->status ] = (int) $row->n;
		}
		return $out;
	}

	/**
	 * Manual vs smart suburb for properties in scope.
	 *
	 * @return array list of array( id, title, postcode, manual, smart, smart_state (saved|suggested|''), comparison, src, confidence, status )
	 */
	public function comparison_rows() {
		global $wpdb;
		$s   = self::settings();
		$ids = array_map( 'intval', $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p WHERE " . $this->scope_sql() . ' ORDER BY p.ID DESC' ) ); // phpcs:ignore
		$out = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			update_meta_cache( 'post', $chunk );
			foreach ( $chunk as $pid ) {
				$status = (string) get_post_meta( $pid, self::META_STATUS, true );
				$st     = self::get_state( $pid );
				$manual = self::meta_values( $pid, $s['manual_key'] );
				$smart  = implode( ', ', self::meta_values( $pid, $s['smart_key'] ) );
				$state  = '' !== $smart ? 'saved' : '';
				if ( '' === $smart && 'suggested' === $status && ! empty( $st['v']['n'] ) ) {
					$smart = (string) $st['v']['n'];
					$state = 'suggested';
				}
				$checked = '' !== $smart || ( '' !== $status && 'retry' !== $status );
				$conf    = array( 'h' => 'high', 'm' => 'medium', 'l' => 'low' );
				$out[]   = array(
					'id'          => $pid,
					'title'       => get_the_title( $pid ),
					'postcode'    => (string) get_post_meta( $pid, '_address_postcode', true ),
					'manual'      => implode( ', ', $manual ),
					'smart'       => $smart,
					'smart_state' => $state,
					'comparison'  => self::compare( $manual, $smart, $checked ),
					'src'         => (string) ( $st['src'] ?? '' ),
					'confidence'  => $conf[ $st['cf'] ?? '' ] ?? '',
					'status'      => $status,
				);
			}
		}
		return $out;
	}

	/* =====================================================================
	 * Triggers: cron and imports
	 * =================================================================== */

	public function cron_run() {
		if ( ! self::settings()['auto_new'] || ! $this->acquire_lock() ) {
			return;
		}
		$started = time();
		try {
			foreach ( $this->candidates( 20, true ) as $pid ) {
				$r = $this->process_property( $pid );
				if ( ! empty( $r['halt'] ) || time() - $started > 45 ) {
					break;
				}
			}
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Runs straight after an import add-on has saved a property. Cheap checks first, and
	 * limited per import run; anything left over is picked up by the scheduled check.
	 */
	public function on_imported( $post_id ) {
		static $seen = array(), $done = 0, $started = 0, $deferred = 0, $halted = false;

		$post_id = (int) $post_id;
		$s       = self::settings();
		if ( ! $s['on_import'] || $halted || isset( $seen[ $post_id ] ) || 'property' !== get_post_type( $post_id ) ) {
			return;
		}
		$seen[ $post_id ] = true;

		$status = get_post_meta( $post_id, self::META_STATUS, true );
		if ( ( $status && 'retry' !== $status ) || ! in_array( get_post_status( $post_id ), $s['statuses'], true ) ) {
			return;
		}
		if ( get_post_field( 'post_date_gmt', $post_id ) < get_option( 'phsl_activated_at', '' ) ) {
			return; // existing properties are processed from the Overview tab
		}
		if ( ! $this->needs_work( $post_id ) ) {
			return;
		}

		$started = $started ? $started : time();
		$limits  = (array) apply_filters( 'phsl_import_limits', array( 'properties' => 20, 'seconds' => 60 ) );
		if ( $done >= $limits['properties'] || time() - $started > $limits['seconds'] ) {
			if ( 0 === $deferred++ ) {
				$this->log( 'info', 'Import limit reached for this run; remaining imported properties will be handled by the scheduled check.' );
			}
			return;
		}
		if ( ! $this->acquire_lock() ) {
			return;
		}
		try {
			$done++;
			$r = $this->process_property( $post_id );
			if ( ! empty( $r['halt'] ) ) {
				$halted = true;
			}
		} finally {
			$this->release_lock();
		}
	}

	/* =====================================================================
	 * Processing
	 * =================================================================== */

	/**
	 * Work out (and, in assign mode, save) the smart suburb – and any enabled neighbourhood /
	 * quarter fields – for one property. Only empty fields are filled.
	 *
	 * @param bool $dry_run Save and record nothing (test tool).
	 * @return array Result (verbose; only ever logged, never stored on the property).
	 */
	public function process_property( $pid, $dry_run = false ) {
		$s        = self::settings();
		$postcode = (string) get_post_meta( $pid, '_address_postcode', true );
		$prev     = self::get_state( $pid );
		$r        = array(
			'property_id' => $pid,
			'status'      => '',
			'suburb'      => '',
			'src'         => '',
			'cf'          => '',
			'extras'      => array(),
			'o'           => null,
			'a'           => null,
			'n'           => (int) ( $prev['n'] ?? 0 ),
			'notes'       => array(),
			'context'     => array(),
		);

		$need_suburb  = $this->smart_is_empty( $pid );
		$extra_fields = $this->empty_extra_fields( $pid );
		if ( ! $need_suburb && ! $extra_fields ) {
			$r['status']  = 'skipped';
			$r['notes'][] = 'nothing to fill in';
			return $this->finish( $r, $dry_run );
		}

		/* 1. OpenStreetMap */
		$osm = $this->osm_lookup( $pid );
		if ( is_wp_error( $osm ) ) {
			$code         = $osm->get_error_code();
			$r['notes'][] = 'OSM: ' . $osm->get_error_message();
			if ( in_array( $code, array( 'rate_limited', 'blocked' ), true ) ) {
				$r['status'] = 'retry';
				$r['halt']   = true;
				return $this->finish( $r, $dry_run );
			}
			$r['o'] = in_array( $code, array( 'no_location', 'not_found' ), true ) ? 0 : 'x';
			if ( 'x' === $r['o'] && ( ! $need_suburb || ! self::claude_enabled() ) ) {
				$r['status'] = 'retry';
				return $this->finish( $r, $dry_run );
			}
			$osm = array( 'address' => array(), 'source' => 'none' );
		}
		$address                  = $osm['address'];
		$r['context']['osm']      = array_intersect_key( $address, array_flip( self::$osm_context_fields ) );
		$r['context']['osm_from'] = $osm['source'];
		if ( '' === $postcode && ! empty( $address['postcode'] ) ) {
			$postcode = (string) $address['postcode'];
		}

		foreach ( $extra_fields as $f => $key ) {
			if ( ! empty( $address[ $f ] ) && is_string( $address[ $f ] ) ) {
				$r['extras'][ $f ] = trim( $address[ $f ] );
			}
		}

		if ( $need_suburb ) {
			if ( ! empty( $address['suburb'] ) && is_string( $address['suburb'] ) ) {
				$r['suburb'] = trim( $address['suburb'] );
				$r['src']    = 'osm:suburb';
			}
			if ( null === $r['o'] ) {
				$r['o'] = '' !== $r['suburb'] ? 1 : 0;
			}

			/* 2. Claude, when OpenStreetMap has no suburb */
			if ( '' === $r['suburb'] && $s['use_claude'] ) {
				if ( ! self::claude_enabled() ) {
					$r['a']       = 'nokey';
					$r['notes'][] = 'Claude skipped: no API key';
				} else {
					$c = $this->claude_lookup( $pid, $postcode, $osm );
					if ( is_wp_error( $c ) ) {
						$r['a']       = 'error';
						$r['notes'][] = 'Claude: ' . $c->get_error_message();
						if ( 'claude_auth' === $c->get_error_code() ) {
							$r['status'] = 'retry';
							$r['halt']   = true;
							return $this->finish( $r, $dry_run );
						}
					} else {
						$r['context']['claude'] = $c;
						$ranks     = array( 'low' => 1, 'medium' => 2, 'high' => 3 );
						$confident = ( $ranks[ $c['confidence'] ] ?? 0 ) >= $ranks[ $s['claude_min_confidence'] ];
						$r['a']    = $confident ? 'used' : 'unsure';
						$r['cf']   = substr( $c['confidence'], 0, 1 );
						if ( '' !== $c['suburb'] ) {
							if ( $confident ) {
								$r['suburb'] = $c['suburb'];
								$r['src']    = 'claude';
							} else {
								$r['notes'][] = sprintf( 'Claude suggested "%s" with low confidence (not used)', $c['suburb'] );
							}
						}
					}
				}
			}

			/* 3. Use the spelling already used on the site, if any */
			if ( '' !== $r['suburb'] ) {
				$vocab = $this->vocab();
				$n     = self::normalise( $r['suburb'] );
				if ( isset( $vocab[ $n ] ) ) {
					$r['suburb'] = $vocab[ $n ];
				}
			}
		}

		if ( '' === $r['suburb'] && ! $r['extras'] ) {
			// A temporary Claude failure is worth retrying; anything else is final.
			$r['status'] = 'error' === $r['a'] ? 'retry' : 'not_found';
			return $this->finish( $r, $dry_run );
		}

		$r['status'] = 'assign' === $s['mode'] ? 'assigned' : 'suggested';
		if ( ! $dry_run && 'assigned' === $r['status'] ) {
			$this->save_values( $pid, $r );
		}
		return $this->finish( $r, $dry_run );
	}

	/** Save values – only into empty fields, so manual edits are never overwritten. */
	private function save_values( $pid, $r ) {
		$s = self::settings();
		if ( '' !== $r['suburb'] && $this->smart_is_empty( $pid ) ) {
			update_post_meta( $pid, $s['smart_key'], $r['suburb'] );
			$this->vocab = null;
		}
		foreach ( self::extra_fields() as $f => $key ) {
			if ( ! empty( $r['extras'][ $f ] ) && ! self::meta_values( $pid, $key ) ) {
				update_post_meta( $pid, $key, $r['extras'][ $f ] );
			}
		}
		do_action( 'phsl_values_assigned', $pid, $r );
	}

	/** Store the tiny state, write the verbose details to the log file. */
	private function finish( $r, $dry_run ) {
		$pid = $r['property_id'];

		if ( 'retry' === $r['status'] && empty( $r['halt'] ) ) {
			$r['n']++;
			if ( $r['n'] >= 3 ) {
				$r['status'] = 'error';
			}
		}

		$parts = array();
		if ( '' !== $r['suburb'] ) {
			$parts[] = sprintf( 'smart suburb "%s" from %s', $r['suburb'], 'claude' === $r['src'] ? 'Claude' : 'OSM' );
		}
		foreach ( $r['extras'] as $f => $v ) {
			$parts[] = sprintf( '%s "%s"', $f, $v );
		}
		$manual = self::meta_values( $pid, self::settings()['manual_key'] );
		if ( '' !== $r['suburb'] ) {
			$parts[] = sprintf( 'manual suburb "%s" → %s', implode( ', ', $manual ), self::compare( $manual, $r['suburb'], true ) );
		}
		$r['comparison'] = self::compare( $manual, $r['suburb'], true );
		$labels          = array( 'not_found' => 'Nothing found' );
		$r['message']    = ( $labels[ $r['status'] ] ?? ucfirst( $r['status'] ) ) . ( $parts ? ': ' . implode( '; ', $parts ) : '' ) . ( $r['notes'] ? ' – ' . implode( '; ', $r['notes'] ) : '' );
		if ( 'retry' === $r['status'] && empty( $r['halt'] ) ) {
			$r['message'] .= sprintf( ' (will retry, attempt %d of 3)', $r['n'] );
		}

		if ( $dry_run ) {
			return $r;
		}

		if ( ! empty( $r['halt'] ) ) {
			$this->log( 'error', 'Stopped: ' . $r['message'], $pid, $r['context'] );
			return $r; // leave the property queued as it was
		}

		$state = array(
			't'   => time(),
			'o'   => $r['o'],
			'a'   => $r['a'],
			'src' => $r['src'],
			'cf'  => $r['cf'],
			'n'   => 'retry' === $r['status'] ? $r['n'] : null,
		);
		if ( 'suggested' === $r['status'] ) {
			$state['v'] = array_filter(
				array(
					'n'  => $r['suburb'],
					'nb' => $r['extras']['neighbourhood'] ?? '',
					'q'  => $r['extras']['quarter'] ?? '',
				)
			);
		}
		$this->set_state( $pid, $r['status'], $state );

		$level = array( 'error' => 'error', 'retry' => 'warning', 'not_found' => 'warning' )[ $r['status'] ] ?? 'info';
		$this->log( $level, $r['message'], $pid, $r['context'] );
		return $r;
	}

	/* =====================================================================
	 * OpenStreetMap
	 * =================================================================== */

	private function address_query( $pid ) {
		$parts = array();
		foreach ( array( '_address_name_number', '_address_street', '_address_two', '_address_three', '_address_four', '_address_postcode' ) as $key ) {
			$v = trim( (string) get_post_meta( $pid, $key, true ) );
			if ( '' !== $v ) {
				$parts[] = $v;
			}
		}
		return implode( ', ', $parts );
	}

	private function osm_lookup( $pid ) {
		$s   = self::settings();
		$lat = self::parse_coord( get_post_meta( $pid, '_latitude', true ) );
		$lng = self::parse_coord( get_post_meta( $pid, '_longitude', true ) );

		if ( null !== $lat && null !== $lng && ( 0.0 !== $lat || 0.0 !== $lng ) && abs( $lat ) <= 90 && abs( $lng ) <= 180 ) {
			$data = $this->nominatim( 'reverse', array( 'lat' => $lat, 'lon' => $lng, 'zoom' => 18 ) );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$address = ( empty( $data['error'] ) && isset( $data['address'] ) && is_array( $data['address'] ) ) ? $data['address'] : array();
			return array( 'address' => $address, 'source' => 'coordinates', 'lat' => $lat, 'lng' => $lng );
		}

		$query = $this->address_query( $pid );
		if ( ! $s['address_fallback'] || '' === $query ) {
			return new WP_Error( 'no_location', 'no latitude/longitude' . ( $s['address_fallback'] ? ' or address' : '' ) );
		}
		$params  = array( 'q' => $query, 'limit' => 1 );
		$country = get_post_meta( $pid, '_address_country', true );
		if ( $country && 2 === strlen( $country ) ) {
			$params['countrycodes'] = strtolower( $country );
		}
		$data = $this->nominatim( 'search', $params );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data[0] ) ) {
			return new WP_Error( 'not_found', 'address not found: ' . $query );
		}
		return array(
			'address' => isset( $data[0]['address'] ) && is_array( $data[0]['address'] ) ? $data[0]['address'] : array(),
			'source'  => 'address',
			'lat'     => isset( $data[0]['lat'] ) ? (float) $data[0]['lat'] : null,
			'lng'     => isset( $data[0]['lon'] ) ? (float) $data[0]['lon'] : null,
		);
	}

	private function nominatim( $endpoint, $params ) {
		$s      = self::settings();
		$params = array_merge( array( 'format' => 'jsonv2', 'addressdetails' => 1, 'accept-language' => $s['language'] ), $params );
		if ( $s['email'] ) {
			$params['email'] = $s['email'];
		}
		$url = add_query_arg( array_map( 'rawurlencode', $params ), $s['endpoint'] . '/' . $endpoint );

		$this->throttle( $s['delay'] );
		$this->last_osm = array( 'url' => $url );

		$response = wp_remote_get( $url, array( 'timeout' => 20, 'user-agent' => 'PropertyHive-Smart-Location/' . self::VERSION . ' (' . home_url() . ')', 'headers' => array( 'Referer' => home_url() ) ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'osm_request_failed', 'request failed: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$this->last_osm['http_code'] = $code;
		$snippet = trim( mb_substr( wp_strip_all_tags( $body ), 0, 200 ) );

		if ( 429 === $code ) {
			return new WP_Error( 'rate_limited', 'rate limit (HTTP 429) ' . $snippet );
		}
		if ( 403 === $code || 418 === $code ) {
			return new WP_Error( 'blocked', 'access refused (HTTP ' . $code . ') ' . $snippet );
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'osm_http_' . $code, 'HTTP ' . $code . ' ' . $snippet );
		}
		$data = json_decode( $body, true );
		return null === $data ? new WP_Error( 'osm_bad_json', 'invalid response ' . $snippet ) : $data;
	}

	private function throttle( $delay ) {
		global $wpdb;
		$last = (float) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'phsl_last_osm_request' ) );
		$wait = $last + $delay - microtime( true );
		if ( $wait > 0 ) {
			usleep( (int) ceil( min( $wait, 10 ) * 1000000 ) );
		}
		update_option( 'phsl_last_osm_request', (string) microtime( true ), false );
	}

	/* =====================================================================
	 * Claude
	 * =================================================================== */

	/**
	 * Ask Claude for the suburb.
	 *
	 * @return array|WP_Error array( suburb, confidence, reason, model, input_tokens, output_tokens )
	 */
	private function claude_lookup( $pid, $postcode, $osm ) {
		$s = self::settings();

		$lines = array( 'Address: ' . $this->address_query( $pid ) );
		if ( $postcode ) {
			$lines[] = 'Postcode: ' . $postcode;
		}
		$lat = self::parse_coord( get_post_meta( $pid, '_latitude', true ) );
		$lng = self::parse_coord( get_post_meta( $pid, '_longitude', true ) );
		if ( ! $lat && ! empty( $osm['lat'] ) ) {
			$lat = $osm['lat'];
			$lng = $osm['lng'];
		}
		if ( $lat && $lng ) {
			$lines[] = sprintf( 'Coordinates: %s, %s', $lat, $lng );
		}
		$areas = array();
		foreach ( self::$osm_context_fields as $f ) {
			if ( ! empty( $osm['address'][ $f ] ) && is_string( $osm['address'][ $f ] ) ) {
				$areas[] = $f . '=' . $osm['address'][ $f ];
			}
		}
		$lines[] = 'OpenStreetMap areas at this location: ' . ( $areas ? implode( ', ', $areas ) : 'none' );
		$tasks   = array();
		$tasks[] = 'Identify the suburb: the locally recognised district or area name an estate agent would use for this address (for example "Edgbaston" or "Kings Norton"). Do not give the city, county, a building, a road or a housing development name. OpenStreetMap data may be missing or wrong; use your own knowledge of the area. If you are not reasonably sure, return an empty suburb.';
		$properties = array(
			'suburb'     => array( 'type' => 'string' ),
			'confidence' => array( 'type' => 'string', 'enum' => array( 'high', 'medium', 'low' ) ),
			'reason'     => array( 'type' => 'string' ),
		);
		$tasks[] = 'Give your confidence and a one-sentence reason.';

		$body = array(
			'model'         => $s['claude_model'],
			'max_tokens'    => 4000,
			'output_config' => array(
				'effort' => 'low',
				'format' => array(
					'type'   => 'json_schema',
					'schema' => array(
						'type'                 => 'object',
						'properties'           => $properties,
						'required'             => array( 'suburb', 'confidence', 'reason' ),
						'additionalProperties' => false,
					),
				),
			),
			'messages'      => array(
				array(
					'role'    => 'user',
					'content' => "You are helping a UK estate agent fill in location details for a property listing.\n\n" . implode( "\n", $lines ) . "\n\n" . implode( "\n\n", $tasks ),
				),
			),
		);

		// On models that support it, a declined request is retried on a fallback model server-side.
		$fallbacks = 'claude-haiku-5-5' !== $s['claude_model'];
		$response  = $this->claude_request( $body, $fallbacks );
		if ( is_wp_error( $response ) && $fallbacks && 'claude_http_400' === $response->get_error_code() ) {
			$response = $this->claude_request( $body, false );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 'refusal' === ( $response['stop_reason'] ?? '' ) ) {
			return new WP_Error( 'claude_refusal', 'declined the request' );
		}
		$text = '';
		foreach ( (array) ( $response['content'] ?? array() ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= $block['text'];
			}
		}
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'claude_bad_output', 'unexpected answer (stop reason: ' . ( $response['stop_reason'] ?? '?' ) . ')' );
		}
		return array(
			'suburb'        => trim( (string) ( $data['suburb'] ?? '' ) ),
			'confidence'    => in_array( $data['confidence'] ?? '', array( 'high', 'medium', 'low' ), true ) ? $data['confidence'] : 'low',
			'reason'        => trim( (string) ( $data['reason'] ?? '' ) ),
			'model'         => $response['model'] ?? $s['claude_model'],
			'input_tokens'  => (int) ( $response['usage']['input_tokens'] ?? 0 ),
			'output_tokens' => (int) ( $response['usage']['output_tokens'] ?? 0 ),
		);
	}

	/**
	 * General structured request to Claude, returning decoded JSON matching $schema.
	 * Used by the Location organiser.
	 *
	 * @return array|WP_Error array( data, model, input_tokens, output_tokens )
	 */
	public function claude_json( $prompt, $schema, $max_tokens = 4000, $effort = 'low', $timeout = 90 ) {
		if ( ! self::claude_enabled() ) {
			return new WP_Error( 'claude_disabled', 'Claude is not enabled or there is no API key (Settings → Claude).' );
		}
		$s    = self::settings();
		$body = array(
			'model'         => $s['claude_model'],
			'max_tokens'    => (int) $max_tokens,
			'output_config' => array(
				'effort' => $effort,
				'format' => array( 'type' => 'json_schema', 'schema' => $schema ),
			),
			'messages'      => array( array( 'role' => 'user', 'content' => $prompt ) ),
		);
		$fallbacks = 'claude-haiku-5-5' !== $s['claude_model'];
		$response  = $this->claude_request( $body, $fallbacks, $timeout );
		if ( is_wp_error( $response ) && $fallbacks && 'claude_http_400' === $response->get_error_code() ) {
			$response = $this->claude_request( $body, false, $timeout );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$stop = $response['stop_reason'] ?? '';
		if ( 'refusal' === $stop ) {
			return new WP_Error( 'claude_refusal', 'Claude declined the request.' );
		}
		if ( 'max_tokens' === $stop ) {
			return new WP_Error( 'claude_too_long', 'Claude’s answer was cut off because it was too long.' );
		}
		$text = '';
		foreach ( (array) ( $response['content'] ?? array() ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= $block['text'];
			}
		}
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'claude_bad_output', 'Unexpected answer from Claude (stop reason: ' . $stop . ').' );
		}
		return array(
			'data'          => $data,
			'model'         => $response['model'] ?? $s['claude_model'],
			'input_tokens'  => (int) ( $response['usage']['input_tokens'] ?? 0 ),
			'output_tokens' => (int) ( $response['usage']['output_tokens'] ?? 0 ),
		);
	}

	private function claude_request( $body, $use_fallbacks, $timeout = 90 ) {
		$headers = array(
			'content-type'      => 'application/json',
			'x-api-key'         => self::api_key(),
			'anthropic-version' => self::API_VERSION,
		);
		if ( $use_fallbacks ) {
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
			$body['fallbacks']         = 'default';
		}
		$response = wp_remote_post( self::ANTHROPIC_URL, array( 'timeout' => $timeout, 'headers' => $headers, 'body' => wp_json_encode( $body ) ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'claude_request_failed', 'could not reach the API: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 === $code && is_array( $data ) ) {
			return $data;
		}
		$message = is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : mb_substr( (string) wp_remote_retrieve_body( $response ), 0, 200 );
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'claude_auth', 'API key rejected (HTTP ' . $code . '): ' . $message );
		}
		if ( 402 === $code || ( 400 === $code && false !== stripos( $message, 'credit' ) ) ) {
			return new WP_Error( 'claude_auth', 'out of API credit: ' . $message );
		}
		return new WP_Error( 'claude_http_' . $code, 'HTTP ' . $code . ': ' . $message );
	}

	/* =====================================================================
	 * Locking
	 * =================================================================== */

	private function acquire_lock() {
		if ( add_option( self::LOCK_OPTION, time(), '', 'no' ) ) {
			return true;
		}
		$since = (int) get_option( self::LOCK_OPTION );
		if ( $since && $since < time() - 180 ) {
			delete_option( self::LOCK_OPTION );
			return add_option( self::LOCK_OPTION, time(), '', 'no' );
		}
		return false;
	}

	private function release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/* =====================================================================
	 * AJAX
	 * =================================================================== */

	public function ajax_step() {
		$this->check_ajax();
		if ( ! $this->acquire_lock() ) {
			wp_send_json_success( array( 'busy' => true, 'message' => 'Another process is running (an import, the scheduled check or another tab). Waiting…' ) );
		}
		try {
			$ids = $this->candidates( 1, false );
			if ( ! $ids ) {
				wp_send_json_success( array( 'done' => true, 'counts' => $this->counts() ) );
			}
			$r = $this->process_property( $ids[0] );
		} finally {
			$this->release_lock();
		}
		wp_send_json_success(
			array(
				'property_id' => $ids[0],
				'title'       => get_the_title( $ids[0] ),
				'status'      => $r['status'],
				'message'     => $r['message'],
				'halt'        => ! empty( $r['halt'] ),
				'counts'      => $this->counts(),
			)
		);
	}

	public function ajax_test() {
		$this->check_ajax();
		$pid = absint( $_POST['property_id'] ?? 0 );
		if ( 'property' !== get_post_type( $pid ) ) {
			wp_send_json_error( array( 'message' => 'Not a property ID.' ) );
		}
		$s = self::settings();
		$r = $this->process_property( $pid, true );
		wp_send_json_success(
			array(
				'property'      => get_the_title( $pid ),
				'manual_suburb' => implode( ', ', self::meta_values( $pid, $s['manual_key'] ) ),
				'smart_suburb'  => implode( ', ', self::meta_values( $pid, $s['smart_key'] ) ),
				'note'          => 'Test only: nothing was saved or recorded. A property whose smart suburb (and any enabled extra fields) are already filled is skipped.',
				'result'        => $r,
				'osm_request'   => $this->last_osm,
			)
		);
	}

	/* =====================================================================
	 * admin-post actions
	 * =================================================================== */

	/** Save a suggestion (only into empty fields). */
	private function apply_one( $pid ) {
		if ( 'suggested' !== get_post_meta( $pid, self::META_STATUS, true ) ) {
			return false;
		}
		$state = self::get_state( $pid );
		$v     = (array) ( $state['v'] ?? array() );
		$r     = array(
			'suburb' => (string) ( $v['n'] ?? '' ),
			'extras' => array_filter( array( 'neighbourhood' => $v['nb'] ?? '', 'quarter' => $v['q'] ?? '' ) ),
			'src'    => (string) ( $state['src'] ?? '' ),
		);
		$this->save_values( $pid, $r );
		unset( $state['v'] );
		$this->set_state( $pid, 'assigned', $state );
		$this->log( 'info', sprintf( 'Assigned (applied by %s): smart suburb "%s"', wp_get_current_user()->user_login, $r['suburb'] ), $pid );
		return true;
	}

	public function handle_apply() {
		$this->check_admin_request( 'phsl_apply' );
		$ok = $this->apply_one( absint( $_POST['property_id'] ?? 0 ) );
		$this->redirect( 'results', $ok ? 'applied' : 'apply_failed' );
	}

	public function handle_apply_all() {
		global $wpdb;
		$this->check_admin_request( 'phsl_apply_all' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'suggested'", self::META_STATUS ) );
		$n   = 0;
		foreach ( $ids as $pid ) {
			$n += $this->apply_one( (int) $pid ) ? 1 : 0;
		}
		$this->log( 'info', sprintf( 'Applied %d suggestions', $n ) );
		$this->redirect( 'results', 'applied' );
	}

	public function handle_retry() {
		$this->check_admin_request( 'phsl_retry' );
		$pid = absint( $_POST['property_id'] ?? 0 );
		$this->clear_state( $pid );
		$this->log( 'info', 'Re-queued by ' . wp_get_current_user()->user_login, $pid );
		$this->redirect( 'results', 'retry' );
	}

	public function handle_requeue() {
		$this->check_admin_request( 'phsl_requeue' );
		$which   = sanitize_key( $_POST['which'] ?? '' );
		$filters = array(
			// Nothing found, but Claude never gave an answer (not configured, or failed); Claude-unsure ones are left alone.
			'not_found' => function ( $status, $state ) {
				return 'not_found' === $status && 'unsure' !== ( $state['a'] ?? '' );
			},
			'errors'    => function ( $status ) {
				return 'error' === $status;
			},
			'all'       => function () {
				return true;
			},
		);
		if ( isset( $filters[ $which ] ) ) {
			$n = $this->requeue( $filters[ $which ] );
			$this->log( 'info', sprintf( 'Re-queued %d properties (%s) by %s', $n, $which, wp_get_current_user()->user_login ) );
		}
		$this->redirect( 'run', 'requeued' );
	}

	/** CSV: manual suburb, smart suburb and the comparison side by side. */
	public function handle_export() {
		$this->check_admin_request( 'phsl_export' );
		$only = sanitize_text_field( wp_unslash( $_POST['comparison'] ?? '' ) );
		$rows = $this->comparison_rows();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=smart-suburb-comparison-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel shows names like "King’s Norton" correctly
		$header = array( 'Property ID', 'Property', 'Postcode', 'Manual suburb', 'Smart suburb', 'Comparison', 'Smart suburb status', 'Smart suburb source', 'Claude confidence', 'Lookup status' );
		$extras = self::extra_fields();
		foreach ( $extras as $f => $key ) {
			$header[] = ucfirst( $f );
		}
		fputcsv( $out, $header );

		$sources = array( 'osm:suburb' => 'OpenStreetMap', 'claude' => 'Claude' );
		$status  = array( 'not_found' => 'nothing found' );
		foreach ( $rows as $row ) {
			if ( '' !== $only && $row['comparison'] !== $only ) {
				continue;
			}
			$line = array(
				$row['id'],
				$row['title'],
				$row['postcode'],
				$row['manual'],
				$row['smart'],
				$row['comparison'],
				$row['smart_state'],
				$sources[ $row['src'] ] ?? $row['src'],
				$row['confidence'],
				$status[ $row['status'] ] ?? $row['status'],
			);
			foreach ( $extras as $key ) {
				$line[] = implode( ', ', self::meta_values( $row['id'], $key ) );
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

	public function handle_clear_log() {
		$this->check_admin_request( 'phsl_clear_log' );
		PHSL_Logger::delete_all();
		$this->redirect( 'log', 'log_cleared' );
	}

	public function handle_download_log() {
		$this->check_admin_request( 'phsl_download_log' );
		$month = sanitize_text_field( wp_unslash( $_POST['month'] ?? '' ) );
		$files = PHSL_Logger::files();
		if ( ! isset( $files[ $month ] ) ) {
			wp_die( 'Log not found' );
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=smart-location-' . $month . '.log' );
		readfile( $files[ $month ] ); // phpcs:ignore
		exit;
	}

	/* =====================================================================
	 * Admin pages
	 * =================================================================== */

	private static function capability() {
		return apply_filters( 'phsl_capability', 'manage_propertyhive' );
	}

	public function user_can() {
		return current_user_can( self::capability() ) || current_user_can( 'manage_options' );
	}

	public function admin_menu() {
		$ph   = class_exists( 'PropertyHive' );
		$hook = add_submenu_page( $ph ? 'propertyhive' : 'tools.php', 'Smart Location', 'Smart Location', $ph ? self::capability() : 'manage_options', self::PAGE_SLUG, array( $this, 'render_page' ) );
		add_action( 'admin_print_scripts-' . $hook, array( $this, 'enqueue' ) );
	}

	public function enqueue() {
		$url = plugin_dir_url( __FILE__ );
		wp_enqueue_style( 'phsl-admin', $url . 'assets/admin.css', array(), self::VERSION );
		wp_enqueue_script( 'phsl-admin', $url . 'assets/admin.js', array( 'jquery' ), self::VERSION, true );
		wp_localize_script( 'phsl-admin', 'phsl', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( self::NONCE ) ) );
	}

	public static function page_url( $tab = 'run', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG, 'tab' => $tab ), $args ), admin_url( class_exists( 'PropertyHive' ) ? 'admin.php' : 'tools.php' ) );
	}

	public function redirect( $tab, $msg ) {
		wp_safe_redirect( self::page_url( $tab, array( 'phsl_msg' => $msg ) ) );
		exit;
	}

	public function post_button( $action, $label, $fields = array(), $class = 'button', $confirm = '' ) {
		printf( '<form method="post" action="%s" class="phsl-inline"%s>', esc_url( admin_url( 'admin-post.php' ) ), $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\');"' : '' );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		wp_nonce_field( $action );
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	public function render_page() {
		if ( ! $this->user_can() ) {
			wp_die( 'Sorry, you are not allowed to access this page.' );
		}
		$tabs = array( 'run' => 'Overview', 'results' => 'Results', 'organise' => 'Organise Locations', 'log' => 'Log', 'settings' => 'Settings' );
		$tab  = sanitize_key( $_GET['tab'] ?? 'run' );
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'run';
		$msgs = array(
			'settings_saved' => 'Settings saved.',
			'applied'        => 'Suggestions applied.',
			'retry'          => 'Property queued to be processed again.',
			'requeued'       => 'Properties re-queued.',
			'log_cleared'    => 'Log files deleted.',
			'organised'      => 'Location changes applied. Use “Undo last apply” to reverse them.',
			'organise_undone' => 'Last organise undone.',
			'organise_discarded' => 'Proposal discarded.',
			'organise_nothing' => 'Nothing was selected.',
			'apply_failed'   => 'Could not apply – see the Log for the reason.',
		);
		echo '<div class="wrap phsl-wrap"><h1>Smart Location</h1>';
		$msg = sanitize_key( $_GET['phsl_msg'] ?? '' );
		if ( isset( $msgs[ $msg ] ) ) {
			echo '<div class="notice ' . ( 'apply_failed' === $msg ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $msgs[ $msg ] ) . '</p></div>';
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::page_url( $k ) ), $k === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';
		if ( 'organise' === $tab ) {
			$this->organiser->render();
		} else {
			call_user_func( array( $this, 'render_' . $tab ) );
		}
		echo '</div>';
	}

	private function render_run() {
		$s      = self::settings();
		$c      = $this->counts();
		$extras = self::extra_fields();
		$when   = array();
		if ( $s['on_import'] ) {
			$when[] = 'straight after each import';
		}
		if ( $s['auto_new'] ) {
			$when[] = 'every 10 minutes';
		}
		$rows   = $this->comparison_rows();
		$counts = array_fill_keys( array_keys( self::$comparisons ), 0 );
		foreach ( $rows as $row ) {
			$counts[ $row['comparison'] ]++;
		}
		$both = $counts['match'] + $counts['near match'] + $counts['partial match'] + $counts['mismatch'];
		?>
		<div class="phsl-card">
			<h2>Status</h2>
			<p><strong>Mode:</strong>
				<?php if ( 'assign' === $s['mode'] ) : ?>
					<span class="phsl-badge phsl-assigned">Assign automatically</span>: the smart suburb is saved to <code><?php echo esc_html( $s['smart_key'] ); ?></code>.
				<?php else : ?>
					<span class="phsl-badge phsl-suggested">Suggest only</span>: nothing is saved; review suggestions on the Results tab.
				<?php endif; ?>
			</p>
			<p><strong>Smart suburb:</strong> OpenStreetMap suburb
				<?php if ( self::claude_enabled() ) : ?>, then <strong><?php echo esc_html( self::$models[ $s['claude_model'] ] ); ?></strong>.
				<?php elseif ( $s['use_claude'] ) : ?>. <span class="phsl-warn">Claude is enabled but there is no API key.</span>
				<?php else : ?>. Claude is off.<?php endif; ?>
				Only filled when empty, so a smart suburb edited by hand is kept.
			</p>
			<p><strong>Other fields:</strong> <?php echo $extras ? esc_html( implode( ', ', array_map( function ( $f, $k ) { return $f . ' → ' . $k; }, array_keys( $extras ), $extras ) ) ) . ' (from OpenStreetMap, only when empty)' : 'none'; ?></p>
			<p><strong>New properties:</strong> <?php echo esc_html( $when ? 'processed ' . implode( ' and ', $when ) : 'not processed automatically' ); ?>.</p>
			<table class="widefat phsl-counts"><tbody>
				<tr><td>Properties in scope</td><td class="num"><?php echo esc_html( number_format_i18n( $c['total'] ) ); ?></td></tr>
				<tr><td>Without a smart suburb</td><td class="num"><?php echo esc_html( number_format_i18n( $c['missing_smart'] ) ); ?></td></tr>
				<tr><td>Waiting to be processed</td><td class="num" id="phsl-to-process"><?php echo esc_html( number_format_i18n( $c['to_process'] ) ); ?></td></tr>
				<?php foreach ( array( 'suggested' => 'Suggestions awaiting review', 'assigned' => 'Assigned', 'not_found' => 'Nothing found', 'retry' => 'Waiting to retry', 'error' => 'Errors' ) as $k => $label ) : ?>
					<tr><td><a href="<?php echo esc_url( self::page_url( 'results', array( 'status' => $k ) ) ); ?>"><?php echo esc_html( $label ); ?></a></td><td class="num"><?php echo esc_html( number_format_i18n( $c[ 'status_' . $k ] ?? 0 ) ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
		</div>

		<div class="phsl-card">
			<h2>Manual vs smart suburb</h2>
			<p class="description">Compares the manually entered suburb (<code><?php echo esc_html( $s['manual_key'] ); ?></code>) with the smart suburb (<code><?php echo esc_html( $s['smart_key'] ); ?></code>, or the pending suggestion in Suggest mode).</p>
			<table class="widefat striped phsl-compare">
				<thead><tr><th>Comparison</th><th class="num">Properties</th><th class="num">% of both filled</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( self::$comparisons as $key => $desc ) : ?>
					<tr>
						<td><span class="phsl-badge phsl-cmp-<?php echo esc_attr( str_replace( ' ', '-', $key ) ); ?>"><?php echo esc_html( $key ); ?></span><br><small><?php echo esc_html( $desc ); ?></small></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $counts[ $key ] ) ); ?></td>
						<td class="num"><?php echo in_array( $key, array( 'match', 'near match', 'partial match', 'mismatch' ), true ) && $both ? esc_html( number_format_i18n( 100 * $counts[ $key ] / $both, 1 ) . '%' ) : ''; ?></td>
						<td><?php echo $counts[ $key ] ? '<a href="' . esc_url( self::page_url( 'results', array( 'comparison' => $key ) ) ) . '">View</a>' : ''; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<?php $this->post_button( 'phsl_export', 'Export all (CSV)', array(), 'button button-primary' ); ?>
				<?php $this->post_button( 'phsl_export', 'Export mismatches only', array( 'comparison' => 'mismatch' ) ); ?>
			</p>
			<p class="description">The CSV has the manual suburb, smart suburb and comparison side by side, plus where the smart suburb came from and Claude’s confidence.</p>
		</div>

		<div class="phsl-card">
			<h2>Process existing properties</h2>
			<p class="description">Works through properties without a smart suburb<?php echo $extras ? ' or with an empty ' . esc_html( implode( '/', array_keys( $extras ) ) ) . ' field' : ''; ?>, newest first, within OpenStreetMap’s limit of one request per second. Stop and resume at any time.</p>
			<div class="phsl-controls">
				<label>Run for <input type="number" id="phsl-minutes" min="1" max="240" value="10" class="small-text"> minutes</label>
				<label>or at most <input type="number" id="phsl-max" min="1" value="100" class="small-text"> properties</label>
				<button type="button" class="button button-primary" id="phsl-start" <?php disabled( 0 === $c['to_process'] ); ?>>Start</button>
				<button type="button" class="button" id="phsl-stop" disabled>Stop</button>
			</div>
			<div class="phsl-progress" hidden>
				<div class="phsl-bar"><span></span></div>
				<p id="phsl-status"></p>
				<ol id="phsl-live" class="phsl-live"></ol>
			</div>
			<p>
				<?php
				$this->post_button( 'phsl_requeue', 'Retry “nothing found” where Claude wasn’t tried', array( 'which' => 'not_found' ) );
				$this->post_button( 'phsl_requeue', 'Retry errors', array( 'which' => 'errors' ) );
				$this->post_button( 'phsl_requeue', 'Re-queue everything', array( 'which' => 'all' ), 'button button-link-delete', 'Forget all previous attempts and suggestions? Properties without a smart suburb will be looked up again (Claude calls cost a little). Saved smart suburbs are kept.' );
				?>
			</p>
			<p class="description">To redo a property’s smart suburb, clear its <code><?php echo esc_html( $s['smart_key'] ); ?></code> field and click Retry on the Results tab.</p>
		</div>

		<div class="phsl-card">
			<h2>Test a property</h2>
			<p class="description">Shows what would happen for one property without saving anything. If Claude is needed this makes a real (very cheap) API call.</p>
			<div class="phsl-controls">
				<label>Property ID <input type="number" id="phsl-test-id" class="small-text"></label>
				<button type="button" class="button" id="phsl-test">Test</button>
			</div>
			<pre id="phsl-test-output" class="phsl-pre" hidden></pre>
		</div>
		<?php
	}

	private function render_results() {
		global $wpdb;
		$labels     = array( 'suggested' => 'Suggested', 'assigned' => 'Assigned', 'not_found' => 'Nothing found', 'retry' => 'Waiting to retry', 'error' => 'Error', 'skipped' => 'Skipped' );
		$status     = sanitize_key( $_GET['status'] ?? '' );
		$comparison = sanitize_text_field( wp_unslash( $_GET['comparison'] ?? '' ) );
		$comparison = isset( self::$comparisons[ $comparison ] ) ? $comparison : '';
		$paged      = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page   = 50;
		$s          = self::settings();
		$extras     = self::extra_fields();

		if ( $comparison ) {
			// Comparison filter works across every property in scope, processed or not.
			$all   = array_values( array_filter( $this->comparison_rows(), function ( $r ) use ( $comparison ) {
				return $r['comparison'] === $comparison;
			} ) );
			$total = count( $all );
			$rows  = array();
			foreach ( array_slice( $all, ( $paged - 1 ) * $per_page, $per_page ) as $r ) {
				$rows[] = (object) array( 'ID' => $r['id'], 'post_title' => $r['title'], 'status' => $r['status'] );
			}
		} else {
			$where = $wpdb->prepare( 'st.meta_key = %s', self::META_STATUS );
			if ( isset( $labels[ $status ] ) ) {
				$where .= $wpdb->prepare( ' AND st.meta_value = %s', $status );
			}
			$from  = "{$wpdb->postmeta} st INNER JOIN {$wpdb->posts} p ON p.ID = st.post_id";
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$from} WHERE {$where}" ); // phpcs:ignore
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.post_title, st.meta_value AS status FROM {$from} WHERE {$where} ORDER BY st.meta_id DESC LIMIT %d OFFSET %d", $per_page, ( $paged - 1 ) * $per_page ) ); // phpcs:ignore
		}

		$osm_label    = array( '1' => 'found', '0' => 'no suburb', 'x' => 'failed' );
		$claude_label = array( 'used' => 'used', 'unsure' => 'unsure', 'error' => 'failed', 'nokey' => 'no API key' );
		$conf         = array( 'h' => 'high', 'm' => 'medium', 'l' => 'low' );
		?>
		<div class="phsl-toolbar">
			<form method="get" class="phsl-inline">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="hidden" name="tab" value="results">
				<select name="status" onchange="this.form.comparison.value='';this.form.submit()">
					<option value="">All statuses</option>
					<?php foreach ( $labels as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $status, $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="comparison" onchange="this.form.status.value='';this.form.submit()">
					<option value="">Any comparison</option>
					<?php foreach ( array_keys( self::$comparisons ) as $k ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $comparison, $k ); ?>><?php echo esc_html( $k ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>
			<?php $this->post_button( 'phsl_apply_all', 'Apply all suggestions', array(), 'button button-primary', 'Save all suggested smart suburbs? Only empty fields are filled.' ); ?>
			<?php $this->post_button( 'phsl_export', 'Export CSV', $comparison ? array( 'comparison' => $comparison ) : array() ); ?>
			<span class="description"><?php echo esc_html( number_format_i18n( $total ) ); ?> properties. Full details of each decision are in the Log.</span>
		</div>
		<table class="widefat striped">
			<thead><tr>
				<th>Property</th><th>Manual suburb</th><th>Smart suburb</th><th>Comparison</th>
				<?php foreach ( array_keys( $extras ) as $f ) : ?><th><?php echo esc_html( ucfirst( $f ) ); ?></th><?php endforeach; ?>
				<th>OSM</th><th>Claude</th><th>Status</th><th></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="<?php echo 8 + count( $extras ); ?>">Nothing here.</td></tr>
			<?php endif; ?>
			<?php
			foreach ( $rows as $row ) :
				$pid       = (int) $row->ID;
				$st        = self::get_state( $pid );
				$v         = (array) ( $st['v'] ?? array() );
				$pending   = 'suggested' === $row->status;
				$manual    = self::meta_values( $pid, $s['manual_key'] );
				$smart     = implode( ', ', self::meta_values( $pid, $s['smart_key'] ) );
				$suggested = '' === $smart && $pending && ! empty( $v['n'] );
				if ( $suggested ) {
					$smart = (string) $v['n'];
				}
				$cmp = self::compare( $manual, $smart, '' !== $smart || ( $row->status && 'retry' !== $row->status ) );
				?>
				<tr>
					<td><a href="<?php echo esc_url( get_edit_post_link( $pid ) ?: '#' ); ?>"><?php echo esc_html( $row->post_title ? $row->post_title : '#' . $pid ); ?></a>
						<?php if ( ! empty( $st['t'] ) ) : ?><br><small><?php echo esc_html( wp_date( 'j M Y H:i', $st['t'] ) ); ?></small><?php endif; ?></td>
					<td><?php echo $manual ? esc_html( implode( ', ', $manual ) ) : '–'; ?></td>
					<td><?php echo '' !== $smart ? esc_html( $smart ) : '–'; ?>
						<?php if ( ! empty( $st['src'] ) ) : ?><br><small><?php echo esc_html( 'claude' === $st['src'] ? 'Claude' . ( ! empty( $st['cf'] ) ? ', ' . ( $conf[ $st['cf'] ] ?? '' ) . ' confidence' : '' ) : 'OpenStreetMap' ); ?><?php echo $suggested ? ' · <strong>suggested</strong>' : ''; ?></small><?php endif; ?></td>
					<td><span class="phsl-badge phsl-cmp-<?php echo esc_attr( str_replace( ' ', '-', $cmp ) ); ?>"><?php echo esc_html( $cmp ); ?></span></td>
					<?php foreach ( $extras as $f => $key ) : ?>
						<td><?php echo esc_html( $pending ? ( $v[ 'neighbourhood' === $f ? 'nb' : 'q' ] ?? '–' ) : ( implode( ', ', self::meta_values( $pid, $key ) ) ?: '–' ) ); ?></td>
					<?php endforeach; ?>
					<td><?php echo esc_html( isset( $st['o'] ) ? ( $osm_label[ (string) $st['o'] ] ?? $st['o'] ) : '–' ); ?></td>
					<td><?php echo esc_html( isset( $st['a'] ) ? ( $claude_label[ $st['a'] ] ?? $st['a'] ) : '–' ); ?></td>
					<td><?php echo $row->status ? '<span class="phsl-badge phsl-' . esc_attr( $row->status ) . '">' . esc_html( $labels[ $row->status ] ?? $row->status ) . '</span>' : '–'; ?></td>
					<td class="phsl-actions">
						<?php
						if ( $pending ) {
							$this->post_button( 'phsl_apply', 'Apply', array( 'property_id' => $pid ), 'button button-small button-primary' );
						}
						$this->post_button( 'phsl_retry', 'Retry', array( 'property_id' => $pid ), 'button button-small' );
						?>
						<a class="button button-small" href="<?php echo esc_url( self::page_url( 'log', array( 'property' => $pid ) ) ); ?>">Log</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></div>';
		}
	}

	private function render_log() {
		$files    = PHSL_Logger::files();
		$month    = sanitize_text_field( wp_unslash( $_GET['month'] ?? '' ) );
		$month    = isset( $files[ $month ] ) ? $month : (string) key( $files );
		$level    = sanitize_key( $_GET['level'] ?? '' );
		$level    = in_array( $level, array( 'info', 'warning', 'error' ), true ) ? $level : '';
		$property = absint( $_GET['property'] ?? 0 );

		if ( $property ) {
			// A property's history can span months: look through every file.
			$rows = array();
			foreach ( array_keys( $files ) as $m ) {
				$rows = array_merge( $rows, PHSL_Logger::read( $m, 200, $level, $property ) );
			}
		} else {
			$rows = $month ? PHSL_Logger::read( $month, 1000, $level ) : array();
		}
		?>
		<div class="phsl-toolbar">
			<form method="get" class="phsl-inline">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="hidden" name="tab" value="log">
				<select name="month">
					<?php foreach ( array_keys( $files ) as $m ) : ?>
						<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $month, $m ); ?>><?php echo esc_html( $m ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="level">
					<option value="">All levels</option>
					<?php foreach ( array( 'info', 'warning', 'error' ) as $l ) : ?>
						<option value="<?php echo esc_attr( $l ); ?>" <?php selected( $level, $l ); ?>><?php echo esc_html( ucfirst( $l ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="number" name="property" value="<?php echo $property ? esc_attr( $property ) : ''; ?>" placeholder="Property ID" class="small-text phsl-pid">
				<button class="button">Filter</button>
			</form>
			<?php
			if ( $month ) {
				$this->post_button( 'phsl_download_log', 'Download ' . $month, array( 'month' => $month ) );
			}
			$this->post_button( 'phsl_clear_log', 'Delete all log files', array(), 'button button-link-delete', 'Delete all Smart Location log files?' );
			?>
			<p class="description">Log files: <code><?php echo esc_html( PHSL_Logger::dir() ); ?></code>, one per month, the last <?php echo (int) PHSL_Logger::KEEP_MONTHS; ?> months kept. Newest first; <?php echo $property ? 'all months for this property' : 'latest 1,000 lines'; ?>.</p>
		</div>
		<table class="widefat striped phsl-log">
			<thead><tr><th>Time (UTC)</th><th>Level</th><th>Property</th><th>Message</th></tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="4">Nothing logged.</td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<tr class="phsl-log-<?php echo esc_attr( $row['level'] ); ?>">
					<td class="phsl-nowrap"><?php echo esc_html( $row['time'] ); ?></td>
					<td><?php echo esc_html( $row['level'] ); ?></td>
					<td><?php echo $row['property_id'] ? '<a href="' . esc_url( get_edit_post_link( $row['property_id'] ) ?: '#' ) . '">#' . esc_html( $row['property_id'] ) . '</a>' : '–'; ?></td>
					<td><?php echo esc_html( $row['message'] ); ?>
						<?php if ( '' !== $row['context'] ) : ?><details><summary>details</summary><pre class="phsl-pre"><?php echo esc_html( wp_json_encode( json_decode( $row['context'], true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre></details><?php endif; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_settings() {
		$s         = self::settings();
		$const_key = defined( 'PHSL_ANTHROPIC_API_KEY' ) && PHSL_ANTHROPIC_API_KEY;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="phsl_save_settings">
			<?php wp_nonce_field( 'phsl_save_settings' ); ?>

			<h2>General</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Mode</th>
					<td>
						<label class="phsl-block"><input type="radio" name="mode" value="suggest" <?php checked( $s['mode'], 'suggest' ); ?>> <strong>Suggest only</strong>: list values on the Results tab for review; nothing is saved.</label>
						<label class="phsl-block"><input type="radio" name="mode" value="assign" <?php checked( $s['mode'], 'assign' ); ?>> <strong>Assign automatically</strong>: save values to the property.</label>
						<p class="description">Either way, fields are only filled when they are <strong>empty</strong>, so a value edited by hand is never overwritten. The Location field is never touched.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">New properties</th>
					<td>
						<label class="phsl-block"><input type="checkbox" name="on_import" value="1" <?php checked( $s['on_import'] ); ?>> Process each property straight after it is imported (Property Import / Property Sales Online add-ons). Up to 20 lookups per import run; the rest are picked up by the scheduled check.</label>
						<label class="phsl-block"><input type="checkbox" name="auto_new" value="1" <?php checked( $s['auto_new'] ); ?>> Check for unprocessed new properties every 10 minutes (catches anything added by hand or left over from an import)</label>
						<p class="description">Both only apply to properties added since Smart Location was activated. Existing properties are processed from the Overview tab.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Properties</th>
					<td>
						<?php foreach ( array( 'publish' => 'Published', 'private' => 'Private', 'draft' => 'Draft', 'pending' => 'Pending', 'future' => 'Scheduled' ) as $k => $l ) : ?>
							<label class="phsl-check"><input type="checkbox" name="statuses[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $s['statuses'], true ) ); ?>> <?php echo esc_html( $l ); ?></label>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>

			<h2>Suburb</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="phsl-smart-key">Smart suburb field</label></th>
					<td><input type="text" id="phsl-smart-key" name="smart_key" class="regular-text code phsl-short" value="<?php echo esc_attr( $s['smart_key'] ); ?>">
						<p class="description">The property meta key the smart suburb is saved to (from OpenStreetMap’s suburb, or Claude when OpenStreetMap has none). Use this key in your search query.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="phsl-manual-key">Manual suburb field</label></th>
					<td><input type="text" id="phsl-manual-key" name="manual_key" class="regular-text code phsl-short" value="<?php echo esc_attr( $s['manual_key'] ); ?>">
						<p class="description">The manually entered suburb, used only for the comparison and the CSV export – never changed. Where the same suburb already exists with a particular spelling (in either field), that spelling is used.</p></td>
				</tr>
			</table>

			<h2>Neighbourhood and quarter</h2>
			<table class="form-table" role="presentation">
				<?php foreach ( array( 'neighbourhood' => 'Neighbourhood', 'quarter' => 'Quarter (e.g. “Jewellery Quarter”, business districts)' ) as $f => $label ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( strtok( $label, ' ' ) ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $f ); ?>_enabled" value="1" <?php checked( $s[ $f . '_enabled' ] ); ?>> Save OpenStreetMap’s <?php echo esc_html( strtolower( $label ) ); ?> to custom field</label>
							<input type="text" name="<?php echo esc_attr( $f ); ?>_key" class="regular-text code phsl-short" value="<?php echo esc_attr( $s[ $f . '_key' ] ); ?>"></td>
					</tr>
				<?php endforeach; ?>
				<tr><td colspan="2" class="phsl-flush"><p class="description">Only filled when empty, from OpenStreetMap only (Claude isn’t asked). When enabled, properties that already have a smart suburb but an empty field here are looked up on OpenStreetMap too.</p></td></tr>
			</table>

			<h2>Claude</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Use Claude</th>
					<td><label><input type="checkbox" name="use_claude" value="1" <?php checked( $s['use_claude'] ); ?>> Ask Claude for the suburb when OpenStreetMap has none</label>
						<p class="description">Also needed for Organise Locations.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="phsl-key">API key</label></th>
					<td>
						<?php if ( $const_key ) : ?>
							<p><span class="phsl-badge phsl-assigned">Set in wp-config.php</span> (<code>PHSL_ANTHROPIC_API_KEY</code>)</p>
						<?php else : ?>
							<input type="password" id="phsl-key" name="claude_api_key" class="regular-text" autocomplete="off" placeholder="<?php echo $s['claude_api_key'] ? esc_attr( 'Saved (…' . substr( $s['claude_api_key'], -4 ) . '). Leave blank to keep, enter - to remove.' ) : 'sk-ant-…'; ?>">
							<p class="description">Recommended instead: add <code>define( 'PHSL_ANTHROPIC_API_KEY', 'sk-ant-…' );</code> to wp-config.php.</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="phsl-model">Model</label></th>
					<td><select id="phsl-model" name="claude_model">
						<?php foreach ( self::$models as $id => $label ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['claude_model'], $id ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Each lookup costs a fraction of a penny; token usage is in the log details.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="phsl-conf">Minimum confidence</label></th>
					<td><select id="phsl-conf" name="claude_min_confidence">
						<?php foreach ( array( 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low (always use Claude’s answer)' ) as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['claude_min_confidence'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select></td>
				</tr>
			</table>

			<h2>OpenStreetMap</h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="phsl-endpoint">Nominatim URL</label></th>
					<td><input type="url" id="phsl-endpoint" name="endpoint" class="regular-text" value="<?php echo esc_attr( $s['endpoint'] ); ?>"></td></tr>
				<tr><th scope="row"><label for="phsl-email">Contact email</label></th>
					<td><input type="email" id="phsl-email" name="email" class="regular-text" value="<?php echo esc_attr( $s['email'] ); ?>"><p class="description">Optional; sent to OpenStreetMap as their usage policy recommends.</p></td></tr>
				<tr><th scope="row"><label for="phsl-delay">Delay between requests</label></th>
					<td><input type="number" id="phsl-delay" name="delay" step="0.1" min="0.1" class="small-text" value="<?php echo esc_attr( $s['delay'] ); ?>"> seconds <span class="description">(minimum 1 for the public service)</span></td></tr>
				<tr><th scope="row"><label for="phsl-lang">Language</label></th>
					<td><input type="text" id="phsl-lang" name="language" class="small-text" value="<?php echo esc_attr( $s['language'] ); ?>"></td></tr>
				<tr><th scope="row">Missing coordinates</th>
					<td><label><input type="checkbox" name="address_fallback" value="1" <?php checked( $s['address_fallback'] ); ?>> Search by address when a property has no latitude/longitude</label></td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* =====================================================================
	 * Misc
	 * =================================================================== */

	public function log( $level, $message, $property_id = 0, $context = array() ) {
		PHSL_Logger::write( $level, $message, $property_id, $context );
	}

	public function check_ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! $this->user_can() ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
		}
	}

	public function check_admin_request( $action ) {
		check_admin_referer( $action );
		if ( ! $this->user_can() ) {
			wp_die( 'Permission denied' );
		}
	}
}

PH_Smart_Location::instance();
