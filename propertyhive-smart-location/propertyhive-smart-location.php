<?php
/**
 * Plugin Name: Property Hive Smart Location
 * Description: Automatically fills in a property's suburb (and optionally region) using OpenStreetMap, falling back to Claude AI when OpenStreetMap has no answer. Only empty fields are ever filled.
 * Version: 1.0.0
 * Author: Property Hive
 * Requires at least: 5.6
 * Requires PHP: 7.2
 * Text Domain: ph-smart-location
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PH_Smart_Location {

	const VERSION       = '1.0.0';
	const DB_VERSION    = '1';
	const OPTION        = 'phsl_settings';
	const PAGE_SLUG     = 'ph-smart-location';
	const NONCE         = 'phsl_nonce';
	const LOCK_OPTION   = 'phsl_lock';
	const CRON_HOOK     = 'phsl_cron';
	const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
	const API_VERSION   = '2026-10-21';

	/* Post meta used to track what the plugin has done. */
	const META_STATUS    = '_phsl_status';    // assigned | suggested | not_found | error | skipped
	const META_RESULT    = '_phsl_result';    // JSON details of the last attempt
	const META_ATTEMPTED = '_phsl_attempted'; // unix time; set once a property should not be retried automatically
	const META_TRIES     = '_phsl_tries';     // transient-failure counter

	/** OSM fields that can be used for smart suburb assignment, in priority order. */
	public static $osm_choices = array( 'suburb', 'neighbourhood', 'quarter' );

	/** All OSM area fields passed to Claude as context. */
	public static $osm_context_fields = array( 'neighbourhood', 'suburb', 'quarter', 'residential', 'hamlet', 'village', 'borough', 'city_district', 'town', 'city', 'county' );

	public static $models = array(
		'claude-opus-5-5'   => 'Claude Opus 5.5 (most accurate)',
		'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
		'claude-haiku-5-5'  => 'Claude Haiku 5.5 (cheapest)',
	);

	private static $instance = null;

	private $vocab = null;
	private $last_osm_request = array();

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

		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 99 );

		add_action( 'wp_ajax_phsl_step', array( $this, 'ajax_step' ) );
		add_action( 'wp_ajax_phsl_test', array( $this, 'ajax_test' ) );

		foreach ( array( 'save_settings', 'apply', 'apply_all', 'retry', 'reset', 'clear_log' ) as $action ) {
			add_action( 'admin_post_phsl_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	/* =====================================================================
	 * Install
	 * =================================================================== */

	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'phsl_log';
	}

	public static function activate() {
		self::install();
		if ( ! get_option( 'phsl_activated_at' ) ) {
			update_option( 'phsl_activated_at', current_time( 'mysql', true ), false );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'phsl_ten_minutes', self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::log_table();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			logged_at datetime NOT NULL,
			level varchar(10) NOT NULL DEFAULT 'info',
			property_id bigint(20) unsigned NOT NULL DEFAULT 0,
			message text NOT NULL,
			PRIMARY KEY  (id),
			KEY logged_at (logged_at)
		) {$wpdb->get_charset_collate()};" );
		update_option( 'phsl_db_version', self::DB_VERSION, false );
	}

	public function maybe_upgrade() {
		if ( get_option( 'phsl_db_version' ) !== self::DB_VERSION ) {
			self::install();
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
			'auto_new'              => 1,
			'suburb_key'            => '_suburb',
			'osm_fields'            => array( 'suburb' ),
			'use_claude'            => 1,
			'claude_model'          => 'claude-opus-5-5',
			'claude_api_key'        => '',
			'claude_min_confidence' => 'medium',
			'region_enabled'        => 0,
			'region_key'            => '_region',
			'region_multiple'       => 1,
			'region_values'         => "Birmingham Central\nBirmingham North\nBirmingham South\nBirmingham East\nBirmingham West\nTowns Surrounding\nRural Surrounding",
			'region_rules'          => '',
			'region_use_claude'     => 1,
			'statuses'              => array( 'publish' ),
			'endpoint'              => 'https://nominatim.openstreetmap.org',
			'email'                 => '',
			'delay'                 => 1.5,
			'language'              => 'en',
			'address_fallback'      => 1,
		);
	}

	public static function settings() {
		$s = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$s['osm_fields'] = array_values( array_intersect( self::$osm_choices, (array) $s['osm_fields'] ) ); // keep priority order
		$s['delay']      = max( self::min_delay( $s['endpoint'] ), (float) $s['delay'] );
		if ( empty( $s['statuses'] ) || ! is_array( $s['statuses'] ) ) {
			$s['statuses'] = array( 'publish' );
		}
		if ( ! isset( self::$models[ $s['claude_model'] ] ) ) {
			$s['claude_model'] = 'claude-opus-5-5';
		}
		return $s;
	}

	public static function min_delay( $endpoint ) {
		$host = (string) wp_parse_url( $endpoint, PHP_URL_HOST );
		return ( false !== stripos( $host, 'openstreetmap.org' ) ) ? 1.0 : 0.1;
	}

	/** The API key: a PHSL_ANTHROPIC_API_KEY constant in wp-config.php wins over the settings field. */
	public static function api_key() {
		if ( defined( 'PHSL_ANTHROPIC_API_KEY' ) && PHSL_ANTHROPIC_API_KEY ) {
			return (string) PHSL_ANTHROPIC_API_KEY;
		}
		return (string) self::settings()['claude_api_key'];
	}

	public static function claude_enabled() {
		return self::settings()['use_claude'] && '' !== self::api_key();
	}

	public static function region_values() {
		$values = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) self::settings()['region_values'] ) ) );
		return array_values( array_unique( $values ) );
	}

	public function handle_save_settings() {
		$this->check_admin_request( 'phsl_save_settings' );
		$p   = wp_unslash( $_POST );
		$old = self::settings();

		$endpoint = esc_url_raw( trim( $p['endpoint'] ?? '' ) );
		if ( '' === $endpoint ) {
			$endpoint = self::defaults()['endpoint'];
		}
		$statuses = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $p['statuses'] ?? array() ) ), array( 'publish', 'private', 'draft', 'pending', 'future' ) ) );
		$key_in   = trim( (string) ( $p['claude_api_key'] ?? '' ) );

		$new = array(
			'mode'                  => 'assign' === ( $p['mode'] ?? '' ) ? 'assign' : 'suggest',
			'auto_new'              => empty( $p['auto_new'] ) ? 0 : 1,
			'suburb_key'            => self::sanitize_meta_key( $p['suburb_key'] ?? '_suburb', '_suburb' ),
			'osm_fields'            => array_values( array_intersect( self::$osm_choices, (array) ( $p['osm_fields'] ?? array() ) ) ),
			'use_claude'            => empty( $p['use_claude'] ) ? 0 : 1,
			'claude_model'          => isset( self::$models[ $p['claude_model'] ?? '' ] ) ? $p['claude_model'] : 'claude-opus-5-5',
			// Blank keeps the existing key (the field is never pre-filled); "-" removes it.
			'claude_api_key'        => '-' === $key_in ? '' : ( '' === $key_in ? $old['claude_api_key'] : sanitize_text_field( $key_in ) ),
			'claude_min_confidence' => in_array( $p['claude_min_confidence'] ?? '', array( 'high', 'medium', 'low' ), true ) ? $p['claude_min_confidence'] : 'medium',
			'region_enabled'        => empty( $p['region_enabled'] ) ? 0 : 1,
			'region_key'            => self::sanitize_meta_key( $p['region_key'] ?? '_region', '_region' ),
			'region_multiple'       => empty( $p['region_multiple'] ) ? 0 : 1,
			'region_values'         => sanitize_textarea_field( $p['region_values'] ?? '' ),
			'region_rules'          => sanitize_textarea_field( $p['region_rules'] ?? '' ),
			'region_use_claude'     => empty( $p['region_use_claude'] ) ? 0 : 1,
			'statuses'              => $statuses ? $statuses : array( 'publish' ),
			'endpoint'              => untrailingslashit( $endpoint ),
			'email'                 => sanitize_email( $p['email'] ?? '' ),
			'delay'                 => max( self::min_delay( $endpoint ), (float) ( $p['delay'] ?? 1.5 ) ),
			'language'              => sanitize_text_field( $p['language'] ?? 'en' ),
			'address_fallback'      => empty( $p['address_fallback'] ) ? 0 : 1,
		);
		update_option( self::OPTION, $new, false );

		if ( $old['mode'] !== $new['mode'] ) {
			$this->log( 'info', sprintf( 'Mode changed to "%s" by %s', $new['mode'], wp_get_current_user()->user_login ) );
		}
		$this->redirect( 'settings', 'settings_saved' );
	}

	private static function sanitize_meta_key( $key, $fallback ) {
		$key = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $key );
		return '' === $key ? $fallback : $key;
	}

	/* =====================================================================
	 * Helpers: values, normalisation, postcodes
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

	/** Split stored meta into values: arrays (multi-selects) and comma/semicolon/pipe separated text. */
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

	/** Every suburb already used on the site: normalised => spelling as used. */
	private function vocab() {
		global $wpdb;
		if ( null !== $this->vocab ) {
			return $this->vocab;
		}
		$key   = self::settings()['suburb_key'];
		$cache = get_transient( 'phsl_vocab_' . md5( $key ) );
		if ( is_array( $cache ) ) {
			return $this->vocab = $cache;
		}
		$vocab = array();
		$raws  = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = 'property' AND pm.meta_value <> ''", $key ) );
		foreach ( $raws as $raw ) {
			foreach ( self::split_values( $raw ) as $v ) {
				$n = self::normalise( $v );
				if ( '' !== $n && ! isset( $vocab[ $n ] ) ) {
					$vocab[ $n ] = $v;
				}
			}
		}
		set_transient( 'phsl_vocab_' . md5( $key ), $vocab, HOUR_IN_SECONDS );
		return $this->vocab = $vocab;
	}

	private function forget_vocab() {
		$this->vocab = null;
		delete_transient( 'phsl_vocab_' . md5( self::settings()['suburb_key'] ) );
	}

	/** Returns array( outward, sector ) e.g. ( 'B15', 'B15 2' ) for "B15 2ES". */
	public static function postcode_parts( $postcode ) {
		$pc = strtoupper( preg_replace( '/\s+/', '', (string) $postcode ) );
		if ( preg_match( '/^([A-Z]{1,2}[0-9][0-9A-Z]?)([0-9])([A-Z]{2})?$/', $pc, $m ) ) {
			return array( $m[1], $m[1] . ' ' . $m[2] );
		}
		if ( preg_match( '/^[A-Z]{1,2}[0-9][0-9A-Z]?$/', $pc ) ) {
			return array( $pc, '' );
		}
		return array( '', '' );
	}

	public static function parse_coord( $value ) {
		$value = trim( str_replace( ',', '.', (string) $value ) );
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Region rules, one per line: "Birmingham South = Edgbaston, Selly Oak, B30, B15 2".
	 * Terms are suburb names, postcode districts (B30) or postcode sectors (B15 2).
	 *
	 * @return array list of array( region, suburbs[], postcodes[] )
	 */
	public static function region_rules() {
		$rules = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) self::settings()['region_rules'] ) as $line ) {
			if ( false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $region, $terms ) = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( '' === $region ) {
				continue;
			}
			$rule = array( 'region' => $region, 'suburbs' => array(), 'postcodes' => array() );
			foreach ( explode( ',', $terms ) as $term ) {
				$term = trim( $term );
				if ( '' === $term ) {
					continue;
				}
				if ( preg_match( '/^[A-Z]{1,2}[0-9][0-9A-Z]?( ?[0-9])?$/i', $term ) ) {
					$rule['postcodes'][] = strtoupper( preg_replace( '/^([A-Z]{1,2}[0-9][0-9A-Z]?) ?([0-9])$/i', '$1 $2', $term ) );
				} else {
					$rule['suburbs'][] = self::normalise( $term );
				}
			}
			$rules[] = $rule;
		}
		return $rules;
	}

	/** Region from the rules: suburb name first, then postcode sector, then postcode district. */
	public static function region_from_rules( $suburbs, $postcode ) {
		$rules = self::region_rules();
		$norm  = array_map( array( __CLASS__, 'normalise' ), (array) $suburbs );
		foreach ( $rules as $r ) {
			foreach ( $norm as $n ) {
				if ( in_array( $n, $r['suburbs'], true ) ) {
					return array( $r['region'], 'rule: suburb ' . $n );
				}
			}
		}
		list( $outward, $sector ) = self::postcode_parts( $postcode );
		foreach ( array( $sector, $outward ) as $pc ) {
			if ( '' === $pc ) {
				continue;
			}
			foreach ( $rules as $r ) {
				if ( in_array( $pc, $r['postcodes'], true ) ) {
					return array( $r['region'], 'rule: postcode ' . $pc );
				}
			}
		}
		return array( '', '' );
	}

	/* =====================================================================
	 * Candidates
	 * =================================================================== */

	private function empty_meta_sql( $key ) {
		global $wpdb;
		return $wpdb->prepare( "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} em WHERE em.post_id = p.ID AND em.meta_key = %s AND em.meta_value <> '' AND em.meta_value <> 'a:0:{}')", $key );
	}

	/** SQL WHERE for properties that need something filled in. */
	private function needs_sql() {
		$s     = self::settings();
		$needs = array( $this->empty_meta_sql( $s['suburb_key'] ) );
		if ( $s['region_enabled'] ) {
			$needs[] = $this->empty_meta_sql( $s['region_key'] );
		}
		return '(' . implode( ' OR ', $needs ) . ')';
	}

	private function scope_sql() {
		global $wpdb;
		$s  = self::settings();
		$in = implode( ',', array_fill( 0, count( $s['statuses'] ), '%s' ) );
		return $wpdb->prepare( "p.post_type = 'property' AND p.post_status IN ({$in})", $s['statuses'] ); // phpcs:ignore
	}

	/**
	 * Properties still to process: need a value, and not yet attempted.
	 *
	 * @param bool $only_new Only properties created since the plugin was activated (automatic processing).
	 */
	private function candidates( $limit, $only_new ) {
		global $wpdb;
		$sql = "SELECT p.ID FROM {$wpdb->posts} p WHERE " . $this->scope_sql() . ' AND ' . $this->needs_sql()
			. $wpdb->prepare( " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} am WHERE am.post_id = p.ID AND am.meta_key = %s)", self::META_ATTEMPTED );
		if ( $only_new ) {
			$sql .= $wpdb->prepare( ' AND p.post_date_gmt >= %s', get_option( 'phsl_activated_at', current_time( 'mysql', true ) ) );
		}
		$sql .= $wpdb->prepare( ' ORDER BY p.ID DESC LIMIT %d', $limit );
		return array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore
	}

	private function counts() {
		global $wpdb;
		$s     = self::settings();
		$scope = $this->scope_sql();
		$out   = array(
			'total'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$scope}" ), // phpcs:ignore
			'missing_suburb' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$scope} AND " . $this->empty_meta_sql( $s['suburb_key'] ) ), // phpcs:ignore
			'to_process'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$scope} AND " . $this->needs_sql() . $wpdb->prepare( " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} am WHERE am.post_id = p.ID AND am.meta_key = %s)", self::META_ATTEMPTED ) ), // phpcs:ignore
		);
		if ( $s['region_enabled'] ) {
			$out['missing_region'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$scope} AND " . $this->empty_meta_sql( $s['region_key'] ) ); // phpcs:ignore
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS status, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value", self::META_STATUS ) ) as $row ) {
			$out[ 'status_' . $row->status ] = (int) $row->n;
		}
		return $out;
	}

	/* =====================================================================
	 * Processing
	 * =================================================================== */

	public function cron_run() {
		$s = self::settings();
		if ( ! $s['auto_new'] || ! $this->acquire_lock() ) {
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
	 * Work out (and, in assign mode, save) the suburb/region for one property.
	 *
	 * @param bool $dry_run Never save or record anything (test tool).
	 * @return array Result details.
	 */
	public function process_property( $property_id, $dry_run = false ) {
		$s        = self::settings();
		$postcode = (string) get_post_meta( $property_id, '_address_postcode', true );
		$result   = array(
			'property_id'   => $property_id,
			'mode'          => $s['mode'],
			'suburb'        => '',
			'suburb_source' => '',
			'suburb_is_new' => false,
			'region'        => '',
			'region_source' => '',
			'claude'        => null,
			'status'        => '',
			'message'       => '',
			'time'          => time(),
		);

		$need_suburb = ! self::meta_values( $property_id, $s['suburb_key'] );
		$need_region = $s['region_enabled'] && ! self::meta_values( $property_id, $s['region_key'] );

		if ( ! $need_suburb && ! $need_region ) {
			$result['status']  = 'skipped';
			$result['message'] = 'Nothing to fill in.';
			return $this->finish( $result, $dry_run, true );
		}

		// 1. OpenStreetMap.
		$osm = $this->osm_lookup( $property_id );
		if ( is_wp_error( $osm ) ) {
			$transient = ! in_array( $osm->get_error_code(), array( 'no_location', 'not_found' ), true );
			$halt      = in_array( $osm->get_error_code(), array( 'rate_limited', 'blocked' ), true );
			if ( ! $transient || $halt || ! self::claude_enabled() ) {
				$result['status']  = 'error';
				$result['message'] = $osm->get_error_message();
				$result['halt']    = $halt;
				return $this->finish( $result, $dry_run, ! $transient );
			}
			$osm = array( 'address' => array(), 'source' => 'none', 'error' => $osm->get_error_message() );
			$result['osm_error'] = $osm['error'];
		}
		$address             = $osm['address'];
		$result['osm_areas'] = array_intersect_key( $address, array_flip( self::$osm_context_fields ) );
		if ( '' === $postcode && ! empty( $address['postcode'] ) ) {
			$postcode = (string) $address['postcode'];
		}

		if ( $need_suburb ) {
			foreach ( $s['osm_fields'] as $field ) {
				if ( ! empty( $address[ $field ] ) && is_string( $address[ $field ] ) ) {
					$result['suburb']        = trim( $address[ $field ] );
					$result['suburb_source'] = 'osm:' . $field;
					break;
				}
			}
		}

		// Region from the rules, using the new suburb or the one already entered.
		$suburbs_for_region = $need_suburb ? array_filter( array( $result['suburb'] ) ) : self::meta_values( $property_id, $s['suburb_key'] );
		if ( $need_region ) {
			list( $result['region'], $result['region_source'] ) = self::region_from_rules( $suburbs_for_region, $postcode );
		}

		// 2. Claude, for whatever OSM / the rules could not answer.
		$ask_suburb = $need_suburb && '' === $result['suburb'];
		$ask_region = $need_region && '' === $result['region'] && $s['region_use_claude'] && self::region_values();
		if ( ( $ask_suburb || $ask_region ) && self::claude_enabled() ) {
			$claude = $this->claude_lookup( $property_id, $postcode, $osm, $ask_suburb, $ask_region, $suburbs_for_region );
			if ( is_wp_error( $claude ) ) {
				$result['claude_error'] = $claude->get_error_message();
				$this->log( 'error', 'Claude: ' . $claude->get_error_message(), $property_id );
				if ( '' === $result['suburb'] && '' === $result['region'] ) {
					$result['status']  = 'error';
					$result['message'] = 'Claude: ' . $claude->get_error_message();
					$result['halt']    = 'claude_auth' === $claude->get_error_code();
					return $this->finish( $result, $dry_run, false );
				}
			} else {
				$result['claude'] = $claude;
				$ranks            = array( 'low' => 1, 'medium' => 2, 'high' => 3 );
				$confident        = ( $ranks[ $claude['confidence'] ] ?? 0 ) >= $ranks[ $s['claude_min_confidence'] ];
				if ( $ask_suburb && '' !== $claude['suburb'] ) {
					if ( $confident ) {
						$result['suburb']        = $claude['suburb'];
						$result['suburb_source'] = 'claude';
					} else {
						$result['suburb_low_confidence'] = $claude['suburb'];
					}
				}
				if ( $ask_region ) {
					// A rule match on Claude's suburb beats Claude's own region guess.
					if ( 'claude' === $result['suburb_source'] ) {
						list( $result['region'], $result['region_source'] ) = self::region_from_rules( array( $result['suburb'] ), $postcode );
					}
					if ( '' === $result['region'] && '' !== $claude['region'] && $confident ) {
						$result['region']        = $claude['region'];
						$result['region_source'] = 'claude';
					}
				}
			}
		}

		// Use the site's existing spelling where the suburb is already in use.
		if ( '' !== $result['suburb'] ) {
			$vocab = $this->vocab();
			$n     = self::normalise( $result['suburb'] );
			if ( isset( $vocab[ $n ] ) ) {
				$result['suburb'] = $vocab[ $n ];
			} else {
				$result['suburb_is_new'] = true;
			}
		}

		if ( '' === $result['suburb'] && '' === $result['region'] ) {
			$result['status']  = 'not_found';
			$result['message'] = 'No suburb' . ( $need_region ? ' or region' : '' ) . ' found' . ( self::claude_enabled() ? '' : ( $s['use_claude'] ? ' (Claude not used: no API key)' : ' (Claude disabled)' ) ) . '.';
			if ( ! empty( $result['osm_error'] ) ) {
				$result['message'] .= ' OpenStreetMap: ' . $result['osm_error'];
			}
			if ( ! empty( $result['claude'] ) || ! empty( $result['suburb_low_confidence'] ) ) {
				$result['message'] .= ' ' . substr( $this->describe( array_merge( $result, array( 'status' => '' ) ) ), 2 );
			}
			return $this->finish( $result, $dry_run, true );
		}

		$result['status'] = 'assign' === $s['mode'] ? 'assigned' : 'suggested';
		if ( ! $dry_run && 'assigned' === $result['status'] ) {
			$this->save_values( $property_id, $result );
		}
		$result['message'] = $this->describe( $result );
		return $this->finish( $result, $dry_run, true );
	}

	private function describe( $r ) {
		$parts = array();
		if ( '' !== $r['suburb'] ) {
			$parts[] = sprintf( 'suburb "%s" from %s%s', $r['suburb'], $r['suburb_source'], $r['suburb_is_new'] ? ' (new suburb)' : '' );
		} elseif ( ! empty( $r['suburb_low_confidence'] ) ) {
			$parts[] = sprintf( 'suburb not set: Claude suggested "%s" with low confidence', $r['suburb_low_confidence'] );
		}
		if ( '' !== $r['region'] ) {
			$parts[] = sprintf( 'region "%s" from %s', $r['region'], $r['region_source'] );
		}
		if ( ! empty( $r['claude'] ) ) {
			$parts[] = sprintf( 'Claude (%s confidence): %s [%s, %d in / %d out tokens]', $r['claude']['confidence'], $r['claude']['reason'], $r['claude']['model'], $r['claude']['input_tokens'], $r['claude']['output_tokens'] );
		}
		return ucfirst( $r['status'] ) . ': ' . implode( '; ', $parts );
	}

	/** Write values, only ever into empty fields. */
	private function save_values( $property_id, $r ) {
		$s = self::settings();
		if ( '' !== $r['suburb'] && ! self::meta_values( $property_id, $s['suburb_key'] ) ) {
			update_post_meta( $property_id, $s['suburb_key'], $r['suburb'] );
			$this->forget_vocab();
		}
		if ( $s['region_enabled'] && '' !== $r['region'] && ! self::meta_values( $property_id, $s['region_key'] ) ) {
			update_post_meta( $property_id, $s['region_key'], $s['region_multiple'] ? array( $r['region'] ) : $r['region'] );
		}
		do_action( 'phsl_values_assigned', $property_id, $r );
	}

	/**
	 * Record the outcome on the property and in the log.
	 *
	 * @param bool $final True when the property should not be retried automatically.
	 */
	private function finish( $result, $dry_run, $final ) {
		if ( $dry_run ) {
			return $result;
		}
		$pid = $result['property_id'];

		if ( ! empty( $result['halt'] ) ) {
			// OSM refused us or the API key is wrong: leave the property queued and stop.
			$this->log( 'error', 'Stopping: ' . $result['message'], $pid );
			return $result;
		}

		if ( ! $final ) {
			$tries = (int) get_post_meta( $pid, self::META_TRIES, true ) + 1;
			update_post_meta( $pid, self::META_TRIES, $tries );
			$final = $tries >= 3;
			if ( ! $final ) {
				$result['message'] .= sprintf( ' (will retry, attempt %d of 3)', $tries );
			}
		}
		if ( $final ) {
			update_post_meta( $pid, self::META_ATTEMPTED, time() );
			delete_post_meta( $pid, self::META_TRIES );
		}
		update_post_meta( $pid, self::META_STATUS, $result['status'] );
		update_post_meta( $pid, self::META_RESULT, wp_slash( wp_json_encode( $result ) ) );

		$level = array( 'error' => 'error', 'not_found' => 'warning' )[ $result['status'] ] ?? 'info';
		$this->log( $level, $result['message'], $pid );
		return $result;
	}

	/* =====================================================================
	 * OpenStreetMap
	 * =================================================================== */

	private function address_query( $property_id ) {
		$parts = array();
		foreach ( array( '_address_name_number', '_address_street', '_address_two', '_address_three', '_address_four', '_address_postcode' ) as $key ) {
			$value = trim( (string) get_post_meta( $property_id, $key, true ) );
			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}
		return implode( ', ', $parts );
	}

	/** @return array|WP_Error array( 'address' => [...], 'source' => 'coordinates'|'address', 'lat', 'lng' ) */
	private function osm_lookup( $property_id ) {
		$s   = self::settings();
		$lat = self::parse_coord( get_post_meta( $property_id, '_latitude', true ) );
		$lng = self::parse_coord( get_post_meta( $property_id, '_longitude', true ) );

		if ( null !== $lat && null !== $lng && ( 0.0 !== $lat || 0.0 !== $lng ) && abs( $lat ) <= 90 && abs( $lng ) <= 180 ) {
			$data = $this->nominatim( 'reverse', array( 'lat' => $lat, 'lon' => $lng, 'zoom' => 18 ) );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$address = ( empty( $data['error'] ) && isset( $data['address'] ) && is_array( $data['address'] ) ) ? $data['address'] : array();
			return array( 'address' => $address, 'source' => 'coordinates', 'lat' => $lat, 'lng' => $lng );
		}

		$query = $this->address_query( $property_id );
		if ( ! $s['address_fallback'] || '' === $query ) {
			return new WP_Error( 'no_location', 'Property has no latitude/longitude' . ( $s['address_fallback'] ? ' or address' : '' ) . '.' );
		}
		$params  = array( 'q' => $query, 'limit' => 1 );
		$country = get_post_meta( $property_id, '_address_country', true );
		if ( $country && 2 === strlen( $country ) ) {
			$params['countrycodes'] = strtolower( $country );
		}
		$data = $this->nominatim( 'search', $params );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data[0] ) ) {
			return new WP_Error( 'not_found', 'Address not found on OpenStreetMap: ' . $query );
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
		$this->last_osm_request = array( 'url' => $url );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => 'PropertyHive-Smart-Location/' . self::VERSION . ' (' . home_url() . ')',
				'headers'    => array( 'Referer' => home_url() ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'osm_request_failed', 'Request to OpenStreetMap failed: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$this->last_osm_request['http_code'] = $code;
		$snippet = trim( mb_substr( wp_strip_all_tags( $body ), 0, 200 ) );

		if ( 429 === $code ) {
			return new WP_Error( 'rate_limited', 'OpenStreetMap rate limit (HTTP 429). ' . $snippet );
		}
		if ( 403 === $code || 418 === $code ) {
			return new WP_Error( 'blocked', 'OpenStreetMap refused access (HTTP ' . $code . '). ' . $snippet );
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'osm_http_' . $code, 'OpenStreetMap HTTP ' . $code . '. ' . $snippet );
		}
		$data = json_decode( $body, true );
		if ( null === $data ) {
			return new WP_Error( 'osm_bad_json', 'Invalid response from OpenStreetMap: ' . $snippet );
		}
		return $data;
	}

	/** Never call OSM more often than $delay seconds, across all requests on the site. */
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
	 * Ask Claude for the suburb and/or region.
	 *
	 * @return array|WP_Error array( suburb, region, confidence, reason, model, input_tokens, output_tokens )
	 */
	private function claude_lookup( $property_id, $postcode, $osm, $ask_suburb, $ask_region, $known_suburbs ) {
		$s = self::settings();

		$lines   = array();
		$lines[] = 'Address: ' . $this->address_query( $property_id );
		if ( $postcode ) {
			$lines[] = 'Postcode: ' . $postcode;
		}
		$lat = self::parse_coord( get_post_meta( $property_id, '_latitude', true ) );
		$lng = self::parse_coord( get_post_meta( $property_id, '_longitude', true ) );
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
		if ( ! $ask_suburb && $known_suburbs ) {
			$lines[] = 'Suburb: ' . implode( ', ', $known_suburbs );
		}

		$tasks = array();
		if ( $ask_suburb ) {
			$tasks[] = 'Identify the suburb: the locally recognised district or area name an estate agent would use for this address (for example "Edgbaston" or "Kings Norton"). Do not give the city, county, a building, a road or a housing development name. OpenStreetMap data may be missing or wrong; use your own knowledge of the area. If you are not reasonably sure, return an empty suburb.';
		}
		$properties = array(
			'suburb'     => array( 'type' => 'string' ),
			'region'     => array( 'type' => 'string' ),
			'confidence' => array( 'type' => 'string', 'enum' => array( 'high', 'medium', 'low' ) ),
			'reason'     => array( 'type' => 'string' ),
		);
		if ( $ask_region ) {
			$values  = self::region_values();
			$tasks[] = 'Choose the region for this property. It must be exactly one of: ' . implode( '; ', $values ) . '. Return an empty region if none fits.';
			if ( trim( $s['region_rules'] ) ) {
				$tasks[] = "How regions are defined (region = suburbs / postcodes):\n" . trim( $s['region_rules'] );
			}
			$properties['region']['enum'] = array_merge( $values, array( '' ) );
		}
		$tasks[] = 'Give your confidence and a one-sentence reason. Return an empty string for anything you were not asked for.';

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
						'required'             => array( 'suburb', 'region', 'confidence', 'reason' ),
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
		$use_fallbacks = 'claude-haiku-5-5' !== $s['claude_model'];
		$response      = $this->claude_request( $body, $use_fallbacks );
		if ( is_wp_error( $response ) && $use_fallbacks && 'claude_http_400' === $response->get_error_code() ) {
			$response = $this->claude_request( $body, false );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 'refusal' === ( $response['stop_reason'] ?? '' ) ) {
			return new WP_Error( 'claude_refusal', 'Claude declined this request.' );
		}
		$text = '';
		foreach ( (array) ( $response['content'] ?? array() ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= $block['text'];
			}
		}
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'claude_bad_output', 'Unexpected answer from Claude (stop reason: ' . ( $response['stop_reason'] ?? '?' ) . ').' );
		}

		$region = trim( (string) ( $data['region'] ?? '' ) );
		if ( '' !== $region && ! in_array( $region, self::region_values(), true ) ) {
			$region = '';
		}
		return array(
			'suburb'        => $ask_suburb ? trim( (string) ( $data['suburb'] ?? '' ) ) : '',
			'region'        => $ask_region ? $region : '',
			'confidence'    => in_array( $data['confidence'] ?? '', array( 'high', 'medium', 'low' ), true ) ? $data['confidence'] : 'low',
			'reason'        => trim( (string) ( $data['reason'] ?? '' ) ),
			'model'         => $response['model'] ?? $s['claude_model'],
			'input_tokens'  => (int) ( $response['usage']['input_tokens'] ?? 0 ),
			'output_tokens' => (int) ( $response['usage']['output_tokens'] ?? 0 ),
		);
	}

	private function claude_request( $body, $use_fallbacks ) {
		$headers = array(
			'content-type'      => 'application/json',
			'x-api-key'         => self::api_key(),
			'anthropic-version' => self::API_VERSION,
		);
		if ( $use_fallbacks ) {
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
			$body['fallbacks']         = 'default';
		}

		$response = wp_remote_post(
			self::ANTHROPIC_URL,
			array(
				'timeout' => 90,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'claude_request_failed', 'Could not reach the Claude API: ' . $response->get_error_message() );
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
			return new WP_Error( 'claude_auth', 'Out of API credit: ' . $message );
		}
		return new WP_Error( 'claude_http_' . $code, 'Claude API HTTP ' . $code . ': ' . $message );
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
			wp_send_json_success( array( 'busy' => true, 'message' => 'Another process is running (automatic run or another tab). Waiting…' ) );
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
				'property'            => get_the_title( $pid ),
				'current_suburb'      => implode( ', ', self::meta_values( $pid, $s['suburb_key'] ) ),
				'current_region'      => $s['region_enabled'] ? implode( ', ', self::meta_values( $pid, $s['region_key'] ) ) : '(region disabled)',
				'note'                => 'Test only: nothing was saved. Only empty fields are ever filled, so a property that already has a suburb is skipped.',
				'result'              => $r,
				'openstreetmap_query' => $this->last_osm_request,
			)
		);
	}

	/* =====================================================================
	 * admin-post actions
	 * =================================================================== */

	private function load_result( $pid ) {
		$r = json_decode( (string) get_post_meta( $pid, self::META_RESULT, true ), true );
		return is_array( $r ) ? $r : null;
	}

	private function apply_one( $pid ) {
		$r = $this->load_result( $pid );
		if ( ! $r || 'suggested' !== get_post_meta( $pid, self::META_STATUS, true ) ) {
			return false;
		}
		$this->save_values( $pid, $r );
		$r['status']  = 'assigned';
		$r['message'] = $this->describe( $r ) . ' (applied by ' . wp_get_current_user()->user_login . ')';
		update_post_meta( $pid, self::META_STATUS, 'assigned' );
		update_post_meta( $pid, self::META_RESULT, wp_slash( wp_json_encode( $r ) ) );
		$this->log( 'info', $r['message'], $pid );
		return true;
	}

	public function handle_apply() {
		$this->check_admin_request( 'phsl_apply' );
		$this->apply_one( absint( $_POST['property_id'] ?? 0 ) );
		$this->redirect( 'results', 'applied' );
	}

	public function handle_apply_all() {
		$this->check_admin_request( 'phsl_apply_all' );
		$ids = get_posts( array( 'post_type' => 'property', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => self::META_STATUS, 'meta_value' => 'suggested' ) ); // phpcs:ignore
		$n   = 0;
		foreach ( $ids as $pid ) {
			$n += $this->apply_one( $pid ) ? 1 : 0;
		}
		$this->log( 'info', sprintf( 'Applied %d suggestions', $n ) );
		$this->redirect( 'results', 'applied' );
	}

	private function clear_tracking( $pid ) {
		foreach ( array( self::META_STATUS, self::META_RESULT, self::META_ATTEMPTED, self::META_TRIES ) as $k ) {
			delete_post_meta( $pid, $k );
		}
	}

	public function handle_retry() {
		$this->check_admin_request( 'phsl_retry' );
		$this->clear_tracking( absint( $_POST['property_id'] ?? 0 ) );
		$this->redirect( 'results', 'retry' );
	}

	public function handle_reset() {
		global $wpdb;
		$this->check_admin_request( 'phsl_reset' );
		$only = sanitize_key( $_POST['only'] ?? '' );
		$ids  = $only
			? $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", self::META_STATUS, $only ) )
			: $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)", self::META_ATTEMPTED, self::META_STATUS ) );
		foreach ( $ids as $pid ) {
			$this->clear_tracking( (int) $pid );
		}
		$this->log( 'info', sprintf( 'Re-queued %d properties (%s)', count( $ids ), $only ? $only : 'all' ) );
		$this->redirect( 'run', 'reset' );
	}

	public function handle_clear_log() {
		global $wpdb;
		$this->check_admin_request( 'phsl_clear_log' );
		$wpdb->query( 'TRUNCATE TABLE ' . self::log_table() ); // phpcs:ignore
		$this->redirect( 'log', 'log_cleared' );
	}

	/* =====================================================================
	 * Admin page
	 * =================================================================== */

	private static function capability() {
		return apply_filters( 'phsl_capability', 'manage_propertyhive' );
	}

	private function user_can() {
		return current_user_can( self::capability() ) || current_user_can( 'manage_options' );
	}

	public function admin_menu() {
		$ph   = class_exists( 'PropertyHive' );
		$hook = add_submenu_page(
			$ph ? 'propertyhive' : 'tools.php',
			__( 'Smart Location', 'ph-smart-location' ),
			__( 'Smart Location', 'ph-smart-location' ),
			$ph ? self::capability() : 'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
		add_action( 'admin_print_scripts-' . $hook, array( $this, 'enqueue' ) );
	}

	public function enqueue() {
		$url = plugin_dir_url( __FILE__ );
		wp_enqueue_style( 'phsl-admin', $url . 'assets/admin.css', array(), self::VERSION );
		wp_enqueue_script( 'phsl-admin', $url . 'assets/admin.js', array( 'jquery' ), self::VERSION, true );
		wp_localize_script( 'phsl-admin', 'phsl', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( self::NONCE ) ) );
	}

	private static function page_url( $tab = 'run', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG, 'tab' => $tab ), $args ), admin_url( class_exists( 'PropertyHive' ) ? 'admin.php' : 'tools.php' ) );
	}

	private function redirect( $tab, $msg ) {
		wp_safe_redirect( self::page_url( $tab, array( 'phsl_msg' => $msg ) ) );
		exit;
	}

	private function post_button( $action, $label, $fields = array(), $class = 'button', $confirm = '' ) {
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
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'ph-smart-location' ) );
		}
		$tabs = array( 'run' => 'Overview', 'results' => 'Results', 'log' => 'Log', 'settings' => 'Settings' );
		$tab  = sanitize_key( $_GET['tab'] ?? 'run' );
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'run';

		$messages = array(
			'settings_saved' => 'Settings saved.',
			'applied'        => 'Suggestions applied.',
			'retry'          => 'Property queued to be processed again.',
			'reset'          => 'Properties re-queued.',
			'log_cleared'    => 'Log cleared.',
		);
		echo '<div class="wrap phsl-wrap"><h1>' . esc_html__( 'Smart Location', 'ph-smart-location' ) . '</h1>';
		$msg = sanitize_key( $_GET['phsl_msg'] ?? '' );
		if ( isset( $messages[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $msg ] ) . '</p></div>';
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::page_url( $k ) ), $k === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';
		call_user_func( array( $this, 'render_' . $tab ) );
		echo '</div>';
	}

	private function render_run() {
		$s = self::settings();
		$c = $this->counts();
		$fields_label = $s['osm_fields'] ? implode( ', then ', $s['osm_fields'] ) : '(none selected)';
		?>
		<div class="phsl-card">
			<h2>Status</h2>
			<p>
				<strong>Mode:</strong>
				<?php if ( 'assign' === $s['mode'] ) : ?>
					<span class="phsl-badge phsl-assigned">Assign automatically</span>: empty fields are filled in.
				<?php else : ?>
					<span class="phsl-badge phsl-suggested">Suggest only</span>: nothing is saved to properties; review suggestions on the Results tab.
				<?php endif; ?>
			</p>
			<p><strong>Suburb:</strong> OpenStreetMap <code><?php echo esc_html( $fields_label ); ?></code>
				<?php if ( self::claude_enabled() ) : ?>
					, then <strong><?php echo esc_html( self::$models[ $s['claude_model'] ] ); ?></strong> when OpenStreetMap has none.
				<?php elseif ( $s['use_claude'] ) : ?>
					. <span class="phsl-warn">Claude is enabled but no API key is set.</span>
				<?php else : ?>
					. Claude is disabled.
				<?php endif; ?>
			</p>
			<p><strong>Region:</strong> <?php echo $s['region_enabled'] ? esc_html( sprintf( 'enabled (%s), %d rules%s', $s['region_key'], count( self::region_rules() ), $s['region_use_claude'] && self::claude_enabled() ? ', Claude for anything the rules don’t cover' : '' ) ) : 'disabled'; ?></p>
			<p><strong>New properties:</strong> <?php echo $s['auto_new'] ? 'processed automatically every 10 minutes' : 'not processed automatically'; ?>.</p>
			<table class="widefat phsl-counts">
				<tbody>
					<tr><td>Properties in scope</td><td class="num"><?php echo esc_html( number_format_i18n( $c['total'] ) ); ?></td></tr>
					<tr><td>Missing a suburb</td><td class="num"><?php echo esc_html( number_format_i18n( $c['missing_suburb'] ) ); ?></td></tr>
					<?php if ( isset( $c['missing_region'] ) ) : ?>
						<tr><td>Missing a region</td><td class="num"><?php echo esc_html( number_format_i18n( $c['missing_region'] ) ); ?></td></tr>
					<?php endif; ?>
					<tr><td>Waiting to be processed</td><td class="num" id="phsl-to-process"><?php echo esc_html( number_format_i18n( $c['to_process'] ) ); ?></td></tr>
					<?php foreach ( array( 'suggested' => 'Suggestions awaiting review', 'assigned' => 'Assigned', 'not_found' => 'Nothing found', 'error' => 'Errors' ) as $k => $label ) : ?>
						<tr><td><a href="<?php echo esc_url( self::page_url( 'results', array( 'status' => $k ) ) ); ?>"><?php echo esc_html( $label ); ?></a></td><td class="num"><?php echo esc_html( number_format_i18n( $c[ 'status_' . $k ] ?? 0 ) ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="phsl-card">
			<h2>Process existing properties</h2>
			<p class="description">Works through properties with an empty suburb<?php echo $s['region_enabled'] ? ' or region' : ''; ?>, newest first, one at a time, keeping within OpenStreetMap’s limit of one request per second. You can stop and resume at any time.</p>
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
				$this->post_button( 'phsl_reset', 'Retry “nothing found” and errors', array( 'only' => 'not_found' ), 'button' );
				$this->post_button( 'phsl_reset', 'Re-queue errors only', array( 'only' => 'error' ), 'button' );
				$this->post_button( 'phsl_reset', 'Re-queue everything', array(), 'button button-link-delete', 'Forget all previous attempts and suggestions? Properties will be looked up again.' );
				?>
			</p>
		</div>

		<div class="phsl-card">
			<h2>Test a property</h2>
			<p class="description">Shows what would be filled in for one property, without saving anything. If Claude is needed this makes a real (very cheap) API call.</p>
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
		$labels   = array( 'suggested' => 'Suggested', 'assigned' => 'Assigned', 'not_found' => 'Nothing found', 'error' => 'Error', 'skipped' => 'Skipped' );
		$status   = sanitize_key( $_GET['status'] ?? '' );
		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 50;
		$s        = self::settings();

		$where = $wpdb->prepare( 'st.meta_key = %s', self::META_STATUS );
		if ( isset( $labels[ $status ] ) ) {
			$where .= $wpdb->prepare( ' AND st.meta_value = %s', $status );
		}
		$from  = "{$wpdb->postmeta} st INNER JOIN {$wpdb->posts} p ON p.ID = st.post_id";
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$from} WHERE {$where}" ); // phpcs:ignore
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.post_title, st.meta_value AS status FROM {$from} WHERE {$where} ORDER BY st.meta_id DESC LIMIT %d OFFSET %d", $per_page, ( $paged - 1 ) * $per_page ) ); // phpcs:ignore
		?>
		<div class="phsl-toolbar">
			<form method="get" class="phsl-inline">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="hidden" name="tab" value="results">
				<select name="status" onchange="this.form.submit()">
					<option value="">All</option>
					<?php foreach ( $labels as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $status, $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>
			<?php $this->post_button( 'phsl_apply_all', 'Apply all suggestions', array(), 'button button-primary', 'Save all suggested values to their properties? Only empty fields are filled.' ); ?>
			<span class="description"><?php echo esc_html( sprintf( '%s results', number_format_i18n( $total ) ) ); ?></span>
		</div>
		<table class="widefat striped phsl-results">
			<thead><tr><th>Property</th><th>Suburb</th><?php echo $s['region_enabled'] ? '<th>Region</th>' : ''; ?><th>Claude</th><th>Status</th><th></th></tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="6">Nothing yet.</td></tr>
			<?php endif; ?>
			<?php
			foreach ( $rows as $row ) :
				$r = $this->load_result( $row->ID );
				?>
				<tr>
					<td><a href="<?php echo esc_url( get_edit_post_link( $row->ID ) ?: '#' ); ?>"><?php echo esc_html( $row->post_title ? $row->post_title : '#' . $row->ID ); ?></a>
						<?php if ( $r ) : ?><br><small><?php echo esc_html( wp_date( 'j M Y H:i', $r['time'] ) ); ?></small><?php endif; ?></td>
					<td>
						<?php if ( $r && '' !== $r['suburb'] ) : ?>
							<?php echo esc_html( $r['suburb'] ); ?><br><small><?php echo esc_html( $r['suburb_source'] ); ?><?php echo ! empty( $r['suburb_is_new'] ) ? ' · <strong>new suburb</strong>' : ''; ?></small>
						<?php elseif ( $r && ! empty( $r['suburb_low_confidence'] ) ) : ?>
							<em><?php echo esc_html( $r['suburb_low_confidence'] ); ?></em><br><small>Claude, low confidence: not used</small>
						<?php else : ?>–<?php endif; ?>
					</td>
					<?php if ( $s['region_enabled'] ) : ?>
						<td><?php echo $r && '' !== $r['region'] ? esc_html( $r['region'] ) . '<br><small>' . esc_html( $r['region_source'] ) . '</small>' : '–'; ?></td>
					<?php endif; ?>
					<td><small><?php echo ! empty( $r['claude'] ) ? esc_html( $r['claude']['confidence'] . ' confidence: ' . $r['claude']['reason'] ) : ''; ?></small></td>
					<td><span class="phsl-badge phsl-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $labels[ $row->status ] ?? $row->status ); ?></span>
						<?php if ( $r && in_array( $row->status, array( 'error', 'not_found' ), true ) ) : ?><br><small><?php echo esc_html( $r['message'] ); ?></small><?php endif; ?></td>
					<td class="phsl-actions">
						<?php
						if ( 'suggested' === $row->status ) {
							$this->post_button( 'phsl_apply', 'Apply', array( 'property_id' => $row->ID ), 'button button-small button-primary' );
						}
						$this->post_button( 'phsl_retry', 'Retry', array( 'property_id' => $row->ID ), 'button button-small' );
						?>
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
		global $wpdb;
		$level = sanitize_key( $_GET['level'] ?? '' );
		$sql   = 'SELECT * FROM ' . self::log_table();
		if ( in_array( $level, array( 'info', 'warning', 'error' ), true ) ) {
			$sql .= $wpdb->prepare( ' WHERE level = %s', $level );
		}
		$rows = $wpdb->get_results( $sql . ' ORDER BY id DESC LIMIT 1000' ); // phpcs:ignore
		?>
		<div class="phsl-toolbar">
			<form method="get" class="phsl-inline">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="hidden" name="tab" value="log">
				<select name="level" onchange="this.form.submit()">
					<option value="">All levels</option>
					<?php foreach ( array( 'info', 'warning', 'error' ) as $l ) : ?>
						<option value="<?php echo esc_attr( $l ); ?>" <?php selected( $level, $l ); ?>><?php echo esc_html( ucfirst( $l ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>
			<?php $this->post_button( 'phsl_clear_log', 'Clear log', array(), 'button button-link-delete', 'Clear the log?' ); ?>
			<span class="description">Latest 1,000 entries.</span>
		</div>
		<table class="widefat striped">
			<thead><tr><th>Time</th><th>Level</th><th>Property</th><th>Message</th></tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="4">The log is empty.</td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<tr class="phsl-log-<?php echo esc_attr( $row->level ); ?>">
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

	private function render_settings() {
		$s          = self::settings();
		$const_key  = defined( 'PHSL_ANTHROPIC_API_KEY' ) && PHSL_ANTHROPIC_API_KEY;
		$osm_labels = array(
			'suburb'        => 'Suburb',
			'neighbourhood' => 'Neighbourhood (usually smaller than a suburb)',
			'quarter'       => 'Quarter (e.g. “Jewellery Quarter”, business districts)',
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="phsl_save_settings">
			<?php wp_nonce_field( 'phsl_save_settings' ); ?>

			<h2>General</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Mode</th>
					<td>
						<label><input type="radio" name="mode" value="suggest" <?php checked( $s['mode'], 'suggest' ); ?>> <strong>Suggest only</strong>: work out values and list them on the Results tab for review; nothing is saved to properties.</label><br>
						<label><input type="radio" name="mode" value="assign" <?php checked( $s['mode'], 'assign' ); ?>> <strong>Assign automatically</strong>: save the values to the property.</label>
						<p class="description">Either way, only <strong>empty</strong> fields are ever filled in. Existing values are never changed.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">New properties</th>
					<td><label><input type="checkbox" name="auto_new" value="1" <?php checked( $s['auto_new'] ); ?>> Automatically process properties added from now on (checked every 10 minutes)</label>
						<p class="description">Existing properties are only processed when you run them from the Overview tab.</p></td>
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
					<th scope="row"><label for="phsl-suburb-key">Suburb field</label></th>
					<td><input type="text" id="phsl-suburb-key" name="suburb_key" class="regular-text code" value="<?php echo esc_attr( $s['suburb_key'] ); ?>">
						<p class="description">The property meta key the suburb is stored in.</p></td>
				</tr>
				<tr>
					<th scope="row">Smart assignment from OpenStreetMap</th>
					<td>
						<?php foreach ( self::$osm_choices as $f ) : ?>
							<label class="phsl-block"><input type="checkbox" name="osm_fields[]" value="<?php echo esc_attr( $f ); ?>" <?php checked( in_array( $f, $s['osm_fields'], true ) ); ?>> <?php echo esc_html( $osm_labels[ $f ] ); ?></label>
						<?php endforeach; ?>
						<p class="description">Which OpenStreetMap areas can be used as the suburb, tried in the order shown. If none of the selected areas is found, Claude is asked (if enabled).</p>
					</td>
				</tr>
			</table>

			<h2>Claude</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Use Claude</th>
					<td><label><input type="checkbox" name="use_claude" value="1" <?php checked( $s['use_claude'] ); ?>> Ask Claude when OpenStreetMap has no suburb<?php echo $s['region_enabled'] ? ' (and for regions the rules don’t cover, if enabled below)' : ''; ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="phsl-key">API key</label></th>
					<td>
						<?php if ( $const_key ) : ?>
							<p><span class="phsl-badge phsl-assigned">Set in wp-config.php</span> (<code>PHSL_ANTHROPIC_API_KEY</code>)</p>
						<?php else : ?>
							<input type="password" id="phsl-key" name="claude_api_key" class="regular-text" autocomplete="off" placeholder="<?php echo $s['claude_api_key'] ? esc_attr( 'Saved (…' . substr( $s['claude_api_key'], -4 ) . '). Leave blank to keep, enter - to remove.' ) : 'sk-ant-…'; ?>">
							<p class="description">Recommended instead: add <code>define( 'PHSL_ANTHROPIC_API_KEY', 'sk-ant-…' );</code> to wp-config.php, which keeps the key out of the database.</p>
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
					<p class="description">Each lookup costs a fraction of a penny. The token usage of every call is written to the log.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="phsl-conf">Minimum confidence</label></th>
					<td><select id="phsl-conf" name="claude_min_confidence">
						<?php foreach ( array( 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low (always use Claude’s answer)' ) as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['claude_min_confidence'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Claude’s answers below this confidence are recorded on the Results tab but not used.</p></td>
				</tr>
			</table>

			<h2>Region</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Enable</th>
					<td><label><input type="checkbox" name="region_enabled" value="1" <?php checked( $s['region_enabled'] ); ?>> Also fill in a region field</label></td>
				</tr>
				<tr>
					<th scope="row"><label for="phsl-region-key">Region field</label></th>
					<td><input type="text" id="phsl-region-key" name="region_key" class="regular-text code" value="<?php echo esc_attr( $s['region_key'] ); ?>">
						<label class="phsl-block"><input type="checkbox" name="region_multiple" value="1" <?php checked( $s['region_multiple'] ); ?>> Multi-select field (stored as a list)</label></td>
				</tr>
				<tr>
					<th scope="row"><label for="phsl-region-values">Allowed regions</label></th>
					<td><textarea id="phsl-region-values" name="region_values" rows="7" class="large-text code"><?php echo esc_textarea( $s['region_values'] ); ?></textarea>
						<p class="description">One per line, spelled exactly as the field’s options.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="phsl-region-rules">Rules</label></th>
					<td><textarea id="phsl-region-rules" name="region_rules" rows="8" class="large-text code" placeholder="Birmingham South = Edgbaston, Selly Oak, Kings Norton, B30, B31&#10;Birmingham Central = Jewellery Quarter, Birmingham City Centre, B1, B2, B3, B4, B5"><?php echo esc_textarea( $s['region_rules'] ); ?></textarea>
						<p class="description">One region per line: <code>Region = suburb, suburb, postcode…</code>. Postcodes can be districts (<code>B30</code>) or sectors (<code>B15 2</code>). Suburb names are matched first, then postcode sector, then district.</p></td>
				</tr>
				<tr>
					<th scope="row">Claude for regions</th>
					<td><label><input type="checkbox" name="region_use_claude" value="1" <?php checked( $s['region_use_claude'] ); ?>> Ask Claude to choose from the allowed regions when no rule matches (your rules are sent as guidance)</label></td>
				</tr>
			</table>

			<h2>OpenStreetMap</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="phsl-endpoint">Nominatim URL</label></th>
					<td><input type="url" id="phsl-endpoint" name="endpoint" class="regular-text" value="<?php echo esc_attr( $s['endpoint'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="phsl-email">Contact email</label></th>
					<td><input type="email" id="phsl-email" name="email" class="regular-text" value="<?php echo esc_attr( $s['email'] ); ?>">
						<p class="description">Optional; sent to OpenStreetMap as their usage policy recommends.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="phsl-delay">Delay between requests</label></th>
					<td><input type="number" id="phsl-delay" name="delay" step="0.1" min="0.1" class="small-text" value="<?php echo esc_attr( $s['delay'] ); ?>"> seconds <span class="description">(minimum 1 for the public service)</span></td>
				</tr>
				<tr>
					<th scope="row"><label for="phsl-lang">Language</label></th>
					<td><input type="text" id="phsl-lang" name="language" class="small-text" value="<?php echo esc_attr( $s['language'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row">Missing coordinates</th>
					<td><label><input type="checkbox" name="address_fallback" value="1" <?php checked( $s['address_fallback'] ); ?>> Search by address when a property has no latitude/longitude</label></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* =====================================================================
	 * Misc
	 * =================================================================== */

	public function log( $level, $message, $property_id = 0 ) {
		global $wpdb;
		$wpdb->insert( self::log_table(), array( 'logged_at' => current_time( 'mysql', true ), 'level' => $level, 'property_id' => (int) $property_id, 'message' => $message ) );
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
			wp_die( 'Permission denied' );
		}
	}
}

PH_Smart_Location::instance();
