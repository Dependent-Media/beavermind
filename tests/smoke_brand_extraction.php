<?php
/**
 * Sanity-check SiteCloner::extract_brand across the theme_color detection
 * tiers.
 *
 * Each fixture exercises ONE tier in isolation so regressions in any single
 * detection path are obvious. Failures print the fixture, expected source,
 * and actual source/hex so the break-site is self-explanatory.
 *
 * Run via: wp eval-file wp-content/plugins/beavermind/tests/smoke_brand_extraction.php
 */

use BeaverMind\SiteCloner;

$cloner = new SiteCloner();

$fixtures = array(
	'meta theme-color wins over everything else' => array(
		'html'   => '<html><head><meta name="theme-color" content="#2563eb"><style>--primary:#f97316</style></head><body></body></html>',
		'hex'    => '2563eb',
		'source' => 'meta',
	),
	'inline style on button when no meta'        => array(
		'html'   => '<html><head></head><body><button style="background:#f97316;color:white">Start</button></body></html>',
		'hex'    => 'f97316',
		'source' => 'button-inline',
	),
	'anchor with btn class and inline style'     => array(
		'html'   => '<html><head></head><body><a href="/" class="btn btn-primary" style="background-color:#dc2626">Get</a></body></html>',
		'hex'    => 'dc2626',
		'source' => 'button-inline',
	),
	'css var --primary in inline <style>'        => array(
		'html'   => '<html><head><style>:root{--primary:#16a34a;--text:#111}</style></head><body></body></html>',
		'hex'    => '16a34a',
		'source' => 'css-var',
	),
	'css var alias --brand-color'                => array(
		'html'   => '<html><head><style>:root{--brand-color:#7c3aed}</style></head><body></body></html>',
		'hex'    => '7c3aed',
		'source' => 'css-var',
	),
	'WordPress preset css var'                   => array(
		'html'   => '<html><head><style>:root{--wp--preset--color--primary:#ea580c}</style></head><body></body></html>',
		'hex'    => 'ea580c',
		'source' => 'css-var',
	),
	'button-rule in inline <style>'              => array(
		'html'   => '<html><head><style>.btn-primary{background-color:#f97316;color:#fff}body{color:#333}</style></head><body></body></html>',
		'hex'    => 'f97316',
		'source' => 'button-rule',
	),
	'frequency fallback finds repeated non-neutral' => array(
		'html'   => '<html><head><style>a{color:#0ea5e9}h2{border:1px solid #0ea5e9}p{background:#0ea5e9}body{color:#111827;background:#ffffff}</style></head><body></body></html>',
		'hex'    => '0ea5e9',
		'source' => 'css-frequency',
	),
	'neutrals filtered out (all gray/black/white)' => array(
		'html'   => '<html><head><style>body{color:#222;background:#fff;border:1px solid #eee}a{color:#888}</style></head><body></body></html>',
		'hex'    => null,
		'source' => null,
	),
	'8-digit hex (RGBA) is accepted as color'    => array(
		'html'   => '<html><head><meta name="theme-color" content="#f97316ff"></head><body></body></html>',
		'hex'    => 'f97316',
		'source' => 'meta',
	),
);

$pass = 0;
$fail = 0;

foreach ( $fixtures as $name => $case ) {
	// Use about:blank as the base so no external stylesheet fetches happen
	// during this smoke — we're testing inline-only detection paths.
	$result = $cloner->extract( $case['html'], 'about:blank', 'about:blank' );
	$got_hex    = $result['brand']['theme_color']        ?? null;
	$got_source = $result['brand']['theme_color_source'] ?? null;

	$ok = ( $got_hex === $case['hex'] ) && ( $got_source === $case['source'] );
	if ( $ok ) {
		echo "OK   $name (#$got_hex from $got_source)\n";
		$pass++;
	} else {
		$exp = null === $case['hex'] ? '(null)' : "#{$case['hex']} from {$case['source']}";
		$act = null === $got_hex   ? '(null)' : "#$got_hex from $got_source";
		echo "FAIL $name\n     expected: $exp\n     actual:   $act\n";
		$fail++;
	}
}

echo "\n$pass passed, $fail failed\n";
