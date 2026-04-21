<?php
/**
 * Enumerate every Beaver Builder module registered on this site, classify by source
 * (BB core / UABB / PowerPack / other), and report counts + slug listings.
 *
 * Run via: wp eval-file wp-content/plugins/beavermind/tests/enumerate_modules.php
 */

if ( ! class_exists( '\FLBuilderModel' ) ) {
	echo "FLBuilderModel not loaded.\n";
	exit( 1 );
}

$modules = \FLBuilderModel::$modules;
echo 'Total registered modules: ' . count( $modules ) . "\n\n";

$by_group = array(
	'bb-core'    => array(),
	'uabb'       => array(),
	'powerpack'  => array(),
	'beavermind' => array(),
	'other'      => array(),
);

$all_rows = array();

foreach ( $modules as $slug => $instance ) {
	$dir = is_object( $instance ) && property_exists( $instance, 'dir' ) ? (string) $instance->dir : '';
	$enabled = method_exists( $instance, 'enabled' ) ? $instance->enabled() : true;
	$name = is_object( $instance ) && isset( $instance->name ) ? $instance->name : $slug;
	$category = is_object( $instance ) && isset( $instance->category ) ? $instance->category : '';
	$group_meta = is_object( $instance ) && isset( $instance->group ) ? $instance->group : '';

	if ( str_contains( $dir, '/bb-ultimate-addon/' ) || str_starts_with( $slug, 'uabb-' ) ) {
		$bucket = 'uabb';
	} elseif ( str_contains( $dir, '/bbpowerpack/' ) || str_starts_with( $slug, 'pp-' ) ) {
		$bucket = 'powerpack';
	} elseif ( str_contains( $dir, '/bb-plugin/' ) ) {
		$bucket = 'bb-core';
	} elseif ( str_contains( $dir, '/beavermind/' ) ) {
		$bucket = 'beavermind';
	} else {
		$bucket = 'other';
	}

	$by_group[ $bucket ][ $slug ] = array(
		'name'     => $name,
		'category' => is_array( $category ) ? implode( ',', $category ) : (string) $category,
		'group'    => is_array( $group_meta ) ? implode( ',', $group_meta ) : (string) $group_meta,
		'enabled'  => $enabled,
	);

	$all_rows[] = sprintf( '%-12s | %-40s | %-30s | %s',
		$bucket, $slug, $name, $by_group[ $bucket ][ $slug ]['category']
	);
}

foreach ( $by_group as $bucket => $entries ) {
	echo strtoupper( $bucket ) . ' — ' . count( $entries ) . " modules\n";
}

echo "\n=== Full table ===\n";
echo sprintf( "%-12s | %-40s | %-30s | %s\n", 'GROUP', 'SLUG', 'NAME', 'CATEGORY' );
echo str_repeat( '-', 120 ) . "\n";
sort( $all_rows );
foreach ( $all_rows as $row ) {
	echo $row . "\n";
}

echo "\n=== JSON export of catalog (first 5 of each group) ===\n";
$preview = array();
foreach ( $by_group as $bucket => $entries ) {
	$preview[ $bucket ] = array_slice( $entries, 0, 5, true );
}
echo wp_json_encode( $preview, JSON_PRETTY_PRINT ) . "\n";

echo "\n=== Sample defaults for one core module (heading) ===\n";
$defaults = \FLBuilderModel::get_module_defaults( 'heading' );
$keys = is_object( $defaults ) ? array_keys( get_object_vars( $defaults ) ) : array();
echo 'Field count: ' . count( $keys ) . "\n";
echo 'First 30 fields: ' . implode( ', ', array_slice( $keys, 0, 30 ) ) . "\n";

// Persist the full catalog as JSON for later use.
$out_path = WP_CONTENT_DIR . '/uploads/beavermind-module-catalog.json';
file_put_contents( $out_path, wp_json_encode( $by_group, JSON_PRETTY_PRINT ) );
echo "\nFull catalog written to: $out_path\n";
