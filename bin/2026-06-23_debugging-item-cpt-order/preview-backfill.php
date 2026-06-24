<?php
/**
 * READ-ONLY backfill PREVIEW (dry run). Writes NOTHING to the database.
 *
 * Restores rule #2 — menu_order = filename {seq} — for images left at the default
 * menu_order 0, WITHOUT touching lots whose order is already set. A lot is judged
 * only by whether its menu_order is broken (a 0, or a tie); filenames are used only
 * to propose a fix. See backfill-lib.php for the full classification.
 *
 * Buckets:
 *   BACKFILL     : has zero(s) on conventionally-named, single-series images; propose 0 -> {seq}.
 *   HAND-ORDERED : distinct menu_order already set that differs from filename order; leave alone.
 *   REVIEW       : can't auto-resolve (merged series / odd-named unordered image / duplicate seq).
 *   CLEAN        : import order intact, nothing to fix.
 *
 * Usage (NOTE: bare keywords, not --flags — wp-cli intercepts --flags before
 * they reach the script):
 *   wp eval-file preview-backfill.php                  # 2026-summer, summary
 *   wp eval-file preview-backfill.php 2026-summer v     # + per-image change lines
 *   wp eval-file preview-backfill.php 2026-summer json
 */

$args    = $args ?? [];
$slug    = '2026-summer';
$verbose = false;
$as_json = false;
foreach ( $args as $a ) {
	$a = (string) $a;
	if ( $a === 'v' || $a === 'verbose' ) { $verbose = true; }
	elseif ( $a === 'json' ) { $as_json = true; }
	elseif ( $a !== '' ) { $slug = $a; }
}

$term = get_term_by( 'slug', $slug, 'auction' );
if ( ! $term ) { WP_CLI::error( "Auction term not found: {$slug}" ); }

require_once __DIR__ . '/backfill-lib.php';
$plan          = caa_compute_backfill_plan( $term->term_id );
$total_changes = $plan['total_changes'];
$lots_scanned  = count( $plan['backfill'] ) + count( $plan['skip'] ) + count( $plan['review'] ) + $plan['clean'] + $plan['no_imgs'];

if ( $as_json ) {
	WP_CLI::log( wp_json_encode( [ 'auction' => $slug, 'total_changes' => $total_changes, 'plan' => $plan ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	return;
}

// ---- Human-readable dry-run report ----
WP_CLI::log( '' );
WP_CLI::log( '======================================================================' );
WP_CLI::log( '  BACKFILL PREVIEW (DRY RUN) — no database writes performed' );
WP_CLI::log( '======================================================================' );
WP_CLI::log( "Auction            : {$term->name} ({$slug})" );
WP_CLI::log( "Lots scanned       : {$lots_scanned}" );
WP_CLI::log( sprintf( "Lots to BACKFILL   : %d   (images to change: %d)", count( $plan['backfill'] ), $total_changes ) );
WP_CLI::log( sprintf( "Lots hand-ordered (no action)  : %d", count( $plan['skip'] ) ) );
WP_CLI::log( sprintf( "Lots needing REVIEW (can't auto-resolve) : %d", count( $plan['review'] ) ) );
WP_CLI::log( sprintf( "Lots already CLEAN (import order intact) : %d", $plan['clean'] ) );
WP_CLI::log( sprintf( "Lots with NO images: %d", $plan['no_imgs'] ) );
WP_CLI::log( str_repeat( '-', 70 ) );

WP_CLI::log( "\nPROPOSED BACKFILL (menu_order 0 -> filename {seq}):" );
if ( empty( $plan['backfill'] ) ) {
	WP_CLI::log( '   (none)' );
} else {
	foreach ( $plan['backfill'] as $lot ) {
		WP_CLI::log( sprintf( '  • post %d — %s', $lot['id'], $lot['title'] ) );
		$tos = array_map( function ( $c ) { return $c['to']; }, $lot['changes'] );
		WP_CLI::log( sprintf( '      %d image(s): set menu_order -> [%s]', $lot['count'], implode( ',', $tos ) ) );
		if ( $verbose ) {
			foreach ( $lot['changes'] as $c ) {
				WP_CLI::log( sprintf( '        attach %d  %-18s  menu_order: %d -> %d', $c['id'], $c['name'], $c['from'], $c['to'] ) );
			}
		}
	}
}

WP_CLI::log( "\nHAND-ORDERED — distinct menu_order already set, left untouched:" );
if ( empty( $plan['skip'] ) ) { WP_CLI::log( '   (none)' ); }
else { foreach ( $plan['skip'] as $s ) { WP_CLI::log( sprintf( '  • post %d — %s', $s['id'], $s['title'] ) ); } }

WP_CLI::log( "\nNEEDS REVIEW — can't auto-resolve from filenames:" );
if ( empty( $plan['review'] ) ) { WP_CLI::log( '   (none)' ); }
else { foreach ( $plan['review'] as $r ) { WP_CLI::log( sprintf( '  • post %d — %s  [%s]', $r['id'], $r['title'], $r['why'] ) ); } }

WP_CLI::log( "\n(DRY RUN complete. Nothing was written.)" );
