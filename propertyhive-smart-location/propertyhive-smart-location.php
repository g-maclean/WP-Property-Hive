<?php
/**
 * Plugin Name: Property Hive Smart Location
 * Description: Automatically assigns a property's suburb as a Location (creating the Location if needed), and optionally a region, using OpenStreetMap with Claude AI as a fallback. Only empty fields are ever filled.
 * Version: 2.0.0
 * Author: Property Hive
 * Requires at least: 5.6
 * Requires PHP: 7.2
 * Text Domain: ph-smart-location
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-phsl-logger.php';

final class PH_Smart_Location {

	const VERSION       = '2.0.0';
	const DB_VERSION    = '2';
	const OPTION        = 'phsl_settings';
	const PAGE_SLUG     = 'ph-smart-location';
	const NONCE         = 'phsl_nonce';
	const LOCK_OPTION   = 'phsl_lock';
	const CRON_HOOK     = 'phsl_cron';
	const TAXONOMY      = 'location';
	const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
	const API_VERSION   = '2026-10-21';

	/**
	 * The only two post meta keys the plugin writes.
	 *
	 * _phsl_status: assigned | suggested | not_found | error | skipped | retry
	 * _phsl:        small array of flags:
	 *   t   unix time of the last attempt
	 *   l   Location term ids Smart Location assigned (re-applied if an import removes them)
	 *   o   OpenStreetMap: 1 = usable area found, 0 = looked up, nothing usable, x = request failed
	 *   a   Claude: used | unsure | error | nokey (enabled, no API key) – absent if not needed/disabled
	 *   src where the suburb came from (osm:suburb, claude, …)
	 *   n   transient-failure count (while status is "retry")
	 *   v   suggestion: list of array( f => OSM field, n => name, t => existing term id or 0, p => parent for a new term )
	 *   r   suggested region
	 * Everything else goes to the log file.
	 *
	 * The option phsl_location_types records which Location terms are suburbs / neighbourhoods /
	 * quarters. A property that already has any of them (e.g. matched from its address by the import
	 * add-on) needs no OpenStreetMap or Claude lookup.
	 */
	const META_STATUS = '_phsl_status';
	const META_STATE  = '_phsl';
	const TYPES_OPTION = 'phsl_location_types';

	/** OSM fields that can be used for smart suburb assignment, in priority order. */
	public static $osm_choices = array( 'suburb', 'neighbourhood', 'quarter' );

	/** OSM area fields passed to Claude as context and logged. */
	public static $osm_context_fields = array( 'neighbourhood', 'suburb', 'quarter', 'residential', 'hamlet', 'village', 'borough', 'city_district', 'town', 'city', 'county' );

	/** OSM fields tried, in order, to find a parent for a newly created Location. */
	public static $parent_fields = array( 'town', 'city', 'village', 'municipality', 'county' );

	public static $models = array(
		'claude-opus-5-5'   => 'Claude Opus 5.5 (most accurate)',
		'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
		'claude-haiku-5-5'  => 'Claude Haiku 5.5 (cheapest)',
	);

	private static $instance = null;

	private $vocab      = null;
	private $terms      = null; // term_id => WP_Term
	private $term_index = null; // normalised name => term ids
	private $last_osm   = array();

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

		add_action( 'delete_term', array( $this, 'on_delete_term' ), 10, 3 );

		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 99 );

		add_action( 'wp_ajax_phsl_step', array( $this, 'ajax_step' ) );
		add_action( 'wp_ajax_phsl_test', array( $this, 'ajax_test' ) );

		foreach ( array( 'save_settings', 'apply', 'apply_all', 'retry', 'requeue', 'clear_log', 'download_log' ) as $action ) {
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
			// 1.x kept a database log and verbose post meta. Drop both and start tracking afresh.
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}phsl_log" ); // phpcs:ignore
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s, %s)", '_phsl_status', '_phsl_result', '_phsl_attempted', '_phsl_tries' ) );
			$this->log( 'info', 'Upgraded to 2.0: old database log and tracking data removed; properties will be processed again.' );
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
			'suburb_target'         => 'taxonomy',
			'suburb_key'            => '_suburb',
			'create_terms'          => 1,
			'new_term_parent'       => 'auto',
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
			'endpoint'              => 'https://nominatim.openstreetmap.org',
			'email'                 => '',
			'delay'                 => 1.5,
			'language'              => 'en',
			'address_fallback'      => 1,
		);
	}

	public static function settings() {
		$s = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$s['osm_fields'] = array_values( array_intersect( self::$osm_choices, (array) $s['osm_fields'] ) );
		$s['delay']      = max( self::min_delay( $s['endpoint'] ), (float) $s['delay'] );
		if ( empty( $s['statuses'] ) || ! is_array( $s['statuses'] ) ) {
			$s['statuses'] = array( 'publish' );
		}
		if ( ! isset( self::$models[ $s['claude_model'] ] ) ) {
			$s['claude_model'] = 'claude-opus-5-5';
		}
		if ( 'meta' !== $s['suburb_target'] ) {
			$s['suburb_target'] = 'taxonomy';
		}
		return $s;
	}

	private static function uses_taxonomy() {
		return 'taxonomy' === self::settings()['suburb_target'];
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

	public static function region_values() {
		return array_values( array_unique( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) self::settings()['region_values'] ) ) ) ) );
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
		$parent   = (string) ( $p['new_term_parent'] ?? 'auto' );

		$new = array(
			'mode'                  => 'assign' === ( $p['mode'] ?? '' ) ? 'assign' : 'suggest',
			'on_import'             => empty( $p['on_import'] ) ? 0 : 1,
			'auto_new'              => empty( $p['auto_new'] ) ? 0 : 1,
			'statuses'              => $statuses ? $statuses : array( 'publish' ),
			'suburb_target'         => 'meta' === ( $p['suburb_target'] ?? '' ) ? 'meta' : 'taxonomy',
			'suburb_key'            => self::sanitize_meta_key( $p['suburb_key'] ?? '_suburb', '_suburb' ),
			'create_terms'          => empty( $p['create_terms'] ) ? 0 : 1,
			'new_term_parent'       => in_array( $parent, array( 'auto', 'top' ), true ) ? $parent : (string) absint( $parent ),
			'osm_fields'            => array_values( array_intersect( self::$osm_choices, (array) ( $p['osm_fields'] ?? array() ) ) ),
			'use_claude'            => empty( $p['use_claude'] ) ? 0 : 1,
			'claude_model'          => isset( self::$models[ $p['claude_model'] ?? '' ] ) ? $p['claude_model'] : 'claude-opus-5-5',
			// Blank keeps the saved key (the field is never pre-filled); "-" removes it.
			'claude_api_key'        => '-' === $key_in ? '' : ( '' === $key_in ? $old['claude_api_key'] : sanitize_text_field( $key_in ) ),
			'claude_min_confidence' => in_array( $p['claude_min_confidence'] ?? '', array( 'high', 'medium', 'low' ), true ) ? $p['claude_min_confidence'] : 'medium',
			'region_enabled'        => empty( $p['region_enabled'] ) ? 0 : 1,
			'region_key'            => self::sanitize_meta_key( $p['region_key'] ?? '_region', '_region' ),
			'region_multiple'       => empty( $p['region_multiple'] ) ? 0 : 1,
			'region_values'         => sanitize_textarea_field( $p['region_values'] ?? '' ),
			'region_rules'          => sanitize_textarea_field( $p['region_rules'] ?? '' ),
			'region_use_claude'     => empty( $p['region_use_claude'] ) ? 0 : 1,
			'endpoint'              => untrailingslashit( $endpoint ),
			'email'                 => sanitize_email( $p['email'] ?? '' ),
			'delay'                 => max( self::min_delay( $endpoint ), (float) ( $p['delay'] ?? 1.5 ) ),
			'language'              => sanitize_text_field( $p['language'] ?? 'en' ),
			'address_fallback'      => empty( $p['address_fallback'] ) ? 0 : 1,
		);
		update_option( self::OPTION, $new, false );

		$changes = array();
		foreach ( $new as $k => $v ) {
			if ( 'claude_api_key' !== $k && $old[ $k ] != $v ) { // phpcs:ignore -- loose on purpose
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

	/** @return array( outward, sector ) e.g. ( 'B15', 'B15 2' ). */
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

	/* ---------- Tracked Location types (wp_options) ---------- */

	/** @return array type => term ids, e.g. array( 'suburb' => array( 12, 15 ), 'neighbourhood' => array( 31 ), 'quarter' => array() ). */
	public static function location_types() {
		$saved = get_option( self::TYPES_OPTION, array() );
		$out   = array();
		foreach ( self::$osm_choices as $t ) {
			$out[ $t ] = isset( $saved[ $t ] ) ? array_values( array_map( 'intval', (array) $saved[ $t ] ) ) : array();
		}
		return $out;
	}

	public static function tracked_term_ids() {
		$all = array();
		foreach ( self::location_types() as $ids ) {
			$all = array_merge( $all, $ids );
		}
		return array_values( array_unique( $all ) );
	}

	private function track_term( $type, $term_id ) {
		$types = self::location_types();
		if ( ! $term_id || ! isset( $types[ $type ] ) || in_array( (int) $term_id, $types[ $type ], true ) ) {
			return;
		}
		$types[ $type ][] = (int) $term_id;
		update_option( self::TYPES_OPTION, $types, true );
	}

	/** Keep the lists tidy when a Location is deleted. */
	public function on_delete_term( $term_id, $tt_id, $taxonomy ) {
		if ( self::TAXONOMY !== $taxonomy ) {
			return;
		}
		$types   = self::location_types();
		$changed = false;
		foreach ( $types as $t => $ids ) {
			if ( in_array( (int) $term_id, $ids, true ) ) {
				$types[ $t ] = array_values( array_diff( $ids, array( (int) $term_id ) ) );
				$changed     = true;
			}
		}
		if ( $changed ) {
			update_option( self::TYPES_OPTION, $types, true );
		}
	}

	/* ---------- Location terms ---------- */

	private function load_terms() {
		if ( null !== $this->terms ) {
			return;
		}
		$this->terms      = array();
		$this->term_index = array();
		$terms            = get_terms( array( 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			return;
		}
		foreach ( $terms as $t ) {
			$this->terms[ $t->term_id ]                        = $t;
			$this->term_index[ self::normalise( $t->name ) ][] = (int) $t->term_id;
		}
		/** Aliases: array( 'name OSM/Claude uses' => term_id ). */
		foreach ( (array) apply_filters( 'phsl_location_aliases', array() ) as $alias => $term_id ) {
			$this->term_index[ self::normalise( $alias ) ][] = (int) $term_id;
		}
	}

	private function forget_terms() {
		$this->terms      = null;
		$this->term_index = null;
	}

	private function term_ancestors( $term_id ) {
		$this->load_terms();
		$out   = array();
		$guard = 0;
		while ( isset( $this->terms[ $term_id ] ) && $this->terms[ $term_id ]->parent && $guard++ < 20 ) {
			$term_id = (int) $this->terms[ $term_id ]->parent;
			$out[]   = $term_id;
		}
		return $out;
	}

	public function term_path( $term_id ) {
		$this->load_terms();
		$names = array();
		foreach ( array_reverse( array_merge( array( (int) $term_id ), $this->term_ancestors( $term_id ) ) ) as $id ) {
			if ( isset( $this->terms[ $id ] ) ) {
				$names[] = $this->terms[ $id ]->name;
			}
		}
		return implode( ' › ', $names );
	}

	/**
	 * Find an existing Location by name. Where several share a name (e.g. "Newtown" in two towns),
	 * prefer the one whose parent appears in the OSM address.
	 */
	private function find_term( $name, $address = array() ) {
		$this->load_terms();
		$key = self::normalise( $name );
		if ( '' === $key || empty( $this->term_index[ $key ] ) ) {
			return 0;
		}
		$ids = array_values( array_unique( $this->term_index[ $key ] ) );
		if ( count( $ids ) > 1 ) {
			$context = array();
			foreach ( self::$osm_context_fields as $f ) {
				if ( ! empty( $address[ $f ] ) && is_string( $address[ $f ] ) ) {
					$context[] = self::normalise( $address[ $f ] );
				}
			}
			foreach ( $ids as $id ) {
				foreach ( $this->term_ancestors( $id ) as $a ) {
					if ( isset( $this->terms[ $a ] ) && in_array( self::normalise( $this->terms[ $a ]->name ), $context, true ) ) {
						return $id;
					}
				}
			}
		}
		return $ids[0];
	}

	/** Parent for a newly created Location. */
	private function parent_for_new_term( $address ) {
		$this->load_terms();
		$setting = self::settings()['new_term_parent'];
		if ( 'top' === $setting ) {
			return 0;
		}
		if ( 'auto' !== $setting ) {
			return isset( $this->terms[ (int) $setting ] ) ? (int) $setting : 0;
		}
		foreach ( self::$parent_fields as $f ) {
			if ( ! empty( $address[ $f ] ) && is_string( $address[ $f ] ) && ( $id = $this->find_term( $address[ $f ], $address ) ) ) {
				return $id;
			}
		}
		return 0;
	}

	private function create_term( $name, $parent ) {
		$r = wp_insert_term( $name, self::TAXONOMY, array( 'parent' => (int) $parent ) );
		if ( is_wp_error( $r ) ) {
			if ( 'term_exists' === $r->get_error_code() && $r->get_error_data() ) {
				return (int) $r->get_error_data();
			}
			return $r;
		}
		$this->forget_terms();
		return (int) $r['term_id'];
	}

	/* ---------- Current values on a property ---------- */

	private function assigned_term_ids( $property_id ) {
		$ids = wp_get_object_terms( $property_id, self::TAXONOMY, array( 'fields' => 'ids' ) );
		return is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
	}

	/**
	 * Does the property still need its suburb working out?
	 * Location mode: it has no Location that is a known suburb / neighbourhood / quarter
	 * (other Locations, e.g. a town matched from the address, don't count).
	 */
	private function suburb_is_empty( $property_id ) {
		if ( self::uses_taxonomy() ) {
			return ! array_intersect( $this->assigned_term_ids( $property_id ), self::tracked_term_ids() );
		}
		return ! self::meta_values( $property_id, self::settings()['suburb_key'] );
	}

	/** Names describing the property's current suburb (Location terms include their parents). */
	private function current_suburb_names( $property_id ) {
		if ( ! self::uses_taxonomy() ) {
			return self::meta_values( $property_id, self::settings()['suburb_key'] );
		}
		$this->load_terms();
		$names = array();
		foreach ( $this->assigned_term_ids( $property_id ) as $id ) {
			foreach ( array_merge( array( $id ), $this->term_ancestors( $id ) ) as $t ) {
				if ( isset( $this->terms[ $t ] ) ) {
					$names[] = $this->terms[ $t ]->name;
				}
			}
		}
		return array_values( array_unique( $names ) );
	}

	private function current_suburb_label( $property_id ) {
		if ( ! self::uses_taxonomy() ) {
			return implode( ', ', self::meta_values( $property_id, self::settings()['suburb_key'] ) );
		}
		return implode( ', ', array_map( array( $this, 'term_path' ), $this->assigned_term_ids( $property_id ) ) );
	}

	/** Suburbs already used, normalised => spelling (custom field mode). */
	private function meta_vocab() {
		global $wpdb;
		if ( null !== $this->vocab ) {
			return $this->vocab;
		}
		$vocab = array();
		$raws  = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = 'property' AND pm.meta_value <> ''", self::settings()['suburb_key'] ) );
		foreach ( $raws as $raw ) {
			foreach ( self::split_values( $raw ) as $v ) {
				$n = self::normalise( $v );
				if ( '' !== $n && ! isset( $vocab[ $n ] ) ) {
					$vocab[ $n ] = $v;
				}
			}
		}
		return $this->vocab = $vocab;
	}

	/* ---------- Region rules ---------- */

	/** "Birmingham South = Edgbaston, Selly Oak, B30, B15 2" – suburbs, postcode districts or sectors. */
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

	public static function region_from_rules( $suburbs, $postcode ) {
		$rules = self::region_rules();
		$norm  = array_map( array( __CLASS__, 'normalise' ), (array) $suburbs );
		foreach ( $rules as $r ) {
			foreach ( $norm as $n ) {
				if ( in_array( $n, $r['suburbs'], true ) ) {
					return array( $r['region'], 'rule:suburb' );
				}
			}
		}
		list( $outward, $sector ) = self::postcode_parts( $postcode );
		foreach ( array( $sector, $outward ) as $pc ) {
			foreach ( $rules as $r ) {
				if ( '' !== $pc && in_array( $pc, $r['postcodes'], true ) ) {
					return array( $r['region'], 'rule:postcode' );
				}
			}
		}
		return array( '', '' );
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
			return null !== $v && '' !== $v;
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
		foreach ( $rows as $row ) {
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

	private function suburb_empty_sql() {
		global $wpdb;
		if ( self::uses_taxonomy() ) {
			$ids = self::tracked_term_ids();
			if ( ! $ids ) {
				return '1=1';
			}
			return $wpdb->prepare( "NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = p.ID AND tt.taxonomy = %s AND tt.term_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . '))', self::TAXONOMY ); // phpcs:ignore
		}
		return $this->meta_empty_sql( self::settings()['suburb_key'] );
	}

	private function meta_empty_sql( $key ) {
		global $wpdb;
		return $wpdb->prepare( "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} em WHERE em.post_id = p.ID AND em.meta_key = %s AND em.meta_value <> '' AND em.meta_value <> 'a:0:{}')", $key );
	}

	private function needs_sql() {
		$s     = self::settings();
		$needs = array( $this->suburb_empty_sql() );
		if ( $s['region_enabled'] ) {
			$needs[] = $this->meta_empty_sql( $s['region_key'] );
		}
		return '(' . implode( ' OR ', $needs ) . ')';
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
		$s     = self::settings();
		$scope = $this->scope_sql();
		$out   = array(
			'total'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$scope}" ), // phpcs:ignore
			'missing_suburb' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$scope} AND " . $this->suburb_empty_sql() ), // phpcs:ignore
			'to_process'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$scope} AND " . $this->needs_sql() . ' AND ' . $this->pending_sql() ), // phpcs:ignore
		);
		if ( $s['region_enabled'] ) {
			$out['missing_region'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$scope} AND " . $this->meta_empty_sql( $s['region_key'] ) ); // phpcs:ignore
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS status, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value", self::META_STATUS ) ) as $row ) {
			$out[ 'status_' . $row->status ] = (int) $row->n;
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
		if ( ! $s['on_import'] || 'property' !== get_post_type( $post_id ) ) {
			return;
		}

		if ( self::uses_taxonomy() && $this->suburb_is_empty( $post_id ) ) {
			// The import's own address matching replaces a property's Locations on every run and can
			// remove the ones Smart Location added. Put them back – no lookups needed.
			$state = self::get_state( $post_id );
			if ( ! empty( $state['l'] ) ) {
				$this->load_terms();
				$ids = array_values( array_filter( array_map( 'intval', (array) $state['l'] ), function ( $id ) {
					return isset( $this->terms[ $id ] );
				} ) );
				if ( $ids ) {
					wp_set_object_terms( $post_id, $ids, self::TAXONOMY, true );
					$this->log( 'info', 'Re-applied Locations removed by the import: ' . implode( ', ', array_map( array( $this, 'term_path' ), $ids ) ), $post_id );
					return;
				}
			}
		}

		if ( $halted || isset( $seen[ $post_id ] ) ) {
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
		if ( ! $this->suburb_is_empty( $post_id ) && ! ( $s['region_enabled'] && ! self::meta_values( $post_id, $s['region_key'] ) ) ) {
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
	 * Work out (and, in assign mode, save) the suburb/region for one property.
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
			'items'       => array(), // Location mode: array( f => OSM field, n => name, t => term id or 0, p => parent for new )
			'l'           => array_map( 'intval', (array) ( $prev['l'] ?? array() ) ),
			'region'      => '',
			'region_src'  => '',
			'o'           => null,
			'a'           => null,
			'n'           => (int) ( $prev['n'] ?? 0 ),
			'notes'       => array(),
			'context'     => array(),
		);

		$need_suburb = $this->suburb_is_empty( $pid );
		$need_region = $s['region_enabled'] && ! self::meta_values( $pid, $s['region_key'] );
		if ( ! $need_suburb && ! $need_region ) {
			$r['status']  = 'skipped';
			$r['notes'][] = 'nothing to fill in';
			return $this->finish( $r, $dry_run );
		}

		// Suburb already known and only the region is missing: the rules may answer without any lookup.
		if ( ! $need_suburb && $need_region ) {
			list( $r['region'], $r['region_src'] ) = self::region_from_rules( $this->current_suburb_names( $pid ), $postcode );
			if ( '' !== $r['region'] ) {
				$r['status'] = 'assign' === $s['mode'] ? 'assigned' : 'suggested';
				if ( ! $dry_run && 'assigned' === $r['status'] ) {
					$this->save_values( $pid, $r );
				}
				return $this->finish( $r, $dry_run );
			}
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
			if ( 'x' === $r['o'] && ! self::claude_enabled() ) {
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

		// Every ticked OSM area that is present (suburb, neighbourhood, quarter).
		if ( $need_suburb ) {
			foreach ( $s['osm_fields'] as $f ) {
				if ( ! empty( $address[ $f ] ) && is_string( $address[ $f ] ) ) {
					$r['items'][] = array( 'f' => $f, 'n' => trim( $address[ $f ] ) );
				}
			}
			if ( $r['items'] ) {
				$r['suburb'] = $r['items'][0]['n'];
				$r['src']    = 'osm:' . $r['items'][0]['f'];
			}
		}
		if ( null === $r['o'] ) {
			$r['o'] = $r['items'] ? 1 : 0;
		}

		$suburbs_for_region = $need_suburb ? wp_list_pluck( $r['items'], 'n' ) : $this->current_suburb_names( $pid );
		if ( $need_region ) {
			list( $r['region'], $r['region_src'] ) = self::region_from_rules( $suburbs_for_region, $postcode );
		}

		/* 2. Claude, for whatever OSM / the rules could not answer */
		$ask_suburb = $need_suburb && '' === $r['suburb'];
		$ask_region = $need_region && '' === $r['region'] && $s['region_use_claude'] && self::region_values();
		if ( ( $ask_suburb || $ask_region ) && $s['use_claude'] ) {
			if ( ! self::claude_enabled() ) {
				$r['a']       = 'nokey';
				$r['notes'][] = 'Claude skipped: no API key';
			} else {
				$c = $this->claude_lookup( $pid, $postcode, $osm, $ask_suburb, $ask_region, $suburbs_for_region );
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
					if ( $ask_suburb && '' !== $c['suburb'] ) {
						if ( $confident ) {
							$r['suburb']  = $c['suburb'];
							$r['src']     = 'claude';
							$r['items'][] = array( 'f' => 'suburb', 'n' => $c['suburb'] );
						} else {
							$r['notes'][] = sprintf( 'Claude suggested "%s" with low confidence (not used)', $c['suburb'] );
						}
					}
					if ( $ask_region ) {
						if ( 'claude' === $r['src'] ) {
							list( $r['region'], $r['region_src'] ) = self::region_from_rules( array( $r['suburb'] ), $postcode );
						}
						if ( '' === $r['region'] && '' !== $c['region'] && $confident ) {
							$r['region']     = $c['region'];
							$r['region_src'] = 'claude';
						}
					}
				}
			}
		}

		/* 3. Match to existing Locations (or plan new ones) / existing spelling */
		if ( '' !== $r['suburb'] ) {
			if ( self::uses_taxonomy() ) {
				$r['items'] = $this->resolve_items( $r['items'], $address, $r['notes'], $dry_run );
				$r['suburb'] = $r['items'] ? $r['items'][0]['n'] : '';
				if ( ! $r['items'] ) {
					$r['src'] = '';
				}
			} else {
				$vocab = $this->meta_vocab();
				$n     = self::normalise( $r['suburb'] );
				if ( isset( $vocab[ $n ] ) ) {
					$r['suburb'] = $vocab[ $n ];
				} else {
					$r['notes'][] = 'new suburb';
				}
			}
		}

		if ( '' === $r['suburb'] && '' === $r['region'] ) {
			// A temporary Claude failure is worth retrying; anything else is final.
			$r['status'] = 'error' === $r['a'] ? 'retry' : 'not_found';
			return $this->finish( $r, $dry_run );
		}

		$r['status'] = 'assign' === $s['mode'] ? 'assigned' : 'suggested';
		if ( ! $dry_run && 'assigned' === $r['status'] ) {
			$saved = $this->save_values( $pid, $r );
			if ( is_wp_error( $saved ) ) {
				$r['status']  = 'error';
				$r['notes'][] = $saved->get_error_message();
			}
		}
		return $this->finish( $r, $dry_run );
	}

	/**
	 * Match each OSM/Claude area to an existing Location, or plan a new one.
	 * Existing matches are recorded in the type lists straight away (that is a fact about the
	 * Location, whether or not the property is updated).
	 *
	 * New suburbs go under the OSM town/city (per settings); new neighbourhoods/quarters go under
	 * the suburb found in the same lookup (p = -1 means "the suburb created alongside it").
	 */
	private function resolve_items( $items, $address, &$notes, $dry_run ) {
		$s         = self::settings();
		$out       = array();
		$seen      = array();
		$suburb_it = null;
		foreach ( $items as $it ) {
			$tid = $this->find_term( $it['n'], $address );
			$key = $tid ? 't' . $tid : 'n' . self::normalise( $it['n'] );
			if ( isset( $seen[ $key ] ) ) {
				continue; // e.g. OSM suburb and quarter with the same name
			}
			$seen[ $key ] = true;
			if ( $tid ) {
				$it['t'] = $tid;
				$it['n'] = $this->terms[ $tid ]->name;
				if ( ! $dry_run ) {
					$this->track_term( $it['f'], $tid );
				}
			} elseif ( $s['create_terms'] ) {
				$it['t'] = 0;
				if ( 'suburb' !== $it['f'] && $suburb_it ) {
					$it['p'] = $suburb_it['t'] ? $suburb_it['t'] : -1;
				} else {
					$it['p'] = $this->parent_for_new_term( $address );
				}
				$notes[] = sprintf( 'new %s Location "%s" under %s', $it['f'], $it['n'], -1 === $it['p'] ? 'the new suburb' : ( $it['p'] ? '"' . $this->term_path( $it['p'] ) . '"' : 'top level' ) );
			} else {
				$notes[] = sprintf( 'Location "%s" (%s) does not exist and creating Locations is switched off', $it['n'], $it['f'] );
				continue;
			}
			if ( 'suburb' === $it['f'] && ! $suburb_it ) {
				$suburb_it = $it;
			}
			$out[] = $it;
		}
		return $out;
	}

	/**
	 * Save values. Locations are added alongside any already on the property (never removed);
	 * a custom-field suburb and the region are only written when empty. Creates Locations if needed.
	 *
	 * @return true|WP_Error
	 */
	private function save_values( $pid, &$r ) {
		$s = self::settings();
		if ( '' !== $r['suburb'] && $this->suburb_is_empty( $pid ) ) {
			if ( self::uses_taxonomy() ) {
				$ids        = array();
				$suburb_tid = 0;
				foreach ( $r['items'] as &$it ) {
					$tid = (int) ( $it['t'] ?? 0 );
					if ( ! $tid ) {
						$tid = $this->find_term( $it['n'] ); // may have been created since the suggestion
					}
					if ( ! $tid ) {
						$parent = (int) ( $it['p'] ?? 0 );
						$tid    = $this->create_term( $it['n'], -1 === $parent ? $suburb_tid : $parent );
						if ( is_wp_error( $tid ) ) {
							return new WP_Error( 'term', 'Could not create Location "' . $it['n'] . '": ' . $tid->get_error_message() );
						}
						$this->log( 'info', sprintf( 'Created %s Location "%s"', $it['f'], $this->term_path( $tid ) ), $pid );
					}
					if ( 'suburb' === $it['f'] && ! $suburb_tid ) {
						$suburb_tid = $tid;
					}
					$this->track_term( $it['f'], $tid );
					$it['t'] = $tid;
					$ids[]   = $tid;
				}
				unset( $it );
				$set = wp_set_object_terms( $pid, $ids, self::TAXONOMY, true );
				if ( is_wp_error( $set ) ) {
					return $set;
				}
				$r['l'] = array_values( array_unique( array_merge( (array) ( $r['l'] ?? array() ), $ids ) ) );
			} else {
				update_post_meta( $pid, $s['suburb_key'], $r['suburb'] );
				$this->vocab = null;
			}
		}
		if ( $s['region_enabled'] && '' !== $r['region'] && ! self::meta_values( $pid, $s['region_key'] ) ) {
			update_post_meta( $pid, $s['region_key'], $s['region_multiple'] ? array( $r['region'] ) : $r['region'] );
		}
		do_action( 'phsl_values_assigned', $pid, $r );
		return true;
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
		if ( $r['items'] ) {
			foreach ( $r['items'] as $it ) {
				$parts[] = sprintf( '%s "%s"%s', $it['f'], ! empty( $it['t'] ) ? $this->term_path( $it['t'] ) : $it['n'], empty( $it['t'] ) ? ' (new)' : '' );
			}
			$parts[ count( $parts ) - 1 ] .= ' from ' . ( 'claude' === $r['src'] ? 'Claude' : 'OSM' );
		} elseif ( '' !== $r['suburb'] ) {
			$parts[] = sprintf( 'suburb "%s" (%s)', $r['suburb'], $r['src'] );
		}
		if ( '' !== $r['region'] ) {
			$parts[] = sprintf( 'region "%s" (%s)', $r['region'], $r['region_src'] );
		}
		$r['message'] = ucfirst( str_replace( '_', ' ', $r['status'] ) ) . ( $parts ? ': ' . implode( '; ', $parts ) : '' ) . ( $r['notes'] ? ' – ' . implode( '; ', $r['notes'] ) : '' );
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
			'n'   => 'retry' === $r['status'] ? $r['n'] : null,
			'l'   => $r['l'] ? $r['l'] : null,
		);
		if ( 'suggested' === $r['status'] ) {
			$state['v'] = self::uses_taxonomy() ? $r['items'] : $r['suburb'];
			$state['r'] = $r['region'];
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

	private function claude_lookup( $pid, $postcode, $osm, $ask_suburb, $ask_region, $known_suburbs ) {
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
		$response = wp_remote_post( self::ANTHROPIC_URL, array( 'timeout' => 90, 'headers' => $headers, 'body' => wp_json_encode( $body ) ) );
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
				'property'       => get_the_title( $pid ),
				'current_suburb' => $this->current_suburb_label( $pid ),
				'current_region' => $s['region_enabled'] ? implode( ', ', self::meta_values( $pid, $s['region_key'] ) ) : '(region disabled)',
				'note'           => 'Test only: nothing was saved, created or recorded. A property that already has a known suburb / neighbourhood / quarter Location is skipped.',
				'result'         => $r,
				'osm_request'    => $this->last_osm,
			)
		);
	}

	/* =====================================================================
	 * admin-post actions
	 * =================================================================== */

	private function apply_one( $pid ) {
		if ( 'suggested' !== get_post_meta( $pid, self::META_STATUS, true ) ) {
			return false;
		}
		$state = self::get_state( $pid );
		$v     = $state['v'] ?? '';
		$items = is_array( $v ) ? $v : array();
		$r     = array(
			'items'  => $items,
			'suburb' => is_array( $v ) ? ( $items ? (string) $items[0]['n'] : '' ) : (string) $v,
			'region' => (string) ( $state['r'] ?? '' ),
			'src'    => (string) ( $state['src'] ?? '' ),
			'l'      => (array) ( $state['l'] ?? array() ),
		);
		$saved = $this->save_values( $pid, $r );
		if ( is_wp_error( $saved ) ) {
			$this->log( 'error', 'Apply failed: ' . $saved->get_error_message(), $pid );
			return false;
		}
		unset( $state['v'], $state['r'] );
		$state['l'] = $r['l'] ? $r['l'] : null;
		$this->set_state( $pid, 'assigned', $state );
		$this->log( 'info', sprintf( 'Assigned (applied by %s): %s%s', wp_get_current_user()->user_login, $items ? implode( ', ', array_map( array( $this, 'term_path' ), wp_list_pluck( $r['items'], 't' ) ) ) : 'suburb "' . $r['suburb'] . '"', '' !== $r['region'] ? '; region "' . $r['region'] . '"' : '' ), $pid );
		return true;
	}

	public function handle_apply() {
		$this->check_admin_request( 'phsl_apply' );
		$this->apply_one( absint( $_POST['property_id'] ?? 0 ) );
		$this->redirect( 'results', 'applied' );
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

	private function user_can() {
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
			wp_die( 'Sorry, you are not allowed to access this page.' );
		}
		$tabs = array( 'run' => 'Overview', 'results' => 'Results', 'log' => 'Log', 'settings' => 'Settings' );
		$tab  = sanitize_key( $_GET['tab'] ?? 'run' );
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'run';
		$msgs = array(
			'settings_saved' => 'Settings saved.',
			'applied'        => 'Suggestions applied.',
			'retry'          => 'Property queued to be processed again.',
			'requeued'       => 'Properties re-queued.',
			'log_cleared'    => 'Log files deleted.',
		);
		echo '<div class="wrap phsl-wrap"><h1>Smart Location</h1>';
		$msg = sanitize_key( $_GET['phsl_msg'] ?? '' );
		if ( isset( $msgs[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msgs[ $msg ] ) . '</p></div>';
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
		$s      = self::settings();
		$c      = $this->counts();
		$target = self::uses_taxonomy() ? 'Location' : 'custom field ' . $s['suburb_key'];
		$when   = array();
		if ( $s['on_import'] ) {
			$when[] = 'straight after each import';
		}
		if ( $s['auto_new'] ) {
			$when[] = 'every 10 minutes';
		}
		?>
		<div class="phsl-card">
			<h2>Status</h2>
			<p><strong>Mode:</strong>
				<?php if ( 'assign' === $s['mode'] ) : ?>
					<span class="phsl-badge phsl-assigned">Assign automatically</span>: empty fields are filled in<?php echo self::uses_taxonomy() && $s['create_terms'] ? ' and missing Locations are created' : ''; ?>.
				<?php else : ?>
					<span class="phsl-badge phsl-suggested">Suggest only</span>: nothing is saved or created; review suggestions on the Results tab.
				<?php endif; ?>
			</p>
			<p><strong>Suburb → <?php echo esc_html( $target ); ?>:</strong> OpenStreetMap <code><?php echo esc_html( $s['osm_fields'] ? implode( ', then ', $s['osm_fields'] ) : '(no fields selected)' ); ?></code>
				<?php if ( self::claude_enabled() ) : ?>, then <strong><?php echo esc_html( self::$models[ $s['claude_model'] ] ); ?></strong>.
				<?php elseif ( $s['use_claude'] ) : ?>. <span class="phsl-warn">Claude is enabled but there is no API key.</span>
				<?php else : ?>. Claude is off.<?php endif; ?>
			</p>
			<p><strong>Region:</strong> <?php echo $s['region_enabled'] ? esc_html( sprintf( '%s, %d rules%s', $s['region_key'], count( self::region_rules() ), $s['region_use_claude'] && self::claude_enabled() ? ', then Claude' : '' ) ) : 'off'; ?></p>
			<p><strong>New properties:</strong> <?php echo esc_html( $when ? 'processed ' . implode( ' and ', $when ) : 'not processed automatically' ); ?>.</p>
			<table class="widefat phsl-counts"><tbody>
				<tr><td>Properties in scope</td><td class="num"><?php echo esc_html( number_format_i18n( $c['total'] ) ); ?></td></tr>
				<tr><td>Without a <?php echo esc_html( self::uses_taxonomy() ? 'suburb / neighbourhood / quarter Location' : 'suburb' ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $c['missing_suburb'] ) ); ?></td></tr>
				<?php if ( isset( $c['missing_region'] ) ) : ?>
					<tr><td>Without a region</td><td class="num"><?php echo esc_html( number_format_i18n( $c['missing_region'] ) ); ?></td></tr>
				<?php endif; ?>
				<tr><td>Waiting to be processed</td><td class="num" id="phsl-to-process"><?php echo esc_html( number_format_i18n( $c['to_process'] ) ); ?></td></tr>
				<?php foreach ( array( 'suggested' => 'Suggestions awaiting review', 'assigned' => 'Assigned', 'not_found' => 'Nothing found', 'retry' => 'Waiting to retry', 'error' => 'Errors' ) as $k => $label ) : ?>
					<tr><td><a href="<?php echo esc_url( self::page_url( 'results', array( 'status' => $k ) ) ); ?>"><?php echo esc_html( $label ); ?></a></td><td class="num"><?php echo esc_html( number_format_i18n( $c[ 'status_' . $k ] ?? 0 ) ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
		</div>

		<div class="phsl-card">
			<h2>Process existing properties</h2>
			<p class="description">Works through properties without a <?php echo esc_html( self::uses_taxonomy() ? 'known suburb / neighbourhood / quarter Location' : 'suburb' ); ?><?php echo $s['region_enabled'] ? ' or region' : ''; ?>, newest first, within OpenStreetMap’s limit of one request per second. Stop and resume at any time.</p>
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
				$this->post_button( 'phsl_requeue', 'Re-queue everything', array( 'which' => 'all' ), 'button button-link-delete', 'Forget all previous attempts and suggestions? Properties will be looked up again (Claude calls cost a little).' );
				?>
			</p>
			<p class="description">Properties where Claude answered with low confidence are not retried by the first button, as asking again gives the same answer. When an API key is added, properties that missed out on Claude for lack of a key are re-queued automatically.</p>
		</div>

		<?php if ( self::uses_taxonomy() ) : ?>
			<div class="phsl-card">
				<h2>Known Location types</h2>
				<p class="description">Locations Smart Location has identified as suburbs, neighbourhoods or quarters (stored in the <code><?php echo esc_html( self::TYPES_OPTION ); ?></code> option). A property that already has any of these, for example matched from its address by the import, needs no OpenStreetMap or Claude lookup. The lists grow as properties are looked up.</p>
				<table class="widefat striped phsl-types"><tbody>
					<?php
					$this->load_terms();
					foreach ( self::location_types() as $type => $ids ) :
						$names = array();
						foreach ( $ids as $id ) {
							if ( isset( $this->terms[ $id ] ) ) {
								$names[] = $this->terms[ $id ]->name;
							}
						}
						natcasesort( $names );
						?>
						<tr><th><?php echo esc_html( ucfirst( $type ) ); ?> (<?php echo count( $names ); ?>)</th><td><?php echo $names ? esc_html( implode( ', ', $names ) ) : '–'; ?></td></tr>
					<?php endforeach; ?>
				</tbody></table>
			</div>
		<?php endif; ?>

		<div class="phsl-card">
			<h2>Test a property</h2>
			<p class="description">Shows what would happen for one property without saving or creating anything. If Claude is needed this makes a real (very cheap) API call.</p>
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
		$labels   = array( 'suggested' => 'Suggested', 'assigned' => 'Assigned', 'not_found' => 'Nothing found', 'retry' => 'Waiting to retry', 'error' => 'Error', 'skipped' => 'Skipped' );
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

		$osm_label    = array( '1' => 'found', '0' => 'nothing', 'x' => 'failed' );
		$claude_label = array( 'used' => 'used', 'unsure' => 'unsure', 'error' => 'failed', 'nokey' => 'no API key' );
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
			<?php $this->post_button( 'phsl_apply_all', 'Apply all suggestions', array(), 'button button-primary', 'Save all suggestions to their properties? Missing Locations will be created. Only empty fields are filled.' ); ?>
			<span class="description"><?php echo esc_html( number_format_i18n( $total ) ); ?> properties. Full details of each decision are in the Log.</span>
		</div>
		<table class="widefat striped">
			<thead><tr>
				<th>Property</th>
				<th><?php echo esc_html( self::uses_taxonomy() ? 'Location' : 'Suburb' ); ?></th>
				<?php echo $s['region_enabled'] ? '<th>Region</th>' : ''; ?>
				<th>OSM</th><th>Claude</th><th>Status</th><th></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="7">Nothing yet.</td></tr>
			<?php endif; ?>
			<?php
			foreach ( $rows as $row ) :
				$st = self::get_state( (int) $row->ID );
				if ( 'suggested' === $row->status ) {
					$v      = $st['v'] ?? '';
					$is_new = false;
					if ( is_array( $v ) ) {
						$labels_v = array();
						foreach ( $v as $it ) {
							$labels_v[] = ( ! empty( $it['t'] ) ? $this->term_path( $it['t'] ) : $it['n'] . ' (new)' ) . ' [' . $it['f'] . ']';
							$is_new     = $is_new || empty( $it['t'] );
						}
						$sub_lb = implode( ', ', $labels_v );
					} else {
						$sub_lb = (string) $v;
					}
					$region = (string) ( $st['r'] ?? '' );
				} else {
					$sub_lb = $this->current_suburb_label( (int) $row->ID );
					$is_new = false;
					$region = $s['region_enabled'] ? implode( ', ', self::meta_values( (int) $row->ID, $s['region_key'] ) ) : '';
				}
				?>
				<tr>
					<td><a href="<?php echo esc_url( get_edit_post_link( $row->ID ) ?: '#' ); ?>"><?php echo esc_html( $row->post_title ? $row->post_title : '#' . $row->ID ); ?></a>
						<?php if ( ! empty( $st['t'] ) ) : ?><br><small><?php echo esc_html( wp_date( 'j M Y H:i', $st['t'] ) ); ?></small><?php endif; ?></td>
					<td><?php echo '' !== $sub_lb ? esc_html( $sub_lb ) : '–'; ?>
						<?php if ( ! empty( $st['src'] ) ) : ?><br><small><?php echo esc_html( $st['src'] ); ?><?php echo $is_new ? ' · <strong>creates new Location</strong>' : ''; ?></small><?php endif; ?></td>
					<?php if ( $s['region_enabled'] ) : ?><td><?php echo '' !== $region ? esc_html( $region ) : '–'; ?></td><?php endif; ?>
					<td><?php echo esc_html( isset( $st['o'] ) ? ( $osm_label[ (string) $st['o'] ] ?? $st['o'] ) : '–' ); ?></td>
					<td><?php echo esc_html( isset( $st['a'] ) ? ( $claude_label[ $st['a'] ] ?? $st['a'] ) : '–' ); ?></td>
					<td><span class="phsl-badge phsl-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $labels[ $row->status ] ?? $row->status ); ?></span></td>
					<td class="phsl-actions">
						<?php
						if ( 'suggested' === $row->status ) {
							$this->post_button( 'phsl_apply', 'Apply', array( 'property_id' => $row->ID ), 'button button-small button-primary' );
						}
						$this->post_button( 'phsl_retry', 'Retry', array( 'property_id' => $row->ID ), 'button button-small' );
						?>
						<a class="button button-small" href="<?php echo esc_url( self::page_url( 'log', array( 'property' => $row->ID ) ) ); ?>">Log</a>
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
		$this->load_terms();
		$term_opts = array();
		foreach ( array_keys( $this->terms ) as $id ) {
			$term_opts[ $id ] = $this->term_path( $id );
		}
		asort( $term_opts );
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
						<label class="phsl-block"><input type="radio" name="mode" value="suggest" <?php checked( $s['mode'], 'suggest' ); ?>> <strong>Suggest only</strong>: list values on the Results tab for review; nothing is saved or created.</label>
						<label class="phsl-block"><input type="radio" name="mode" value="assign" <?php checked( $s['mode'], 'assign' ); ?>> <strong>Assign automatically</strong>: save values to the property (creating Locations if needed).</label>
						<p class="description">Locations are only ever <strong>added</strong>, never removed. A property that already has a known suburb, neighbourhood or quarter Location (for example matched from its address during import) is left alone with no lookups. The region and a custom-field suburb are only filled when empty.</p>
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
					<th scope="row">Assign the suburb to</th>
					<td>
						<label class="phsl-block"><input type="radio" name="suburb_target" value="taxonomy" <?php checked( $s['suburb_target'], 'taxonomy' ); ?>> <strong>Location</strong> (Property Hive’s Location taxonomy)</label>
						<label class="phsl-block"><input type="radio" name="suburb_target" value="meta" <?php checked( $s['suburb_target'], 'meta' ); ?>> Custom field <input type="text" name="suburb_key" class="regular-text code phsl-short" value="<?php echo esc_attr( $s['suburb_key'] ); ?>"></label>
					</td>
				</tr>
				<tr>
					<th scope="row">New Locations</th>
					<td>
						<label class="phsl-block"><input type="checkbox" name="create_terms" value="1" <?php checked( $s['create_terms'] ); ?>> Create the Location if the suburb doesn’t exist yet</label>
						<label for="phsl-parent">Create new Locations under:</label>
						<select id="phsl-parent" name="new_term_parent">
							<option value="auto" <?php selected( $s['new_term_parent'], 'auto' ); ?>>Automatic: the town/city from OpenStreetMap if it is an existing Location, otherwise top level</option>
							<option value="top" <?php selected( $s['new_term_parent'], 'top' ); ?>>Top level</option>
							<?php foreach ( $term_opts as $id => $path ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( (string) $s['new_term_parent'], (string) $id ); ?>><?php echo esc_html( $path ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Existing Locations are matched by name, ignoring case and punctuation (“King’s Norton” = “Kings Norton”).</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Smart assignment from OpenStreetMap</th>
					<td>
						<?php foreach ( self::$osm_choices as $f ) : ?>
							<label class="phsl-block"><input type="checkbox" name="osm_fields[]" value="<?php echo esc_attr( $f ); ?>" <?php checked( in_array( $f, $s['osm_fields'], true ) ); ?>> <?php echo esc_html( $osm_labels[ $f ] ); ?></label>
						<?php endforeach; ?>
						<p class="description">Every ticked area OpenStreetMap returns is assigned as a Location (a neighbourhood or quarter is created under its suburb). If none of them is found, Claude is asked for the suburb (if enabled). With a custom field, only the first one found is used.</p>
					</td>
				</tr>
			</table>

			<h2>Claude</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Use Claude</th>
					<td><label><input type="checkbox" name="use_claude" value="1" <?php checked( $s['use_claude'] ); ?>> Ask Claude when OpenStreetMap has no suburb</label></td>
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

			<h2>Region</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Enable</th>
					<td><label><input type="checkbox" name="region_enabled" value="1" <?php checked( $s['region_enabled'] ); ?>> Also fill in a region custom field</label></td>
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
						<p class="description">One region per line: <code>Region = suburb, suburb, postcode…</code>. Postcodes can be districts (<code>B30</code>) or sectors (<code>B15 2</code>). Suburbs are matched first (including parent Locations), then postcode sector, then district.</p></td>
				</tr>
				<tr>
					<th scope="row">Claude for regions</th>
					<td><label><input type="checkbox" name="region_use_claude" value="1" <?php checked( $s['region_use_claude'] ); ?>> Ask Claude to choose from the allowed regions when no rule matches</label></td>
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
