<?php
/**
 * Organise Locations: asks Claude to propose a sensible hierarchy (max 3 levels) for the
 * site's Location terms, shows it for review, applies the selected changes and can undo them.
 *
 * Only ever re-parents Locations or creates new grouping Locations. Never renames or deletes
 * existing ones.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PHSL_Organiser {

	const PROPOSAL  = 'phsl_organise_proposal';
	const UNDO      = 'phsl_organise_undo';
	const MAX_DEPTH = 3;
	const MAX_TERMS = 2000;
	const TAXONOMY  = 'location';

	/** @var PH_Smart_Location */
	private $plugin;

	/** @var array id => array( name, parent, count ) */
	private $terms = null;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		add_action( 'wp_ajax_phsl_organise_generate', array( $this, 'ajax_generate' ) );
		add_action( 'admin_post_phsl_organise_apply', array( $this, 'handle_apply' ) );
		add_action( 'admin_post_phsl_organise_undo', array( $this, 'handle_undo' ) );
		add_action( 'admin_post_phsl_organise_discard', array( $this, 'handle_discard' ) );
	}

	/* =====================================================================
	 * Current Locations
	 * =================================================================== */

	private function load( $fresh = false ) {
		if ( null !== $this->terms && ! $fresh ) {
			return $this->terms;
		}
		if ( $fresh ) {
			clean_term_cache( array(), self::TAXONOMY );
		}
		$this->terms = array();
		$terms       = get_terms( array( 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$this->terms[ (int) $t->term_id ] = array( 'name' => $t->name, 'parent' => (int) $t->parent, 'count' => (int) $t->count );
			}
		}
		return $this->terms;
	}

	/** Depth of each node (1 = top) in a parent map; nodes in a cycle get 99. */
	private static function depths( $parents ) {
		$out = array();
		foreach ( array_keys( $parents ) as $id ) {
			$d    = 1;
			$seen = array( $id => true );
			$p    = $parents[ $id ];
			while ( $p && isset( $parents[ $p ] ) ) {
				if ( isset( $seen[ $p ] ) || $d > 20 ) {
					$d = 99;
					break;
				}
				$seen[ $p ] = true;
				$d++;
				$p = $parents[ $p ];
			}
			$out[ $id ] = $d;
		}
		return $out;
	}

	private function path( $id, $parents, $names ) {
		$out   = array();
		$guard = 0;
		while ( $id && isset( $names[ $id ] ) && $guard++ < 20 ) {
			array_unshift( $out, $names[ $id ] );
			$id = $parents[ $id ] ?? 0;
		}
		return implode( ' › ', $out );
	}

	/** Most common postcode districts (e.g. "B15") of the properties in each Location. */
	private function postcode_districts() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_id, pm.meta_value FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
				INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'property'
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = tr.object_id AND pm.meta_key = '_address_postcode'
				WHERE pm.meta_value <> ''",
				self::TAXONOMY
			)
		);
		$counts = array();
		foreach ( (array) $rows as $row ) {
			$pc = strtoupper( trim( (string) $row->meta_value ) );
			$pc = preg_replace( '/\s+/', ' ', $pc );
			if ( preg_match( '/^([A-Z]{1,2}[0-9][0-9A-Z]?)\s?[0-9][A-Z]{2}$/', $pc, $m ) || preg_match( '/^([A-Z]{1,2}[0-9][0-9A-Z]?)$/', $pc, $m ) ) {
				$counts[ (int) $row->term_id ][ $m[1] ] = ( $counts[ (int) $row->term_id ][ $m[1] ] ?? 0 ) + 1;
			}
		}
		$out = array();
		foreach ( $counts as $id => $c ) {
			arsort( $c );
			$out[ $id ] = array_slice( array_keys( $c ), 0, 3 );
		}
		return $out;
	}

	/** Top-level Locations with no children: the ones a "stragglers" run tries to place. */
	private function stragglers() {
		$terms   = $this->load();
		$parents = array();
		foreach ( $terms as $t ) {
			$parents[ $t['parent'] ] = true;
		}
		$out = array();
		foreach ( $terms as $id => $t ) {
			if ( 0 === $t['parent'] && ! isset( $parents[ $id ] ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/* =====================================================================
	 * Proposal
	 * =================================================================== */

	public function ajax_generate() {
		$this->plugin->check_ajax();
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 660 ); // phpcs:ignore
		}
		$mode      = 'full' === ( $_POST['mode'] ?? '' ) ? 'full' : 'stragglers';
		$allow_new = ! empty( $_POST['allow_new'] );
		$result    = $this->generate( $mode, $allow_new );
		if ( is_wp_error( $result ) ) {
			$this->plugin->log( 'error', 'Organise Locations: ' . $result->get_error_message() );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'moves' => count( $result['moves'] ) ) );
	}

	/**
	 * Ask Claude for a structure, validate it and store it as the current proposal.
	 *
	 * @return array|WP_Error The stored proposal.
	 */
	public function generate( $mode, $allow_new ) {
		$terms = $this->load( true );
		if ( ! $terms ) {
			return new WP_Error( 'empty', 'There are no Locations to organise.' );
		}
		if ( count( $terms ) > self::MAX_TERMS && 'full' === $mode ) {
			return new WP_Error( 'too_many', sprintf( 'There are %d Locations; a full reorganise is limited to %d. Use “stragglers only”.', count( $terms ), self::MAX_TERMS ) );
		}
		$targets = 'full' === $mode ? array_keys( $terms ) : $this->stragglers();
		if ( ! $targets ) {
			return new WP_Error( 'nothing', 'There are no unplaced top-level Locations to organise.' );
		}
		$districts = $this->postcode_districts();

		$line = function ( $id ) use ( $terms, $districts ) {
			$t = $terms[ $id ];
			return sprintf(
				'%d | %s | parent: %s | properties: %d%s',
				$id,
				$t['name'],
				$t['parent'] ? (string) $t['parent'] : '-',
				$t['count'],
				! empty( $districts[ $id ] ) ? ' | postcodes: ' . implode( ', ', $districts[ $id ] ) : ''
			);
		};

		$intro = "You are organising the Location list of a UK estate agent's property website. Locations are used to search for properties; searching a Location also finds properties in every Location beneath it.\n\n"
			. "Rules:\n"
			. "- The finished tree may be at most 3 levels deep (top level, child, grandchild).\n"
			. "- Choose whatever structure best suits this particular list, for example Region › City › Suburb, City › Town › Suburb, County › Town › Suburb, or a mix. Locations that don't fit anywhere sensibly should stay at the top level.\n"
			. "- Prefer existing Locations as parents (e.g. put Birmingham suburbs under an existing \"Birmingham\").\n"
			. ( $allow_new
				? "- You may create new grouping Locations (e.g. a county or region) only where they clearly help; each new grouping must end up with at least 2 Locations under it. Use real, commonly understood UK place or area names.\n"
				: "- Do not create any new Locations; only use existing ones as parents.\n" )
			. "- Never rename Locations. Where you are not confident, leave a Location where it is.\n"
			. "- Use the postcode districts (from the properties in each Location) to judge where places really are.\n\n"
			. "Parent values: \"\" = top level, an existing Location id as a string (e.g. \"123\"), or \"new:KEY\" for a new grouping you define in new_groups (whose own parent uses the same format).\n";

		if ( 'full' === $mode ) {
			$prompt = $intro . "\nList every Location whose parent should change, with its new parent. Leave out Locations that should stay where they are.\n\nLocations (id | name | current parent id | number of properties | postcode districts):\n"
				. implode( "\n", array_map( $line, array_keys( $terms ) ) );
		} else {
			$placed = array_values( array_diff( array_keys( $terms ), $targets ) );
			$prompt = $intro . "\nThe existing structure must stay as it is. Only place the UNPLACED Locations below: give each one a parent (an existing Location or a new grouping) or \"\" to leave it at the top level. Only list UNPLACED Locations in placements.\n\nExisting structure (id | name | parent id | properties | postcode districts):\n"
				. ( $placed ? implode( "\n", array_map( $line, $placed ) ) : '(none)' )
				. "\n\nUNPLACED Locations:\n" . implode( "\n", array_map( $line, $targets ) );
		}
		$prompt .= "\n\nAlso give a short summary (2-4 sentences) of the structure you chose and why.";

		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'summary'    => array( 'type' => 'string' ),
				'new_groups' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'key'    => array( 'type' => 'string' ),
							'name'   => array( 'type' => 'string' ),
							'parent' => array( 'type' => 'string' ),
						),
						'required'             => array( 'key', 'name', 'parent' ),
						'additionalProperties' => false,
					),
				),
				'placements' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'     => array( 'type' => 'integer' ),
							'parent' => array( 'type' => 'string' ),
						),
						'required'             => array( 'id', 'parent' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'summary', 'new_groups', 'placements' ),
			'additionalProperties' => false,
		);

		$this->plugin->log( 'info', sprintf( 'Organise Locations: asking Claude (%s, %d Locations, %d to place)', $mode, count( $terms ), count( $targets ) ) );
		$res = $this->plugin->claude_json( $prompt, $schema, 32000, 'medium', 600 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$proposal = $this->validate( $res['data'], $mode, $targets, $allow_new );
		$proposal += array(
			'time'          => time(),
			'mode'          => $mode,
			'summary'       => trim( (string) ( $res['data']['summary'] ?? '' ) ),
			'model'         => $res['model'],
			'input_tokens'  => $res['input_tokens'],
			'output_tokens' => $res['output_tokens'],
		);
		update_option( self::PROPOSAL, $proposal, false );
		$this->plugin->log(
			'info',
			sprintf( 'Organise Locations: proposal with %d moves and %d new groupings (%d rejected)', count( $proposal['moves'] ), count( $proposal['groups'] ), count( $proposal['rejected'] ) ),
			0,
			array( 'summary' => $proposal['summary'], 'rejected' => $proposal['rejected'], 'model' => $res['model'], 'input_tokens' => $res['input_tokens'], 'output_tokens' => $res['output_tokens'] )
		);
		return $proposal;
	}

	/**
	 * Turn Claude's answer into a safe proposal: resolve parents, map new groupings onto existing
	 * Locations with the same name, and reject anything that would loop or exceed 3 levels.
	 */
	private function validate( $data, $mode, $targets, $allow_new ) {
		$terms    = $this->load();
		$rejected = array();
		$names    = array();
		$parents  = array();
		$by_name  = array();
		foreach ( $terms as $id => $t ) {
			$parents[ $id ] = $t['parent'];
			$names[ $id ]   = $t['name'];
			$by_name[ PH_Smart_Location::normalise( $t['name'] ) ][] = $id;
		}

		// New groupings get virtual negative ids. A grouping named like an existing Location becomes that Location.
		$groups  = array();
		$key_map = array(); // key => node id (negative = new)
		$next    = -1;
		foreach ( (array) ( $data['new_groups'] ?? array() ) as $g ) {
			$key  = (string) ( $g['key'] ?? '' );
			$name = trim( (string) ( $g['name'] ?? '' ) );
			if ( '' === $key || '' === $name || isset( $key_map[ $key ] ) ) {
				continue;
			}
			$existing = $by_name[ PH_Smart_Location::normalise( $name ) ] ?? array();
			if ( $existing ) {
				$key_map[ $key ] = $existing[0];
				continue;
			}
			if ( ! $allow_new ) {
				$rejected[] = sprintf( 'New grouping "%s" (creating new Locations is switched off)', $name );
				continue;
			}
			$key_map[ $key ] = $next;
			$groups[ $next ] = array( 'name' => $name, 'parent_ref' => (string) ( $g['parent'] ?? '' ) );
			$names[ $next ]  = $name;
			$next--;
		}

		$resolve = function ( $ref ) use ( $key_map, $terms ) {
			$ref = trim( (string) $ref );
			if ( '' === $ref || '0' === $ref ) {
				return 0;
			}
			if ( 0 === strpos( $ref, 'new:' ) ) {
				return $key_map[ substr( $ref, 4 ) ] ?? null;
			}
			return ( ctype_digit( $ref ) && isset( $terms[ (int) $ref ] ) ) ? (int) $ref : null;
		};

		foreach ( $groups as $gid => $g ) {
			$p = $resolve( $g['parent_ref'] );
			if ( null === $p ) {
				$p = 0;
			}
			$parents[ $gid ]          = $p;
			$groups[ $gid ]['parent'] = $p;
		}

		$moves = array();
		foreach ( (array) ( $data['placements'] ?? array() ) as $pl ) {
			$id = (int) ( $pl['id'] ?? 0 );
			if ( ! isset( $terms[ $id ] ) ) {
				continue;
			}
			if ( 'stragglers' === $mode && ! in_array( $id, $targets, true ) ) {
				$rejected[] = sprintf( '"%s" is already placed – left as it is', $terms[ $id ]['name'] );
				continue;
			}
			$to = $resolve( $pl['parent'] ?? '' );
			if ( null === $to ) {
				$rejected[] = sprintf( '"%s": unknown parent "%s"', $terms[ $id ]['name'], (string) ( $pl['parent'] ?? '' ) );
				continue;
			}
			if ( $to === $id || $to === $terms[ $id ]['parent'] ) {
				continue; // no change
			}
			$moves[ $id ] = $to;
		}

		// Check the whole proposed tree; drop moves whose subtree would loop or go too deep.
		for ( $pass = 0; $pass < 50; $pass++ ) {
			$virtual = $parents;
			foreach ( $moves as $id => $to ) {
				$virtual[ $id ] = $to;
			}
			$depths   = self::depths( $virtual );
			$children = array();
			foreach ( $virtual as $node => $p ) {
				$children[ $p ][] = $node;
			}
			// Reject the most specific offender first: a move that itself lands too deep (deepest
			// first), and only if there is none, a move that pushes existing children too deep.
			$bad       = null;
			$bad_score = 0;
			foreach ( $moves as $id => $to ) {
				if ( $depths[ $id ] > self::MAX_DEPTH ) {
					$score = 1000 + $depths[ $id ];
				} elseif ( $this->deepest( $id, $children, $depths ) > self::MAX_DEPTH ) {
					$score = $depths[ $id ];
				} else {
					continue;
				}
				if ( $score > $bad_score ) {
					$bad       = $id;
					$bad_score = $score;
				}
			}
			if ( null === $bad ) {
				break;
			}
			$to         = $moves[ $bad ];
			$loop       = $depths[ $bad ] >= 99;
			$rejected[] = sprintf( '"%s" under "%s" (%s)', $terms[ $bad ]['name'], $to ? ( $loop ? $names[ $to ] : $this->path( $to, $virtual, $names ) ) : 'top level', $loop ? 'would create a loop' : 'more than 3 levels deep' );
			unset( $moves[ $bad ] );
		}

		// Keep only new groupings that something actually ends up under.
		$virtual = $parents;
		foreach ( $moves as $id => $to ) {
			$virtual[ $id ] = $to;
		}
		$used = array();
		foreach ( $virtual as $node => $p ) {
			if ( $node > 0 ) {
				for ( $q = $p, $guard = 0; $q < 0 && $guard < 10; $q = $groups[ $q ]['parent'] ?? 0, $guard++ ) {
					$used[ $q ] = true;
				}
			}
		}
		foreach ( array_keys( $groups ) as $gid ) {
			if ( ! isset( $used[ $gid ] ) ) {
				unset( $groups[ $gid ] );
			}
		}

		$out_moves = array();
		foreach ( $moves as $id => $to ) {
			$out_moves[] = array( 'id' => $id, 'from' => $terms[ $id ]['parent'], 'to' => $to );
		}
		return array( 'groups' => $groups, 'moves' => $out_moves, 'rejected' => $rejected );
	}

	/** Deepest level reached by a node or anything beneath it. */
	private function deepest( $id, $children, $depths, $guard = 0 ) {
		$max = $depths[ $id ] ?? 1;
		if ( $guard > 10 ) {
			return 99;
		}
		foreach ( $children[ $id ] ?? array() as $child ) {
			$max = max( $max, $this->deepest( $child, $children, $depths, $guard + 1 ) );
		}
		return $max;
	}

	/* =====================================================================
	 * Apply / undo
	 * =================================================================== */

	public function handle_apply() {
		$this->plugin->check_admin_request( 'phsl_organise_apply' );
		$proposal = get_option( self::PROPOSAL );
		$selected = array_map( 'intval', (array) ( $_POST['move'] ?? array() ) );
		if ( ! is_array( $proposal ) || ! $selected ) {
			$this->plugin->redirect( 'organise', 'organise_nothing' );
		}
		$result = $this->apply( $proposal, $selected );
		delete_option( self::PROPOSAL );
		$this->plugin->redirect( 'organise', is_wp_error( $result ) ? 'apply_failed' : 'organised' );
	}

	/**
	 * Apply the selected moves (term ids) of a proposal, creating only the new groupings they need.
	 *
	 * @return true|WP_Error
	 */
	public function apply( $proposal, $selected ) {
		$terms   = $this->load( true );
		$groups  = (array) $proposal['groups'];
		$undo    = array( 'time' => time(), 'user' => wp_get_current_user()->user_login, 'parents' => array(), 'created' => array() );
		$created = array(); // virtual id => real term id
		$moved   = 0;

		$real_parent = function ( $ref ) use ( &$created, &$undo, $groups, &$real_parent ) {
			if ( $ref >= 0 ) {
				return (int) $ref;
			}
			if ( isset( $created[ $ref ] ) ) {
				return $created[ $ref ];
			}
			if ( ! isset( $groups[ $ref ] ) ) {
				return 0;
			}
			$parent = $real_parent( (int) $groups[ $ref ]['parent'] );
			$r      = wp_insert_term( $groups[ $ref ]['name'], self::TAXONOMY, array( 'parent' => $parent ) );
			if ( is_wp_error( $r ) ) {
				$id = 'term_exists' === $r->get_error_code() ? (int) $r->get_error_data() : 0;
			} else {
				$id                 = (int) $r['term_id'];
				$undo['created'][] = $id;
				$this->plugin->log( 'info', sprintf( 'Organise Locations: created grouping "%s"', $groups[ $ref ]['name'] ) );
			}
			$created[ $ref ] = $id;
			return $id;
		};

		foreach ( (array) $proposal['moves'] as $m ) {
			$id = (int) $m['id'];
			if ( ! in_array( $id, $selected, true ) || ! isset( $terms[ $id ] ) ) {
				continue;
			}
			if ( $terms[ $id ]['parent'] !== (int) $m['from'] ) {
				$this->plugin->log( 'warning', sprintf( 'Organise Locations: skipped "%s" – it was moved since the proposal was made', $terms[ $id ]['name'] ) );
				continue;
			}
			$to = $real_parent( (int) $m['to'] );
			if ( (int) $m['to'] < 0 && ! $to ) {
				continue; // the new grouping could not be created
			}
			$r = wp_update_term( $id, self::TAXONOMY, array( 'parent' => $to ) );
			if ( is_wp_error( $r ) ) {
				$this->plugin->log( 'error', sprintf( 'Organise Locations: could not move "%s": %s', $terms[ $id ]['name'], $r->get_error_message() ) );
				continue;
			}
			$undo['parents'][ $id ] = (int) $m['from'];
			$moved++;
		}

		clean_term_cache( array(), self::TAXONOMY );
		update_option( self::UNDO, $undo, false );
		$names = wp_list_pluck( $this->load( true ), 'name' );
		$lines = array();
		foreach ( $undo['parents'] as $id => $from ) {
			$lines[] = $this->path( $id, wp_list_pluck( $this->terms, 'parent' ), $names );
		}
		$this->plugin->log( 'info', sprintf( 'Organise Locations: applied by %s – %d moved, %d groupings created', $undo['user'], $moved, count( $undo['created'] ) ), 0, array( 'now' => $lines ) );
		return true;
	}

	public function handle_undo() {
		$this->plugin->check_admin_request( 'phsl_organise_undo' );
		$this->undo();
		$this->plugin->redirect( 'organise', 'organise_undone' );
	}

	/** Reverse the last apply: restore parents, remove created groupings that are still empty. */
	public function undo() {
		$undo = get_option( self::UNDO );
		if ( is_array( $undo ) ) {
			$terms = $this->load( true );
			foreach ( (array) $undo['parents'] as $id => $parent ) {
				if ( isset( $terms[ (int) $id ] ) ) {
					wp_update_term( (int) $id, self::TAXONOMY, array( 'parent' => isset( $terms[ (int) $parent ] ) ? (int) $parent : 0 ) );
				}
			}
			clean_term_cache( array(), self::TAXONOMY );
			$terms   = $this->load( true );
			$removed = 0;
			foreach ( array_reverse( (array) $undo['created'] ) as $id ) {
				$has_children = false;
				foreach ( $terms as $t ) {
					if ( $t['parent'] === (int) $id ) {
						$has_children = true;
						break;
					}
				}
				if ( isset( $terms[ (int) $id ] ) && ! $has_children && 0 === $terms[ (int) $id ]['count'] ) {
					wp_delete_term( (int) $id, self::TAXONOMY );
					$removed++;
					$terms = $this->load( true );
				}
			}
			delete_option( self::UNDO );
			$this->plugin->log( 'info', sprintf( 'Organise Locations: undone by %s – %d Locations restored, %d groupings removed', wp_get_current_user()->user_login, count( (array) $undo['parents'] ), $removed ) );
		}
	}

	public function handle_discard() {
		$this->plugin->check_admin_request( 'phsl_organise_discard' );
		delete_option( self::PROPOSAL );
		$this->plugin->redirect( 'organise', 'organise_discarded' );
	}

	/* =====================================================================
	 * Screen
	 * =================================================================== */

	public function render() {
		$terms    = $this->load( true );
		$parents  = wp_list_pluck( $terms, 'parent' );
		$names    = wp_list_pluck( $terms, 'name' );
		$depths   = self::depths( $parents );
		$by_depth = array_count_values( $depths );
		$proposal = get_option( self::PROPOSAL );
		$undo     = get_option( self::UNDO );
		$enabled  = PH_Smart_Location::claude_enabled();
		?>
		<div class="phsl-card">
			<h2>Organise Locations</h2>
			<p class="description">Claude looks at all your Locations – their names, where they sit now, how many properties use them and those properties’ postcode districts – and proposes a sensible hierarchy of up to 3 levels (for example Region › City › Suburb, County › Town › Suburb, or some left at the top level). Nothing changes until you review the proposal and apply it, and the last apply can be undone. Locations are only ever moved, or new grouping Locations created; nothing is renamed or deleted.</p>
			<table class="widefat phsl-counts"><tbody>
				<tr><td>Locations</td><td class="num"><?php echo count( $terms ); ?></td></tr>
				<tr><td>Top level / level 2 / level 3</td><td class="num"><?php echo (int) ( $by_depth[1] ?? 0 ) . ' / ' . (int) ( $by_depth[2] ?? 0 ) . ' / ' . (int) ( $by_depth[3] ?? 0 ); ?></td></tr>
				<tr><td>Unplaced (top level, nothing under them)</td><td class="num"><?php echo count( $this->stragglers() ); ?></td></tr>
			</tbody></table>
			<?php if ( count( array_filter( $depths, function ( $d ) { return $d > self::MAX_DEPTH; } ) ) ) : ?>
				<p class="phsl-warn">Some Locations are deeper than 3 levels, which Property Hive doesn’t fully support.</p>
			<?php endif; ?>

			<?php if ( ! $enabled ) : ?>
				<p class="phsl-warn">Needs Claude: add an API key in Settings → Claude.</p>
			<?php else : ?>
				<div class="phsl-controls">
					<label class="phsl-block"><input type="radio" name="phsl_org_mode" value="stragglers" checked> <strong>Place unplaced Locations</strong> – keep the existing structure and fit top-level Locations with nothing under them into it (run now and again)</label>
					<label class="phsl-block"><input type="radio" name="phsl_org_mode" value="full"> <strong>Full reorganise</strong> – propose a structure for all Locations (e.g. after the initial import)</label>
					<label class="phsl-block"><input type="checkbox" id="phsl-org-allow-new" checked> Allow new grouping Locations (e.g. a county or region) where they help</label>
					<button type="button" class="button button-primary" id="phsl-org-generate">Generate proposal</button>
					<span id="phsl-org-status" class="description"></span>
				</div>
				<p class="description">This asks <?php echo esc_html( PH_Smart_Location::$models[ PH_Smart_Location::settings()['claude_model'] ] ); ?>; with a few hundred Locations it typically takes a minute or two and costs a few pence.</p>
			<?php endif; ?>
		</div>

		<?php if ( is_array( $proposal ) ) : ?>
			<?php $this->render_proposal( $proposal, $terms, $parents, $names ); ?>
		<?php endif; ?>

		<?php if ( is_array( $undo ) ) : ?>
			<div class="phsl-card">
				<h2>Last apply</h2>
				<p><?php echo esc_html( sprintf( '%s by %s: %d Locations moved, %d groupings created.', wp_date( 'j M Y H:i', $undo['time'] ), $undo['user'], count( (array) $undo['parents'] ), count( (array) $undo['created'] ) ) ); ?></p>
				<?php $this->plugin->post_button( 'phsl_organise_undo', 'Undo last apply', array(), 'button', 'Put the moved Locations back where they were, and remove any groupings that were created (if still empty)?' ); ?>
			</div>
		<?php endif; ?>

		<div class="phsl-card">
			<h2>Current structure</h2>
			<?php $this->render_tree( $parents, $names, array(), array() ); ?>
		</div>
		<?php
	}

	private function render_proposal( $proposal, $terms, $parents, $names ) {
		$groups = (array) $proposal['groups'];
		$all    = $names;
		$after  = $parents;
		foreach ( $groups as $gid => $g ) {
			$all[ $gid ]   = $g['name'];
			$after[ $gid ] = (int) $g['parent'];
		}
		foreach ( $proposal['moves'] as $m ) {
			$after[ (int) $m['id'] ] = (int) $m['to'];
		}
		$moved = wp_list_pluck( $proposal['moves'], 'id' );
		?>
		<div class="phsl-card">
			<h2>Proposal (<?php echo 'full' === $proposal['mode'] ? 'full reorganise' : 'place unplaced Locations'; ?>)</h2>
			<?php if ( $proposal['summary'] ) : ?>
				<p><em><?php echo esc_html( $proposal['summary'] ); ?></em></p>
			<?php endif; ?>
			<p class="description"><?php echo esc_html( sprintf( 'Made %s by %s (%s in / %s out tokens).', wp_date( 'j M Y H:i', $proposal['time'] ), $proposal['model'], number_format_i18n( $proposal['input_tokens'] ), number_format_i18n( $proposal['output_tokens'] ) ) ); ?></p>

			<?php if ( ! $proposal['moves'] ) : ?>
				<p>Claude suggests no changes.</p>
			<?php else : ?>
				<?php if ( $groups ) : ?>
					<h3>New groupings (<?php echo count( $groups ); ?>)</h3>
					<ul class="phsl-list">
						<?php foreach ( $groups as $gid => $g ) : ?>
							<li><strong><?php echo esc_html( $g['name'] ); ?></strong> – <?php echo $g['parent'] ? 'under ' . esc_html( $this->path( (int) $g['parent'], $after, $all ) ) : 'top level'; ?></li>
						<?php endforeach; ?>
					</ul>
					<p class="description">Only created if at least one selected move uses them.</p>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="phsl_organise_apply">
					<?php wp_nonce_field( 'phsl_organise_apply' ); ?>
					<h3>Moves (<?php echo count( $proposal['moves'] ); ?>)</h3>
					<table class="widefat striped phsl-org-moves">
						<thead><tr>
							<td class="check-column"><input type="checkbox" id="phsl-org-all" checked></td>
							<th>Location</th><th class="num">Properties</th><th>From</th><th>To</th>
						</tr></thead>
						<tbody>
						<?php foreach ( $proposal['moves'] as $m ) : ?>
							<tr>
								<th class="check-column"><input type="checkbox" name="move[]" value="<?php echo esc_attr( $m['id'] ); ?>" checked></th>
								<td><strong><?php echo esc_html( $names[ $m['id'] ] ?? '#' . $m['id'] ); ?></strong></td>
								<td class="num"><?php echo (int) ( $terms[ $m['id'] ]['count'] ?? 0 ); ?></td>
								<td><?php echo $m['from'] ? esc_html( $this->path( (int) $m['from'], $parents, $names ) ) : '<em>top level</em>'; ?></td>
								<td><?php echo $m['to'] ? esc_html( $this->path( (int) $m['to'], $after, $all ) ) . ( (int) $m['to'] < 0 ? ' <span class="phsl-badge phsl-suggested">new</span>' : '' ) : '<em>top level</em>'; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p>
						<button type="submit" class="button button-primary" onclick="return confirm('Apply the selected changes to your Locations? You can undo this afterwards.');">Apply selected</button>
					</p>
				</form>

				<h3>Proposed structure</h3>
				<?php $this->render_tree( $after, $all, $moved, array_keys( $groups ) ); ?>
			<?php endif; ?>

			<?php if ( $proposal['rejected'] ) : ?>
				<details><summary><?php echo esc_html( sprintf( 'Left out (%d) – suggestions that were unsafe or invalid', count( $proposal['rejected'] ) ) ); ?></summary>
					<ul class="phsl-list"><?php foreach ( $proposal['rejected'] as $r ) : ?><li><?php echo esc_html( $r ); ?></li><?php endforeach; ?></ul>
				</details>
			<?php endif; ?>
			<p><?php $this->plugin->post_button( 'phsl_organise_discard', 'Discard proposal', array(), 'button button-link-delete' ); ?></p>
		</div>
		<?php
	}

	/** Nested list of a parent map; $moved / $new are highlighted. */
	private function render_tree( $parents, $names, $moved, $new ) {
		$children = array();
		foreach ( $parents as $id => $p ) {
			$children[ (int) $p ][] = $id;
		}
		foreach ( $children as &$list ) {
			usort( $list, function ( $a, $b ) use ( $names ) {
				return strnatcasecmp( $names[ $a ], $names[ $b ] );
			} );
		}
		unset( $list );
		echo '<div class="phsl-tree">';
		$this->render_branch( 0, $children, $names, $moved, $new, 0 );
		echo '</div>';
	}

	private function render_branch( $parent, $children, $names, $moved, $new, $level ) {
		if ( empty( $children[ $parent ] ) || $level > 6 ) {
			return;
		}
		echo '<ul>';
		foreach ( $children[ $parent ] as $id ) {
			$class = in_array( $id, $new, true ) ? 'phsl-tree-new' : ( in_array( $id, $moved, true ) ? 'phsl-tree-moved' : '' );
			echo '<li' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . esc_html( $names[ $id ] );
			if ( 'phsl-tree-new' === $class ) {
				echo ' <span class="phsl-badge phsl-suggested">new</span>';
			}
			$this->render_branch( $id, $children, $names, $moved, $new, $level + 1 );
			echo '</li>';
		}
		echo '</ul>';
	}
}
