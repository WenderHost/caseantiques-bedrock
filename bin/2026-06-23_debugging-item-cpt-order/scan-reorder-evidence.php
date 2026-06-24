<?php
/**
 * READ-ONLY diagnostic: look for evidence that the "Add Media" drag-and-drop
 * reorder has EVER persisted a custom menu_order.
 *
 * Logic: filenames are {lot}_{seq}.(jpg|jpeg). If an image's saved menu_order
 * differs from its filename {seq} (and isn't the default 0), that can ONLY have
 * come from a manual reorder (the importer always sets menu_order = seq).
 *
 * To avoid false positives from "duplicate series" lots (a second image set with
 * a different lot prefix attached to the same post), we only judge images whose
 * filename prefix matches the DOMINANT prefix for that item.
 *
 * Writes NOTHING to the database.
 *
 * Usage: wp eval-file scan-reorder-evidence.php [auction-slug]   (default 2026-summer)
 */

$args = $args ?? [];
$slug = ( ! empty( $args[0] ) ) ? $args[0] : '2026-summer';

$term = get_term_by( 'slug', $slug, 'auction' );
if ( ! $term ) { WP_CLI::error( "Auction term not found: {$slug}" ); }

$item_ids = get_posts( [
	'post_type'      => 'item',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'tax_query'      => [ [ 'taxonomy' => 'auction', 'field' => 'term_id', 'terms' => $term->term_id ] ],
] );

$reorder_hits   = [];   // images where menu_order != filename seq (non-zero) on dominant prefix
$lots_with_hits = [];
$total_imgs     = 0;
$parseable      = 0;

foreach ( $item_ids as $iid ) {
	$atts = get_posts( [
		'post_parent'    => $iid,
		'post_type'      => 'attachment',
		'post_mime_type' => 'image',
		'posts_per_page' => -1,
		'orderby'        => 'menu_order',
		'order'          => 'ASC',
	] );
	if ( empty( $atts ) ) { continue; }

	// Determine dominant filename prefix for this item.
	$prefix_counts = [];
	$parsed = [];
	foreach ( $atts as $a ) {
		$total_imgs++;
		// Parse the ACTUAL FILENAME, not post_title. post_title is often the
		// LiveAuctioneers id (e.g. "9087223_10") while the file is "113_10.jpeg";
		// the client's {lot}_{seq} convention lives in the filename.
		$fname = basename( (string) get_post_meta( $a->ID, '_wp_attached_file', true ) );
		// Capture the leading {lot}_{seq}, tolerating WP suffixes that follow the
		// sequence number: "-scaled" (core large-image), dedup "-1"/"-2", size "-WxH".
		// (?![0-9]) just guards against truncating a longer number.
		if ( preg_match( '/^([0-9]+[a-zA-Z]*)_([0-9]+)(?![0-9])/', $fname, $m ) ) {
			$parseable++;
			$parsed[ $a->ID ] = [ 'prefix' => $m[1], 'seq' => (int) $m[2], 'mo' => (int) $a->menu_order, 'name' => $fname ];
			$prefix_counts[ $m[1] ] = ( $prefix_counts[ $m[1] ] ?? 0 ) + 1;
		}
	}
	if ( empty( $prefix_counts ) ) { continue; }
	arsort( $prefix_counts );
	// Cast to string: a purely-numeric prefix like "274" becomes an INTEGER array key,
	// so array_key_first() returns int 274 while $p['prefix'] is the string "274".
	// A strict (!==) compare would then skip every image. Compare as strings.
	$dominant = (string) array_key_first( $prefix_counts );

	foreach ( $parsed as $aid => $p ) {
		if ( (string) $p['prefix'] !== $dominant ) { continue; }   // skip alien/duplicate series
		if ( $p['mo'] === 0 ) { continue; }               // skip un-set (default) — not reorder evidence
		if ( $p['mo'] !== $p['seq'] ) {
			$reorder_hits[] = [
				'item'  => $iid,
				'title' => get_the_title( $iid ),
				'file'  => $p['name'],
				'seq'   => $p['seq'],
				'mo'    => $p['mo'],
			];
			$lots_with_hits[ $iid ] = true;
		}
	}
}

WP_CLI::log( '' );
WP_CLI::log( "Auction        : {$term->name} ({$slug})" );
WP_CLI::log( "Lots scanned   : " . count( $item_ids ) );
WP_CLI::log( "Images total   : {$total_imgs}  (parseable {lot}_{seq}: {$parseable})" );
WP_CLI::log( "Reorder hits   : " . count( $reorder_hits ) . "  across " . count( $lots_with_hits ) . " lot(s)" );
WP_CLI::log( str_repeat( '-', 90 ) );
if ( empty( $reorder_hits ) ) {
	WP_CLI::log( 'NO evidence of any persisted manual reorder anywhere in this auction.' );
	WP_CLI::log( '(Every dominant-prefix image either matches its filename seq, or is still 0.)' );
} else {
	foreach ( $reorder_hits as $h ) {
		WP_CLI::log( sprintf( 'item %d (%s): %s  filename-seq=%d  but  menu_order=%d',
			$h['item'], $h['title'], $h['file'], $h['seq'], $h['mo'] ) );
	}
}
