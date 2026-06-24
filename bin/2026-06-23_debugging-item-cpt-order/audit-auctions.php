<?php
/**
 * READ-ONLY cross-auction audit. Writes NOTHING.
 *
 * Ranks auctions by their `date` term-meta (YYYYMMDD) and, for the most recent N,
 * reports how prevalent the "image left at menu_order 0" problem is — the fingerprint
 * of the import not assigning order. Helps test whether a recent importer change
 * introduced this (recent auctions bad, older auctions clean).
 *
 * Per auction: lots, images, images at menu_order 0, lots with >=1 zero, and the
 * backfill-lib buckets (auto-fixable / review / hand-ordered / clean).
 *
 * Usage:
 *   wp eval-file audit-auctions.php            # most recent 6 auctions
 *   wp eval-file audit-auctions.php 10         # most recent 10
 */

global $wpdb;
$args = $args ?? [];
$N = 6;
foreach ( $args as $a ) { if ( ctype_digit( (string) $a ) ) { $N = (int) $a; } }

require_once __DIR__ . '/backfill-lib.php';

// Rank auctions by date meta (desc), keeping only real, non-empty ones.
$terms = get_terms( [ 'taxonomy' => 'auction', 'hide_empty' => false ] );
$dated = [];
foreach ( $terms as $t ) {
	$d = (int) get_term_meta( $t->term_id, 'date', true );
	if ( $d && $t->count > 0 ) { $dated[] = [ 'term' => $t, 'date' => $d ]; }
}
usort( $dated, function ( $a, $b ) { return $b['date'] <=> $a['date']; } );
$recent = array_slice( $dated, 0, $N );

WP_CLI::log( '' );
WP_CLI::log( 'CROSS-AUCTION AUDIT (read-only) — menu_order=0 prevalence, newest first' );
WP_CLI::log( str_repeat( '=', 118 ) );
WP_CLI::log( sprintf( '%-10s  %-34s  %5s  %6s  %8s  %8s  | %6s %6s  %6s  %6s  %6s',
	'date', 'auction', 'lots', 'imgs', '0-imgs', '0-lots', 'bf-lot', 'bf-img', 'review', 'hand', 'clean' ) );
WP_CLI::log( str_repeat( '-', 118 ) );

foreach ( $recent as $row ) {
	$t   = $row['term'];
	$ids = get_posts( [
		'post_type'      => 'item',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'tax_query'      => [ [ 'taxonomy' => 'auction', 'field' => 'term_id', 'terms' => $t->term_id ] ],
	] );
	if ( empty( $ids ) ) { continue; }
	$in = implode( ',', array_map( 'intval', $ids ) );

	$total_imgs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%' AND post_parent IN ($in)" );
	$zero_imgs  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%' AND menu_order=0 AND post_parent IN ($in)" );
	$zero_lots  = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_parent) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%' AND menu_order=0 AND post_parent IN ($in)" );

	$plan       = caa_compute_backfill_plan( $t->term_id );
	$bf_lots    = count( $plan['backfill'] );
	$bf_imgs    = $plan['total_changes'];
	$review     = count( $plan['review'] );
	$hand       = count( $plan['skip'] );
	$clean      = $plan['clean'];

	$name = html_entity_decode( wp_strip_all_tags( $t->name ) );
	if ( strlen( $name ) > 34 ) { $name = substr( $name, 0, 31 ) . '...'; }
	$date = preg_replace( '/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', (string) $row['date'] );

	WP_CLI::log( sprintf( '%-10s  %-34s  %5d  %6d  %8d  %8d  | %6d %6d  %6d  %6d  %6d',
		$date, $name, count( $ids ), $total_imgs, $zero_imgs, $zero_lots, $bf_lots, $bf_imgs, $review, $hand, $clean ) );
}

WP_CLI::log( str_repeat( '-', 118 ) );
WP_CLI::log( 'Legend: 0-imgs/0-lots = images/lots with menu_order=0 (the import-unset fingerprint).' );
WP_CLI::log( '        bf-lot/bf-img = auto-fixable backfill;  review = needs human;  hand = hand-ordered;  clean = import order intact.' );
