<?php
/**
 * Shared, READ-ONLY backfill-plan computation.
 *
 * Used by BOTH preview-backfill.php and apply-backfill.php so the two can never
 * drift: the apply script applies exactly what the preview shows, and recomputing
 * the plan at apply time re-reads live data (i.e. re-verifies nothing changed).
 *
 * A lot is judged ONLY by whether its menu_order is actually broken — an image at
 * menu_order 0 (unset) or a menu_order tie. Filenames are consulted only to PROPOSE
 * a fix; when the order is already set, naming is irrelevant (the client hand-orders
 * odd-named uploads, and menu_order is the source of truth there).
 *
 * Returns:
 *   [
 *     'backfill'      => [ ['id','title','count','changes'=>[ ['id','name','from','to'] ]], ... ],
 *     'skip'          => [ ['id','title'], ... ],   // hand-ordered, no action
 *     'review'        => [ ['id','title','why'], ... ],
 *     'clean'         => int,
 *     'no_imgs'       => int,
 *     'total_changes' => int,
 *   ]
 */

if ( ! function_exists( 'caa_compute_backfill_plan' ) ) {
	function caa_compute_backfill_plan( $term_id ) {
		$item_ids = get_posts( [
			'post_type'      => 'item',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => [ [ 'taxonomy' => 'auction', 'field' => 'term_id', 'terms' => $term_id ] ],
		] );

		$plan = [ 'backfill' => [], 'skip' => [], 'review' => [], 'clean' => 0, 'no_imgs' => 0, 'total_changes' => 0 ];

		foreach ( $item_ids as $iid ) {
			$atts = get_posts( [
				'post_parent'    => $iid,
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			] );
			if ( empty( $atts ) ) { $plan['no_imgs']++; continue; }

			// Parse every image; capture filename {lot}_{seq} when present.
			$prefix_counts = [];
			$all = [];
			foreach ( $atts as $a ) {
				// Actual FILENAME, not post_title (often the LiveAuctioneers id). Tolerate WP
				// suffixes after the seq ("-scaled", dedup "-1", size "-WxH"); (?![0-9]) avoids truncation.
				$fname = basename( (string) get_post_meta( $a->ID, '_wp_attached_file', true ) );
				$mo    = (int) $a->menu_order;
				if ( preg_match( '/^([0-9]+[a-zA-Z]*)_([0-9]+)(?![0-9])/', $fname, $m ) ) {
					$all[] = [ 'id' => $a->ID, 'mo' => $mo, 'parseable' => true, 'prefix' => $m[1], 'seq' => (int) $m[2], 'name' => $fname ];
					$prefix_counts[ $m[1] ] = ( $prefix_counts[ $m[1] ] ?? 0 ) + 1;
				} else {
					$all[] = [ 'id' => $a->ID, 'mo' => $mo, 'parseable' => false, 'prefix' => null, 'seq' => null, 'name' => ( $fname !== '' ? $fname : '(no file)' ) ];
				}
			}

			// Only a zero (unset) or a tie breaks ordering. If neither, leave the lot alone.
			$mos        = array_map( function ( $x ) { return $x['mo']; }, $all );
			$has_zero   = in_array( 0, $mos, true );
			$counts     = array_count_values( $mos );
			$dup_values = ! empty( $counts ) && max( $counts ) > 1;

			$dominant = null;
			if ( ! empty( $prefix_counts ) ) { arsort( $prefix_counts ); $dominant = (string) array_key_first( $prefix_counts ); }
			$reordered = false;
			foreach ( $all as $x ) {
				if ( $x['parseable'] && (string) $x['prefix'] === (string) $dominant && $x['mo'] !== 0 && $x['mo'] !== $x['seq'] ) { $reordered = true; break; }
			}

			if ( ! $has_zero && ! $dup_values ) {
				if ( $reordered ) { $plan['skip'][] = [ 'id' => $iid, 'title' => get_the_title( $iid ) ]; }
				else { $plan['clean']++; }
				continue;
			}

			// --- Problem present. Can we auto-resolve from filenames? ---
			$multi_pref       = count( $prefix_counts ) > 1;
			$zero_imgs        = array_values( array_filter( $all, function ( $x ) { return $x['mo'] === 0; } ) );
			$zero_unparseable = count( array_filter( $zero_imgs, function ( $x ) { return ! $x['parseable']; } ) );

			if ( $multi_pref ) {
				$plan['review'][] = [ 'id' => $iid, 'title' => get_the_title( $iid ),
					'why' => count( $prefix_counts ) . ' filename prefixes (' . implode( ',', array_keys( $prefix_counts ) ) . ') — merged series, needs a lead-series rule' ];
				continue;
			}
			if ( $zero_unparseable > 0 ) {
				$plan['review'][] = [ 'id' => $iid, 'title' => get_the_title( $iid ),
					'why' => "{$zero_unparseable} unordered image(s) with a non-{lot}_{seq} filename — client must set order by hand" ];
				continue;
			}
			if ( empty( $zero_imgs ) ) {
				$plan['review'][] = [ 'id' => $iid, 'title' => get_the_title( $iid ), 'why' => 'two images share a menu_order (duplicate {seq}) — no zero to backfill' ];
				continue;
			}

			// Propose each zero image -> its filename {seq}; bail to review if that would collide.
			$proposed = [];
			foreach ( $zero_imgs as $x ) { $proposed[] = [ 'id' => $x['id'], 'name' => $x['name'], 'from' => 0, 'to' => $x['seq'] ]; }
			$existing_nonzero = array_map( function ( $x ) { return $x['mo']; }, array_filter( $all, function ( $x ) { return $x['mo'] !== 0; } ) );
			$combined         = array_merge( $existing_nonzero, array_map( function ( $c ) { return $c['to']; }, $proposed ) );
			if ( count( $combined ) !== count( array_unique( $combined ) ) ) {
				$plan['review'][] = [ 'id' => $iid, 'title' => get_the_title( $iid ), 'why' => 'backfilling {seq} would collide with an existing menu_order' ];
				continue;
			}

			usort( $proposed, function ( $x, $y ) { return $x['to'] <=> $y['to']; } );
			$plan['total_changes'] += count( $proposed );
			$plan['backfill'][] = [ 'id' => $iid, 'title' => get_the_title( $iid ), 'count' => count( $proposed ), 'changes' => $proposed ];
		}

		// Sort lists numerically by lot number.
		$by_lot = function ( $a, $b ) {
			$la = preg_match( '/Lot\s+(\d+)/i', $a['title'], $m ) ? (int) $m[1] : PHP_INT_MAX;
			$lb = preg_match( '/Lot\s+(\d+)/i', $b['title'], $m ) ? (int) $m[1] : PHP_INT_MAX;
			return $la <=> $lb;
		};
		usort( $plan['backfill'], $by_lot );
		usort( $plan['skip'], $by_lot );
		usort( $plan['review'], $by_lot );

		return $plan;
	}
}
