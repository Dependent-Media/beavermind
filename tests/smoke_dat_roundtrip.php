<?php
/**
 * Verify the .dat round-trip: export inline fragments → temp .dat →
 * re-load via FragmentLibrary::read_dat_file() → assert node counts match.
 *
 * Doesn't pollute library/ with the generated files; uses /tmp.
 *
 * Run via: wp eval-file wp-content/plugins/beavermind/tests/smoke_dat_roundtrip.php
 */

$plugin = \BeaverMind\Plugin::instance();

$ref = new ReflectionClass( $plugin->fragments );
$prop = $ref->getProperty( 'inline_fragments' );
$prop->setAccessible( true );
$inline = $prop->getValue( $plugin->fragments );

if ( empty( $inline ) ) {
	echo "FAIL: no inline fragments registered\n";
	exit( 1 );
}

$dat = array( 'layout' => array(), 'row' => array(), 'module' => array() );
foreach ( $inline as $id => $f ) {
	$dat['row'][] = (object) array(
		'name'     => (string) ( $f['meta']['name'] ?? $id ),
		'type'     => 'row',
		'global'   => false,
		'nodes'    => $f['nodes'],
		'settings' => new stdClass(),
	);
}

$tmp_path = '/tmp/beavermind-smoke.dat';
file_put_contents( $tmp_path, serialize( $dat ) );
echo "wrote $tmp_path (" . filesize( $tmp_path ) . " bytes)\n";

$reloaded = \BeaverMind\FragmentLibrary::read_dat_file( $tmp_path );
echo 'reloaded: ' . count( $reloaded ) . " entries\n\n";

$failures = 0;
foreach ( $inline as $id => $f ) {
	$slug = sanitize_title( (string) ( $f['meta']['name'] ?? $id ) );
	if ( ! isset( $reloaded[ $slug ] ) ) {
		echo "MISSING $slug (from $id)\n";
		$failures++;
		continue;
	}
	if ( count( $f['nodes'] ) !== count( $reloaded[ $slug ] ) ) {
		echo "MISMATCH $slug: " . count( $f['nodes'] ) . " vs " . count( $reloaded[ $slug ] ) . "\n";
		$failures++;
		continue;
	}
	// Spot-check: types of all nodes match.
	$expected_types = array_map( fn( $n ) => $n->type, array_values( $f['nodes'] ) );
	$actual_types   = array_map( fn( $n ) => $n->type, array_values( $reloaded[ $slug ] ) );
	sort( $expected_types );
	sort( $actual_types );
	if ( $expected_types !== $actual_types ) {
		echo "TYPE-MISMATCH $slug\n";
		$failures++;
		continue;
	}
	echo "OK  $slug  (" . count( $reloaded[ $slug ] ) . " nodes)\n";
}

unlink( $tmp_path );
if ( $failures ) {
	echo "\nFAIL: $failures fragments mismatched\n";
	exit( 1 );
}
echo "\nAll " . count( $inline ) . " fragments round-trip cleanly.\n";
