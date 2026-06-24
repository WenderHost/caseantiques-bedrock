<?php
/**
 * Backfill APPLY — sets menu_order = filename {seq} for unset (0) images.
 *
 * SAFE BY DEFAULT: dry-run unless you pass --apply. The plan is recomputed live via
 * backfill-lib.php (the same logic the preview uses), so it always reflects current
 * data — and each write is guarded again right before it happens:
 *   - the attachment still exists, is an attachment, and still belongs to the lot;
 *   - its menu_order is still 0 (we never overwrite a value someone has since set);
 *   - no sibling already holds the target menu_order (no collision).
 * Anything that fails a guard is skipped and reported, never forced.
 *
 * Only the BACKFILL bucket is ever touched. Hand-ordered, review, and clean lots
 * are never modified.
 *
 * Usage (NOTE: bare keywords, not --flags — wp-cli intercepts --flags before
 * they reach the script):
 *   wp eval-file apply-backfill.php                        # DRY RUN, all eligible lots
 *   wp eval-file apply-backfill.php 2026-summer lot=113    # DRY RUN, just Lot 113
 *   wp eval-file apply-backfill.php 2026-summer lot=113 apply   # WRITE Lot 113 only
 *   wp eval-file apply-backfill.php 2026-summer apply      # WRITE all eligible lots
 *   (add the keyword  v  for per-image lines)
 */

$args     = $args ?? [];
$slug     = '2026-summer';
$apply    = false;
$verbose  = false;
$only_lot = null;   // restrict to a single lot number, e.g. lot=113
foreach ( $args as $a ) {
	$a = (string) $a;
	if ( $a === 'apply' ) { $apply = true; }
	elseif ( $a === 'v' || $a === 'verbose' ) { $verbose = true; }
	elseif ( strpos( $a, 'lot=' ) === 0 ) { $only_lot = (int) substr( $a, 4 ); }
	elseif ( $a !== '' ) { $slug = $a; }
}

$term = get_term_by( 'slug', $slug, 'auction' );
if ( ! $term ) { WP_CLI::error( "Auction term not found: {$slug}" ); }

require_once __DIR__ . '/backfill-lib.php';
$plan = caa_compute_backfill_plan( $term->term_id );

// Optionally narrow to a single lot.
$backfill = $plan['backfill'];
if ( $only_lot !== null ) {
	$backfill = array_values( array_filter( $backfill, function ( $lot ) use ( $only_lot ) {
		return preg_match( '/Lot\s+(\d+)/i', $lot['title'], $m ) && (int) $m[1] === $only_lot;
	} ) );
}

$mode = $apply ? 'LIVE APPLY — writing to the database' : 'DRY RUN — no database writes';
WP_CLI::log( '' );
WP_CLI::log( '======================================================================' );
WP_CLI::log( "  BACKFILL APPLY :: {$mode}" );
WP_CLI::log( '======================================================================' );
WP_CLI::log( "Auction : {$term->name} ({$slug})" );
WP_CLI::log( "Scope   : " . ( $only_lot !== null ? "Lot {$only_lot} only" : 'all eligible lots' ) );
WP_CLI::log( "Lots    : " . count( $backfill ) . '   (eligible images: ' . array_sum( array_map( function ( $l ) { return $l['count']; }, $backfill ) ) . ')' );
WP_CLI::log( str_repeat( '-', 70 ) );

if ( empty( $backfill ) ) {
	WP_CLI::log( $only_lot !== null ? "Lot {$only_lot} is not in the auto-backfill set (clean, hand-ordered, or needs review)." : 'Nothing to backfill.' );
	return;
}

$applied = 0;
$skipped = 0;
$failed  = 0;

foreach ( $backfill as $lot ) {
	WP_CLI::log( sprintf( "\npost %d — %s", $lot['id'], $lot['title'] ) );

	// Current sibling menu_orders for collision checks (re-read live).
	$siblings = get_posts( [
		'post_parent'    => $lot['id'],
		'post_type'      => 'attachment',
		'post_mime_type' => 'image',
		'posts_per_page' => -1,
	] );
	$taken = [];   // menu_order => attachment ID currently holding it (non-zero only)
	foreach ( $siblings as $s ) {
		$smo = (int) $s->menu_order;
		if ( $smo !== 0 ) { $taken[ $smo ] = $s->ID; }
	}

	foreach ( $lot['changes'] as $c ) {
		$id   = $c['id'];
		$to   = $c['to'];
		$live = get_post( $id );

		// --- Guards ---
		if ( ! $live || $live->post_type !== 'attachment' || (int) $live->post_parent !== (int) $lot['id'] ) {
			WP_CLI::log( sprintf( '   SKIP attach %d (%s): no longer an image on this lot', $id, $c['name'] ) );
			$skipped++; continue;
		}
		if ( (int) $live->menu_order !== 0 ) {
			WP_CLI::log( sprintf( '   SKIP attach %d (%s): menu_order is now %d, not 0 — leaving as-is', $id, $c['name'], (int) $live->menu_order ) );
			$skipped++; continue;
		}
		if ( isset( $taken[ $to ] ) && (int) $taken[ $to ] !== (int) $id ) {
			WP_CLI::log( sprintf( '   SKIP attach %d (%s): target menu_order %d already held by attach %d', $id, $c['name'], $to, $taken[ $to ] ) );
			$skipped++; continue;
		}

		if ( ! $apply ) {
			WP_CLI::log( sprintf( '   would set attach %d  %-18s  menu_order: 0 -> %d', $id, $c['name'], $to ) );
			$applied++;   // counts as "would apply" in dry run
			$taken[ $to ] = $id;
			continue;
		}

		$res = wp_update_post( [ 'ID' => $id, 'menu_order' => $to ], true );
		if ( is_wp_error( $res ) ) {
			WP_CLI::log( sprintf( '   FAIL attach %d (%s): %s', $id, $c['name'], $res->get_error_message() ) );
			$failed++; continue;
		}
		WP_CLI::log( sprintf( '   set  attach %d  %-18s  menu_order: 0 -> %d', $id, $c['name'], $to ) );
		$applied++;
		$taken[ $to ] = $id;
	}
}

WP_CLI::log( "\n" . str_repeat( '-', 70 ) );
if ( $apply ) {
	WP_CLI::log( sprintf( 'DONE. Applied: %d   Skipped (guard): %d   Failed: %d', $applied, $skipped, $failed ) );
	WP_CLI::log( 'Object/query caches were invalidated by wp_update_post() (clean_post_cache bumps last_changed).' );
} else {
	WP_CLI::log( sprintf( 'DRY RUN. Would apply: %d   Would skip (guard): %d', $applied, $skipped ) );
	WP_CLI::log( 'Re-run with --apply to write. Tip: start with a single lot, e.g. --lot=113 --apply' );
}
