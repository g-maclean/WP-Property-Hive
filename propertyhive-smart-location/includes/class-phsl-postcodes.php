<?php
/**
 * Postcode → suburb list for Smart Location.
 *
 * Generated from the manually entered suburb field: for each full postcode, which suburbs the
 * properties there use and how many. One postcode can have several suburbs. Your own edits are
 * kept separately (they override the generated entry and survive a rebuild).
 *
 * Options:
 *   phsl_postcode_map        array( 'B30 2AB' => array( 'Kings Norton' => 6, 'Cotteridge' => 2 ), ... )
 *   phsl_postcode_overrides  array( 'B30 2AB' => array( 'Kings Norton' ), ... )   (empty array = ignore postcode)
 *   phsl_postcode_map_info   array( time, properties, postcodes )
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PHSL_Postcodes {

	const MAP       = 'phsl_postcode_map';
	const OVERRIDES = 'phsl_postcode_overrides';
	const INFO      = 'phsl_postcode_map_info';

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

	/** "b302ab" → "B30 2AB"; '' if not a UK postcode. */
	public static function normalise( $postcode ) {
		$pc = strtoupper( preg_replace( '/\s+/', '', (string) $postcode ) );
		if ( preg_match( '/^([A-Z]{1,2}[0-9][0-9A-Z]?)([0-9][A-Z]{2})$/', $pc, $m ) ) {
			return $m[1] . ' ' . $m[2];
		}
		return '';
	}

	/** "B30 2AB" → "B30 2". */
	public static function sector( $postcode ) {
		$pc = self::normalise( $postcode );
		return '' === $pc ? '' : substr( $pc, 0, -2 );
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
	 * Build the list from the manual suburb field of every property with a postcode.
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
			$pc = self::normalise( $row->postcode );
			if ( '' === $pc ) {
				continue;
			}
			foreach ( PH_Smart_Location::split_values( $row->suburb ) as $suburb ) {
				$n = PH_Smart_Location::normalise( $suburb );
				if ( '' === $n ) {
					continue;
				}
				$map[ $pc ][ $n ]          = ( $map[ $pc ][ $n ] ?? 0 ) + 1;
				$spelling[ $n ][ $suburb ] = ( $spelling[ $n ][ $suburb ] ?? 0 ) + 1;
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
		ksort( $map, SORT_NATURAL );
		foreach ( $map as $pc => $counts ) {
			arsort( $counts );
			foreach ( $counts as $n => $c ) {
				$out[ $pc ][ $display[ $n ] ] = $c;
			}
		}
		$info = array( 'time' => time(), 'properties' => count( $props ), 'postcodes' => count( $out ), 'field' => $key );
		update_option( self::MAP, $out, false );
		update_option( self::INFO, $info, false );
		$this->map = $out;
		$this->plugin->log( 'info', sprintf( 'Postcode list generated from %s: %d postcodes from %d properties', $key, $info['postcodes'], $info['properties'] ) );
		return $info;
	}

	/**
	 * Suburbs for a postcode: the full postcode first, then (if enabled) its sector.
	 * $exclude removes one count of each of those suburbs at the property's own postcode, so an
	 * existing property isn't matched against its own manual suburb.
	 *
	 * @return array( level => 'postcode'|'sector'|'', key => 'B30 2AB'|'B30 2', candidates => array( suburb => count ) )
	 */
	public function lookup( $postcode, $exclude = array(), $use_sector = true ) {
		$pc   = self::normalise( $postcode );
		$none = array( 'level' => '', 'key' => '', 'candidates' => array() );
		if ( '' === $pc ) {
			return $none;
		}
		$overrides = $this->overrides();
		if ( isset( $overrides[ $pc ] ) ) {
			$c = array();
			foreach ( (array) $overrides[ $pc ] as $s ) {
				$c[ $s ] = 1;
			}
			return array( 'level' => $c ? 'postcode' : '', 'key' => $pc, 'candidates' => $c, 'override' => true );
		}

		$map     = $this->map();
		$exclude = array_map( array( 'PH_Smart_Location', 'normalise' ), (array) $exclude );
		$minus   = function ( $counts, $own ) use ( $exclude ) {
			if ( $own ) {
				foreach ( $counts as $s => $n ) {
					if ( in_array( PH_Smart_Location::normalise( $s ), $exclude, true ) ) {
						$counts[ $s ] = $n - 1;
					}
				}
			}
			$counts = array_filter( $counts, function ( $n ) {
				return $n > 0;
			} );
			arsort( $counts );
			return $counts;
		};

		if ( ! empty( $map[ $pc ] ) ) {
			$c = $minus( $map[ $pc ], true );
			if ( $c ) {
				return array( 'level' => 'postcode', 'key' => $pc, 'candidates' => $c );
			}
		}
		if ( $use_sector ) {
			$sector = self::sector( $pc );
			$c      = array();
			foreach ( $map as $p => $counts ) {
				if ( 0 === strpos( $p, $sector ) && strlen( $p ) === strlen( $sector ) + 2 ) {
					foreach ( $minus( $counts, $p === $pc ) as $s => $n ) {
						$c[ $s ] = ( $c[ $s ] ?? 0 ) + $n;
					}
				}
			}
			if ( $c ) {
				arsort( $c );
				return array( 'level' => 'sector', 'key' => $sector, 'candidates' => $c );
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
		$pc = self::normalise( wp_unslash( $_POST['postcode'] ?? '' ) );
		if ( '' === $pc ) {
			$this->plugin->redirect( 'postcodes', 'postcodes_invalid' );
		}
		$raw     = trim( sanitize_text_field( wp_unslash( $_POST['suburbs'] ?? '' ) ) );
		$suburbs = '-' === $raw ? array() : array_values( array_unique( PH_Smart_Location::split_values( $raw ) ) );
		$o       = $this->overrides();
		$o[ $pc ] = $suburbs;
		ksort( $o, SORT_NATURAL );
		update_option( self::OVERRIDES, $o, false );
		$this->plugin->log( 'info', sprintf( 'Postcode %s set by %s to: %s', $pc, wp_get_current_user()->user_login, $suburbs ? implode( ', ', $suburbs ) : '(ignore this postcode)' ) );
		$this->plugin->redirect( 'postcodes', 'postcodes_saved' );
	}

	public function handle_remove() {
		$this->plugin->check_admin_request( 'phsl_postcodes_remove' );
		$pc = self::normalise( wp_unslash( $_POST['postcode'] ?? '' ) );
		$o  = $this->overrides();
		unset( $o[ $pc ] );
		update_option( self::OVERRIDES, $o, false );
		$this->plugin->log( 'info', sprintf( 'Postcode %s: your edit removed by %s', $pc, wp_get_current_user()->user_login ) );
		$this->plugin->redirect( 'postcodes', 'postcodes_saved' );
	}

	/* =====================================================================
	 * Screen
	 * =================================================================== */

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
		foreach ( array_unique( array_merge( array_keys( $map ), array_keys( $overrides ) ) ) as $pc ) {
			$all[ $pc ] = array( 'generated' => $map[ $pc ] ?? array(), 'override' => $overrides[ $pc ] ?? null );
		}
		uksort( $all, 'strnatcmp' );
		if ( '' !== $search ) {
			$q   = strtoupper( $search );
			$qn  = PH_Smart_Location::normalise( $search );
			$all = array_filter( $all, function ( $e, $pc ) use ( $q, $qn ) {
				if ( 0 === strpos( str_replace( ' ', '', $pc ), str_replace( ' ', '', $q ) ) ) {
					return true;
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
			<p class="description">The first thing Smart Location checks. Built from the manual suburb field (<code><?php echo esc_html( $s['manual_key'] ); ?></code>): for each postcode, the suburbs used by properties there and how many. If a postcode has one suburb it is used straight away; if it has several, OpenStreetMap and then Claude pick between them (otherwise the most common is used). Postcodes not in the list fall back to the postcode sector<?php echo $s['postcode_sector'] ? '' : ' (switched off in Settings)'; ?>, then to OpenStreetMap and Claude as before. When an existing property is checked, its own manual suburb is left out of the counts so it isn’t matched against itself.</p>
			<table class="widefat phsl-counts"><tbody>
				<tr><td>Postcodes in the list</td><td class="num"><?php echo esc_html( number_format_i18n( count( $map ) ) ); ?></td></tr>
				<tr><td>…with more than one suburb</td><td class="num"><?php echo esc_html( number_format_i18n( $multi ) ); ?></td></tr>
				<tr><td>Your edits</td><td class="num"><?php echo esc_html( number_format_i18n( count( $overrides ) ) ); ?></td></tr>
				<tr><td>Last generated</td><td class="num"><?php echo is_array( $info ) ? esc_html( sprintf( '%s from %s properties', wp_date( 'j M Y H:i', $info['time'] ), number_format_i18n( $info['properties'] ) ) ) : 'never'; ?></td></tr>
			</tbody></table>
			<p>
				<?php $this->plugin->post_button( 'phsl_postcodes_generate', is_array( $info ) ? 'Rebuild from manual suburbs' : 'Generate from manual suburbs', array(), 'button button-primary', is_array( $info ) ? 'Rebuild the list from the current manual suburbs? Your own edits are kept.' : '' ); ?>
			</p>
			<?php if ( ! $s['postcode_enabled'] ) : ?>
				<p class="phsl-warn">Postcode lookup is switched off in Settings.</p>
			<?php endif; ?>
		</div>

		<div class="phsl-card">
			<h2>Edit a postcode</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="phsl-controls">
				<input type="hidden" name="action" value="phsl_postcodes_save">
				<?php wp_nonce_field( 'phsl_postcodes_save' ); ?>
				<label>Postcode <input type="text" name="postcode" class="regular-text phsl-short" placeholder="B30 2AB" required></label>
				<label>Suburbs <input type="text" name="suburbs" class="regular-text" placeholder="Kings Norton, Cotteridge"></label>
				<button class="button">Save</button>
			</form>
			<p class="description">Comma-separate several suburbs. Enter <code>-</code> to ignore the postcode (it then goes straight to OpenStreetMap). Your edit replaces the generated entry and is kept when the list is rebuilt.</p>
		</div>

		<div class="phsl-card">
			<form method="get" class="phsl-toolbar">
				<input type="hidden" name="page" value="<?php echo esc_attr( PH_Smart_Location::PAGE_SLUG ); ?>">
				<input type="hidden" name="tab" value="postcodes">
				<input type="search" name="q" value="<?php echo esc_attr( $search ); ?>" placeholder="Postcode or suburb">
				<select name="show">
					<option value="">All postcodes</option>
					<option value="multi" <?php selected( $only, 'multi' ); ?>>More than one suburb</option>
					<option value="edited" <?php selected( $only, 'edited' ); ?>>Your edits</option>
				</select>
				<button class="button">Filter</button>
				<span class="description"><?php echo esc_html( number_format_i18n( $total ) ); ?> postcodes</span>
			</form>
			<table class="widefat striped">
				<thead><tr><th>Postcode</th><th>Suburbs (number of properties)</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="3"><?php echo $map ? 'No postcodes match.' : 'The list is empty – generate it from the manual suburbs above.'; ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $pc => $e ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $pc ); ?></strong></td>
						<td>
							<?php if ( null !== $e['override'] ) : ?>
								<?php echo $e['override'] ? esc_html( implode( ', ', $e['override'] ) ) : '<em>ignored</em>'; ?> <span class="phsl-badge phsl-suggested">your edit</span>
								<?php if ( $e['generated'] ) : ?><br><small>Generated: <?php echo esc_html( self::format( $e['generated'] ) ); ?></small><?php endif; ?>
							<?php else : ?>
								<?php echo esc_html( self::format( $e['generated'] ) ); ?>
							<?php endif; ?>
						</td>
						<td class="phsl-actions"><?php if ( null !== $e['override'] ) { $this->plugin->post_button( 'phsl_postcodes_remove', 'Remove edit', array( 'postcode' => $pc ), 'button button-small' ); } ?></td>
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
