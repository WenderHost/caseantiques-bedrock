<?php
/**
 * READ-ONLY diagnostic: scan an auction's lots for broken image menu_order.
 *
 * Detects lots whose attachments cannot produce a deterministic "first image"
 * under the query used by the DataTables thumbnail view:
 *
 *     ORDER BY wp_posts.menu_order ASC  LIMIT 1   (no unique tiebreaker)
 *
 * A lot is flagged when 2+ images share the LOWEST menu_order value, because
 * MySQL is then free to return any of them first, and the LIMIT-1 plan may
 * disagree with the full-list plan (admin media grid).
 *
 * This script writes NOTHING to the database.
 *
 * Usage:
 *   wp eval-file scan-menu-order.php                 # defaults to 2026-summer
 *   wp eval-file scan-menu-order.php some-auction-slug
 *   wp eval-file scan-menu-order.php 2026-summer --json   # machine-readable
 */

$args = $args ?? [];
$slug = ( ! empty( $args[0] ) ) ? $args[0] : '2026-summer';
$as_json = in_array( '--json', $args, true );

$term = get_term_by( 'slug', $slug, 'auction' );
if ( ! $term ) {
	WP_CLI::error( "Auction term not found for slug: {$slug}" );
}

$item_ids = get_posts( [
	'post_type'      => 'item',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'tax_query'      => [ [
		'taxonomy' => 'auction',
		'field'    => 'term_id',
		'terms'    => $term->term_id,
	] ],
] );

$report = [
	'auction_slug' => $slug,
	'auction_name' => $term->name,
	'term_id'      => $term->term_id,
	'total_lots'   => count( $item_ids ),
	'flagged'      => [],
	'no_images'    => [],
	'ok'           => 0,
];

foreach ( $item_ids as $iid ) {
	// Full-list ordering (what the admin media grid roughly follows).
	$atts = get_posts( [
		'post_parent'    => $iid,
		'post_type'      => 'attachment',
		'post_mime_type' => 'image',
		'posts_per_page' => -1,
		'orderby'        => 'menu_order',
		'order'          => 'ASC',
	] );

	if ( empty( $atts ) ) {
		$report['no_images'][] = [ 'id' => $iid, 'title' => get_the_title( $iid ) ];
		continue;
	}

	// What the DataTables thumbnail query (LIMIT 1) actually returns.
	$limit1 = get_posts( [
		'post_parent'    => $iid,
		'post_type'      => 'attachment',
		'post_mime_type' => 'image',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'orderby'        => 'menu_order',
		'order'          => 'ASC',
	] );
	$thumb_id = ( is_array( $limit1 ) && ! empty( $limit1 ) ) ? $limit1[0] : null;

	$menu_orders = array_map( function ( $a ) { return (int) $a->menu_order; }, $atts );
	$min         = min( $menu_orders );
	$tied_at_min = count( array_filter( $menu_orders, function ( $v ) use ( $min ) { return $v === $min; } ) );

	// Display the actual FILENAME, not post_title (often the LiveAuctioneers id).
	$fname = function ( $id ) { return $id ? basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ) : '(none)'; };
	$full_first_id    = $atts[0]->ID;
	$full_first_title = $fname( $full_first_id );
	$thumb_title      = $fname( $thumb_id );

	// Flag when the lowest menu_order is shared by 2+ images (non-deterministic),
	// OR when the LIMIT-1 result disagrees with the full-list first result.
	$is_tied      = $tied_at_min > 1;
	$is_divergent = ( $thumb_id && $thumb_id !== $full_first_id );

	if ( $is_tied || $is_divergent ) {
		$report['flagged'][] = [
			'id'             => $iid,
			'title'          => get_the_title( $iid ),
			'image_count'    => count( $atts ),
			'menu_orders'    => $menu_orders,
			'min'            => $min,
			'tied_at_min'    => $tied_at_min,
			'full_first'     => $full_first_title,
			'thumb_shows'    => $thumb_title,
			'divergent'      => $is_divergent,
		];
	} else {
		$report['ok']++;
	}
}

if ( $as_json ) {
	WP_CLI::log( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	return;
}

// ---- Human-readable output ----
WP_CLI::log( '' );
WP_CLI::log( "Auction : {$report['auction_name']}  ({$slug}, term {$report['term_id']})" );
WP_CLI::log( "Lots    : {$report['total_lots']}" );
WP_CLI::log( sprintf( "OK      : %d", $report['ok'] ) );
WP_CLI::log( sprintf( "Flagged : %d", count( $report['flagged'] ) ) );
WP_CLI::log( sprintf( "No imgs : %d", count( $report['no_images'] ) ) );
WP_CLI::log( str_repeat( '-', 100 ) );

if ( empty( $report['flagged'] ) ) {
	WP_CLI::log( 'No flagged lots. All lots produce a deterministic first image.' );
} else {
	foreach ( $report['flagged'] as $f ) {
		$flagtype = [];
		if ( $f['tied_at_min'] > 1 ) {
			$flagtype[] = "{$f['tied_at_min']} imgs tied at menu_order={$f['min']}";
		}
		if ( $f['divergent'] ) {
			$flagtype[] = "thumb≠gallery";
		}
		WP_CLI::log( sprintf( "Lot post %d: %s", $f['id'], $f['title'] ) );
		WP_CLI::log( sprintf( "   images=%d  [%s]", $f['image_count'], implode( ',', $f['menu_orders'] ) ) );
		WP_CLI::log( sprintf( "   gallery-first: %s   |   thumbnail-shows: %s%s",
			$f['full_first'], $f['thumb_shows'], $f['divergent'] ? '  <-- DIVERGENT' : '' ) );
		WP_CLI::log( sprintf( "   flag: %s", implode( '; ', $flagtype ) ) );
		WP_CLI::log( '' );
	}
}
