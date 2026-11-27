<?php
/**
 * Postcode → suburb list for Smart Location.
 *
 * Entries can be at any precision: a postcode district ("B13"), a sector ("B13 3") or a full
 * postcode ("B13 3AB"). One entry can have several suburbs. Matching always uses the most
 * specific entry available for a property's postcode: full postcode, then sector, then district.
 *
 * The generated list is built at district level only, from the manually entered suburb field
 * (which suburbs the properties in each district use, and how many). Your own entries – at any
 * precision – are kept separately; they take priority over a generated entry at the same level and
 * survive a rebuild.
 *
 * Options:
 *   phsl_postcode_map        array( 'B13' => array( 'Moseley' => 40, 'Kings Heath' => 25 ), ... )
 *   phsl_postcode_overrides  array( 'B13 3' => array( 'Moseley' ), 'B30 2AB' => array(), ... )  (empty = ignore)
 *   phsl_postcode_map_info   array( time, properties, postcodes, field )
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PHSL_Postcodes {

	const MAP       = 'phsl_postcode_map';
	const OVERRIDES = 'phsl_postcode_overrides';
	const INFO      = 'phsl_postcode_map_info';

	/** Levels, most specific first. */
	const LEVELS = array( 'full', 'sector', 'district' );

	/** @var PH_Smart_Location */
	private $plugin;

	private $map       = null;
	private $overrides = null;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		add_action( 'admin_post_phsl_postcodes_generate', array( $this, 'handle_generate' ) );
		add_action( 'admin_post_phsl_postcodes_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_phsl_postcodes_remove', array( $this, 'handle_remove' ) );
	}

	/* =====================================================================
	 * Postcodes
	 * =================================================================== */

	/**
	 * Parse a postcode or part of one.
	 * "b133ab" → full "B13 3AB"; "B13 3" / "b133" → sector "B13 3"; "b13" → district "B13".
	 *
	 * @return array( 'full' => '', 'sector' => '', 'district' => '', 'level' => 'full'|'sector'|'district'|'' )
	 */
	public static function parse( $input ) {
		$out      = array( 'full' => '', 'sector' => '', 'district' => '', 'level' => '' );
		$raw      = strtoupper( trim( preg_replace( '/\s+/', ' ', (string) $input ) ) );
		$compact  = str_replace( ' ', '', $raw );
		$district = '[A-Z]{1,2}[0-9][0-9A-Z]?';
		if ( '' === $compact ) {
			return $out;
		}
		// Full postcode, with or without the space.
		if ( preg_match( '/^(' . $district . ')([0-9])([A-Z]{2})$/', $compact, $m ) ) {
			return array( 'full' => $m[1] . ' ' . $m[2] . $m[3], 'sector' => $m[1] . ' ' . $m[2], 'district' => $m[1], 'level' => 'full' );
		}
		// With a space the user has said where the district ends: "B13 3" is a sector.
		if ( false !== strpos( $raw, ' ' ) ) {
			if ( preg_match( '/^(' . $district . ') ([0-9])$/', $raw, $m ) ) {
				return array( 'full' => '', 'sector' => $m[1] . ' ' . $m[2], 'district' => $m[1], 'level' => 'sector' );
			}
			return $out;
		}
		// No space: a valid district ("B13") is a district; one extra digit ("B133") is a sector.
		if ( preg_match( '/^' . $district . '$/', $compact ) ) {
			return array( 'full' => '', 'sector' => '', 'district' => $compact, 'level' => 'district' );
		}
		if ( preg_match( '/^(' . $district . ')([0-9])$/', $compact, $m ) ) {
			return array( 'full' => '', 'sector' => $m[1] . ' ' . $m[2], 'district' => $m[1], 'level' => 'sector' );
		}
		return $out;
	}

	/** Normalised key for an entry ("B13", "B13 3", "B13 3AB"), or '' if not valid. */
	public static function key( $input ) {
		$p = self::parse( $input );
		return '' === $p['level'] ? '' : $p[ $p['level'] ];
	}

	private function map() {
		if ( null === $this->map ) {
			$this->map = (array) get_option( self::MAP, array() );
		}
		return $this->map;
	}

	private function overrides() {
		if ( null === $this->overrides ) {
			$this->overrides = (array) get_option( self::OVERRIDES, array() );
		}
		return $this->overrides;
	}

	/**
	 * Build the list at district level (e.g. "B13") from the manual suburb field.
	 *
	 * @return array info
	 */
	public function generate() {
		global $wpdb;
		$key  = PH_Smart_Location::settings()['manual_key'];
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pc.post_id, pc.meta_value AS postcode, sb.meta_value AS suburb FROM {$wpdb->postmeta} pc
				INNER JOIN {$wpdb->postmeta} sb ON sb.post_id = pc.post_id AND sb.meta_key = %s AND sb.meta_value <> ''
				INNER JOIN {$wpdb->posts} p ON p.ID = pc.post_id AND p.post_type = 'property' AND p.post_status NOT IN ('trash', 'auto-draft')
				WHERE pc.meta_key = '_address_postcode' AND pc.meta_value <> ''",
				$key
			)
		);
		$map      = array();
		$spelling = array(); // normalised suburb => array( spelling => count )
		$props    = array();
		foreach ( (array) $rows as $row ) {
			$district = self::parse( $row->postcode )['district'];
			if ( '' === $district ) {
				continue;
			}
			foreach ( PH_Smart_Location::split_values( $row->suburb ) as $suburb ) {
				$n = PH_Smart_Location::normalise( $suburb );
				if ( '' === $n ) {
					continue;
				}
				$map[ $district ][ $n ]       = ( $map[ $district ][ $n ] ?? 0 ) + 1;
				$spelling[ $n ][ $suburb ]    = ( $spelling[ $n ][ $suburb ] ?? 0 ) + 1;
				$props[ (int) $row->post_id ] = true;
			}
		}
		// Use the most common spelling of each suburb.
		$display = array();
		foreach ( $spelling as $n => $forms ) {
			arsort( $forms );
			$display[ $n ] = (string) key( $forms );
		}
		$out = array();
		uksort( $map, 'strnatcmp' );
		foreach ( $map as $district => $counts ) {
			arsort( $counts );
			foreach ( $counts as $n => $c ) {
				$out[ $district ][ $display[ $n ] ] = $c;
			}
		}
		$info = array( 'time' => time(), 'properties' => count( $props ), 'postcodes' => count( $out ), 'field' => $key );
		update_option( self::MAP, $out, false );
		update_option( self::INFO, $info, false );
		$this->map = $out;
		$this->plugin->log( 'info', sprintf( 'Postcode list generated from %s: %d postcode districts from %d properties', $key, $info['postcodes'], $info['properties'] ) );
		return $info;
	}

	/**
	 * Suburbs for a property's postcode, from the most specific entry available:
	 * full postcode, then sector, then district (your own entry first at each level).
	 * $exclude takes one count of each of those suburbs off generated entries, so an existing
	 * property isn't matched against its own manual suburb.
	 *
	 * @return array( level => 'full'|'sector'|'district'|'', key => 'B13 3AB'|'B13 3'|'B13', candidates => array( suburb => count ), override => bool )
	 */
	public function lookup( $postcode, $exclude = array() ) {
		$none = array( 'level' => '', 'key' => '', 'candidates' => array(), 'override' => false );
		$p    = self::parse( $postcode );
		if ( '' === $p['level'] ) {
			return $none;
		}
		$map       = $this->map();
		$overrides = $this->overrides();
		$exclude   = array_map( array( 'PH_Smart_Location', 'normalise' ), (array) $exclude );

		foreach ( self::LEVELS as $level ) {
			$key = $p[ $level ];
			if ( '' === $key ) {
				continue;
			}
			if ( isset( $overrides[ $key ] ) ) {
				$c = array();
				foreach ( (array) $overrides[ $key ] as $s ) {
					$c[ $s ] = 1;
				}
				// An empty entry means "ignore this postcode": no postcode match at all.
				return $c ? array( 'level' => $level, 'key' => $key, 'candidates' => $c, 'override' => true ) : $none;
			}
			if ( ! empty( $map[ $key ] ) ) {
				$c = array();
				foreach ( $map[ $key ] as $s => $n ) {
					if ( in_array( PH_Smart_Location::normalise( $s ), $exclude, true ) ) {
						$n--;
					}
					if ( $n > 0 ) {
						$c[ $s ] = $n;
					}
				}
				if ( $c ) {
					arsort( $c );
					return array( 'level' => $level, 'key' => $key, 'candidates' => $c, 'override' => false );
				}
			}
		}
		return $none;
	}

	/* =====================================================================
	 * Actions
	 * =================================================================== */

	public function handle_generate() {
		$this->plugin->check_admin_request( 'phsl_postcodes_generate' );
		$this->generate();
		$this->plugin->redirect( 'postcodes', 'postcodes_generated' );
	}

	public function handle_save() {
		$this->plugin->check_admin_request( 'phsl_postcodes_save' );
		$key = self::key( wp_unslash( $_POST['postcode'] ?? '' ) );
		if ( '' === $key ) {
			$this->plugin->redirect( 'postcodes', 'postcodes_invalid' );
		}
		$raw      = trim( sanitize_text_field( wp_unslash( $_POST['suburbs'] ?? '' ) ) );
		$suburbs  = '-' === $raw ? array() : array_values( array_unique( PH_Smart_Location::split_values( $raw ) ) );
		$o        = $this->overrides();
		$o[ $key ] = $suburbs;
		uksort( $o, 'strnatcmp' );
		update_option( self::OVERRIDES, $o, false );
		$this->plugin->log( 'info', sprintf( 'Postcode %s set by %s to: %s', $key, wp_get_current_user()->user_login, $suburbs ? implode( ', ', $suburbs ) : '(ignore this postcode)' ) );
		$this->plugin->redirect( 'postcodes', 'postcodes_saved' );
	}

	public function handle_remove() {
		$this->plugin->check_admin_request( 'phsl_postcodes_remove' );
		$key = self::key( wp_unslash( $_POST['postcode'] ?? '' ) );
		$o   = $this->overrides();
		unset( $o[ $key ] );
		update_option( self::OVERRIDES, $o, false );
		$this->plugin->log( 'info', sprintf( 'Postcode %s: your entry removed by %s', $key, wp_get_current_user()->user_login ) );
		$this->plugin->redirect( 'postcodes', 'postcodes_saved' );
	}

	/* =====================================================================
	 * Screen
	 * =================================================================== */

	public static function level_label( $level ) {
		$labels = array( 'full' => 'full postcode', 'sector' => 'sector', 'district' => 'district' );
		return $labels[ $level ] ?? '';
	}

	public function render() {
		$s         = PH_Smart_Location::settings();
		$map       = $this->map();
		$overrides = $this->overrides();
		$info      = get_option( self::INFO );
		$multi     = count( array_filter( $map, function ( $c ) {
			return count( $c ) > 1;
		} ) );

		$search = trim( sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ) );
		$only   = sanitize_key( $_GET['show'] ?? '' );
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$all    = array();
		foreach ( array_unique( array_merge( array_keys( $map ), array_keys( $overrides ) ) ) as $key ) {
			$all[ $key ] = array( 'generated' => $map[ $key ] ?? array(), 'override' => $overrides[ $key ] ?? null );
		}
		uksort( $all, 'strnatcmp' );
		if ( '' !== $search ) {
			$q   = str_replace( ' ', '', strtoupper( $search ) );
			$qn  = PH_Smart_Location::normalise( $search );
			$all = array_filter( $all, function ( $e, $key ) use ( $q, $qn ) {
				$k = str_replace( ' ', '', $key );
				if ( 0 === strpos( $k, $q ) || 0 === strpos( $q, $k ) ) {
					return true; // "B13" finds B13 entries; "B13 3AB" finds B13, B13 3 and B13 3AB
				}
				foreach ( array_merge( array_keys( $e['generated'] ), (array) $e['override'] ) as $sub ) {
					if ( '' !== $qn && false !== strpos( PH_Smart_Location::normalise( $sub ), $qn ) ) {
						return true;
					}
				}
				return false;
			}, ARRAY_FILTER_USE_BOTH );
		}
		if ( 'multi' === $only ) {
			$all = array_filter( $all, function ( $e ) {
				return null === $e['override'] ? count( $e['generated'] ) > 1 : count( $e['override'] ) > 1;
			} );
		} elseif ( 'edited' === $only ) {
			$all = array_filter( $all, function ( $e ) {
				return null !== $e['override'];
			} );
		}
		$total = count( $all );
		$per   = 100;
		$rows  = array_slice( $all, ( $paged - 1 ) * $per, $per, true );
		?>
		<div class="phsl-card">
			<h2>Postcode list</h2>
			<p class="description">The first thing Smart Location checks. Generated from the manual suburb field (<code><?php echo esc_html( $s['manual_key'] ); ?></code>) at <strong>postcode district</strong> level (the first part, e.g. “B13”): the suburbs used by properties in each district and how many. You can add your own entries at any precision – a district (“B13”), a sector (“B13 3”) or a full postcode (“B13 3AB”) – and the <strong>most specific entry available</strong> is used for each property. One suburb: used straight away. Several: OpenStreetMap and then Claude pick between them (otherwise the most common is used). No entry: OpenStreetMap, then Claude. When an existing property is checked, its own manual suburb is left out of the counts so it isn’t matched against itself.</p>
			<table class="widefat phsl-counts"><tbody>
				<tr><td>Generated postcode districts</td><td class="num"><?php echo esc_html( number_format_i18n( count( $map ) ) ); ?></td></tr>
				<tr><td>…with more than one suburb</td><td class="num"><?php echo esc_html( number_format_i18n( $multi ) ); ?></td></tr>
				<tr><td>Your entries</td><td class="num"><?php echo esc_html( number_format_i18n( count( $overrides ) ) ); ?></td></tr>
				<tr><td>Last generated</td><td class="num"><?php echo is_array( $info ) ? esc_html( sprintf( '%s from %s properties', wp_date( 'j M Y H:i', $info['time'] ), number_format_i18n( $info['properties'] ) ) ) : 'never'; ?></td></tr>
			</tbody></table>
			<p>
				<?php $this->plugin->post_button( 'phsl_postcodes_generate', is_array( $info ) ? 'Rebuild from manual suburbs' : 'Generate from manual suburbs', array(), 'button button-primary', is_array( $info ) ? 'Rebuild the district list from the current manual suburbs? Your own entries are kept.' : '' ); ?>
			</p>
			<?php if ( ! $s['postcode_enabled'] ) : ?>
				<p class="phsl-warn">Postcode lookup is switched off in Settings.</p>
			<?php endif; ?>
		</div>

		<div class="phsl-card">
			<h2>Add or change an entry</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="phsl-controls">
				<input type="hidden" name="action" value="phsl_postcodes_save">
				<?php wp_nonce_field( 'phsl_postcodes_save' ); ?>
				<label>Postcode <input type="text" name="postcode" class="regular-text phsl-short" placeholder="B13, B13 3 or B13 3AB" required></label>
				<label>Suburbs <input type="text" name="suburbs" class="regular-text" placeholder="Moseley, Kings Heath"></label>
				<button class="button">Save</button>
			</form>
			<p class="description">A district (“B13”), sector (“B13 3”) or full postcode (“B13 3AB”). Comma-separate several suburbs. Enter <code>-</code> to ignore that postcode (properties there go straight to OpenStreetMap). Your entry takes priority over a generated one for the same district and is kept when the list is rebuilt.</p>
		</div>

		<div class="phsl-card">
			<form method="get" class="phsl-toolbar">
				<input type="hidden" name="page" value="<?php echo esc_attr( PH_Smart_Location::PAGE_SLUG ); ?>">
				<input type="hidden" name="tab" value="postcodes">
				<input type="search" name="q" value="<?php echo esc_attr( $search ); ?>" placeholder="Postcode or suburb">
				<select name="show">
					<option value="">All entries</option>
					<option value="multi" <?php selected( $only, 'multi' ); ?>>More than one suburb</option>
					<option value="edited" <?php selected( $only, 'edited' ); ?>>Your entries</option>
				</select>
				<button class="button">Filter</button>
				<span class="description"><?php echo esc_html( number_format_i18n( $total ) ); ?> entries</span>
			</form>
			<table class="widefat striped">
				<thead><tr><th>Postcode</th><th>Level</th><th>Suburbs (number of properties)</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="4"><?php echo $map || $overrides ? 'No entries match.' : 'The list is empty – generate it from the manual suburbs above.'; ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $key => $e ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $key ); ?></strong></td>
						<td><?php echo esc_html( self::level_label( self::parse( $key )['level'] ) ); ?></td>
						<td>
							<?php if ( null !== $e['override'] ) : ?>
								<?php echo $e['override'] ? esc_html( implode( ', ', $e['override'] ) ) : '<em>ignored</em>'; ?> <span class="phsl-badge phsl-suggested">your entry</span>
								<?php if ( $e['generated'] ) : ?><br><small>Generated: <?php echo esc_html( self::format( $e['generated'] ) ); ?></small><?php endif; ?>
							<?php else : ?>
								<?php echo esc_html( self::format( $e['generated'] ) ); ?>
							<?php endif; ?>
						</td>
						<td class="phsl-actions"><?php if ( null !== $e['override'] ) { $this->plugin->post_button( 'phsl_postcodes_remove', 'Remove your entry', array( 'postcode' => $key ), 'button button-small' ); } ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $total / $per );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></div>';
			}
			?>
		</div>
		<?php
	}

	public static function format( $counts ) {
		$out = array();
		foreach ( (array) $counts as $s => $n ) {
			$out[] = $s . ' (' . $n . ')';
		}
		return implode( ', ', $out );
	}
}
