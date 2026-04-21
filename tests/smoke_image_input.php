<?php
/**
 * CLI smoke test for the image-input pipeline.
 * Uses a screenshot from a previous Playwright run as the visual reference.
 *
 * Run via: wp eval-file wp-content/plugins/beavermind/tests/smoke_image_input.php
 */

$plugin = \BeaverMind\Plugin::instance();

// Find a screenshot to use as input. Prefer the rendered-page screenshot
// from the most recent Playwright run.
$results_dir = WP_PLUGIN_DIR . '/beavermind/tests/playwright/test-results';
$candidates = array();
if ( is_dir( $results_dir ) ) {
	$files = scandir( $results_dir );
	foreach ( (array) $files as $f ) {
		if ( str_ends_with( (string) $f, '.png' ) ) {
			$candidates[] = $results_dir . '/' . $f;
		}
	}
}
sort( $candidates );

$image_path = $candidates ? $candidates[ count( $candidates ) - 1 ] : '';
if ( '' === $image_path || ! is_readable( $image_path ) ) {
	echo "FAIL: no screenshot available at $results_dir\n";
	exit( 1 );
}

echo "Using image: $image_path\n";
echo 'Size: ' . round( filesize( $image_path ) / 1024 ) . " KB\n\n";

$bytes = file_get_contents( $image_path );
$start = microtime( true );

$plan = $plugin->planner->plan_from_image(
	$bytes,
	'image/png',
	'Recreate the structure shown in the image as a polished landing page. The image is a screenshot of an existing page; mirror its sections (hero, features, testimonial, etc.) using the BeaverMind fragment library.',
	array( 'post_status' => 'publish', 'title' => 'Image-input smoke ' . gmdate( 'H:i:s' ) )
);

$elapsed = round( microtime( true ) - $start, 1 );

if ( is_wp_error( $plan ) ) {
	echo "FAIL ({$elapsed}s): " . $plan->get_error_message() . "\n";
	exit( 1 );
}

echo "OK ({$elapsed}s)\n";
echo "title: {$plan['page']['title']}\n";
echo 'fragments: ' . implode( ', ', array_column( $plan['fragments'], 'id' ) ) . "\n";
if ( ! empty( $plan['usage'] ) ) {
	echo "usage: in={$plan['usage']['input_tokens']} out={$plan['usage']['output_tokens']}\n";
}

$post_id = $plugin->writer->apply_plan( $plan, $plugin->fragments );
if ( is_wp_error( $post_id ) ) {
	echo 'WRITE FAIL: ' . $post_id->get_error_message() . "\n";
	exit( 1 );
}

echo "post_id: $post_id\n";
echo 'edit: ' . admin_url( "post.php?post=$post_id&action=edit" ) . "\n";
