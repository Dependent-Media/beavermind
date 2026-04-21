<?php
/**
 * Export the inline fragment library to library/fragments.dat (BB's native
 * row-template format) + library/fragments.json (slots / theme_bindings).
 *
 * Run via wp-cli on the test site:
 *   wp eval-file wp-content/plugins/beavermind/bin/export-fragments-to-dat.php
 *
 * Why both files? .dat carries node trees in BB's portable serialized format;
 * fragments.json carries the slot maps and theme_bindings BeaverMind needs
 * (BB doesn't model those — they're our extension). FragmentLibrary loads
 * both at runtime.
 *
 * Designers extend the library by:
 *   1. Designing a row in the BB editor on any site.
 *   2. Right-click → Save as Template → row.
 *   3. Tools → Template Data Exporter → download as .dat.
 *   4. Drop the .dat in beavermind/library/ (any filename ending .dat).
 *   5. Add the slot map + theme_bindings to library/fragments.json keyed by
 *      a slug of the template's name (the same slug FragmentLibrary derives).
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run via wp eval-file from a WordPress install.\n";
	exit( 1 );
}

$plugin = \BeaverMind\Plugin::instance();

// We export ONLY inline fragments — the catalog also includes any existing
// .dat fragments, but we don't want to round-trip those (they're already
// .dat) and risk corruption.
$reflection = new ReflectionClass( $plugin->fragments );
$prop = $reflection->getProperty( 'inline_fragments' );
$prop->setAccessible( true );
$inline = $prop->getValue( $plugin->fragments );

if ( empty( $inline ) ) {
	echo "FAIL: no inline fragments registered\n";
	exit( 1 );
}

echo 'Exporting ' . count( $inline ) . " inline fragments…\n";

$dat = array(
	'layout' => array(),
	'row'    => array(),
	'module' => array(),
);
$meta = array();

foreach ( $inline as $id => $f ) {
	$nodes = $f['nodes'];
	$display_name = (string) ( $f['meta']['name'] ?? $id );

	// Sanity: id must round-trip via sanitize_title( name ). If they differ,
	// FragmentLibrary won't find the fragment after .dat reload.
	$reverse_id = sanitize_title( $display_name );
	if ( $reverse_id !== $id ) {
		echo "  WARN: id '$id' doesn't match slugified name '$reverse_id' — keeping name + ID alias in metadata.\n";
	}

	$dat['row'][] = (object) array(
		'name'     => $display_name,
		'type'     => 'row',
		'global'   => false,
		'nodes'    => $nodes,
		'settings' => new stdClass(),
	);

	$meta[ $reverse_id ] = $f['meta'];
	if ( $reverse_id !== $id ) {
		$meta[ $id ] = $f['meta'];
	}

	echo "  + $id  (\"$display_name\")  — " . count( $nodes ) . " nodes\n";
}

$dat_path  = WP_PLUGIN_DIR . '/beavermind/library/fragments.dat';
$json_path = WP_PLUGIN_DIR . '/beavermind/library/fragments.json';

file_put_contents( $dat_path, serialize( $dat ) );
file_put_contents( $json_path, wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

echo "\nWrote:\n";
echo '  ' . $dat_path  . ' (' . round( filesize( $dat_path  ) / 1024 ) . " KB)\n";
echo '  ' . $json_path . ' (' . round( filesize( $json_path ) / 1024 ) . " KB)\n";

// Verify round-trip: read the .dat back, confirm every fragment appears with
// the same node count.
$reloaded = \BeaverMind\FragmentLibrary::read_dat_file( $dat_path );
echo "\n=== Round-trip verification ===\n";
$mismatch = 0;
foreach ( $inline as $id => $f ) {
	$reverse_id = sanitize_title( (string) ( $f['meta']['name'] ?? $id ) );
	if ( ! isset( $reloaded[ $reverse_id ] ) ) {
		echo "  MISSING: $reverse_id\n";
		$mismatch++;
		continue;
	}
	$expected = count( $f['nodes'] );
	$actual   = count( $reloaded[ $reverse_id ] );
	if ( $expected !== $actual ) {
		echo "  COUNT MISMATCH: $reverse_id expected=$expected actual=$actual\n";
		$mismatch++;
	} else {
		echo "  OK  $reverse_id  ($actual nodes)\n";
	}
}

if ( $mismatch ) {
	echo "\nFAIL: $mismatch fragments mismatched\n";
	exit( 1 );
}
echo "\nAll fragments round-trip cleanly.\n";
