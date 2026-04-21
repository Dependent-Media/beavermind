<?php
/**
 * Apply a saved BeaverMind plan JSON file as a new draft page.
 *
 * Usage:  wp eval-file wp-content/plugins/beavermind/bin/apply-sample-plan.php <path-to-json>
 *
 * Example: wp eval-file wp-content/plugins/beavermind/bin/apply-sample-plan.php \
 *            wp-content/plugins/beavermind/docs/samples/landing-beavermind.json
 *
 * No Claude API call is made — the plan is consumed verbatim. Useful for
 * dropping curated sample pages onto a fresh install without spending API
 * credits, and for testing fragment compatibility (a sample plan that
 * fails to apply means a fragment ID changed).
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Run via wp eval-file from a WordPress install.\n";
	exit( 1 );
}

global $argv;
// wp eval-file passes positional args after the script path.
$path = '';
if ( is_array( $argv ?? null ) ) {
	foreach ( $argv as $arg ) {
		if ( str_ends_with( (string) $arg, '.json' ) && is_readable( $arg ) ) {
			$path = (string) $arg;
			break;
		}
	}
}
if ( '' === $path ) {
	echo "Usage: wp eval-file " . basename( __FILE__ ) . " <path-to-plan.json>\n";
	exit( 1 );
}

$plan = json_decode( (string) file_get_contents( $path ), true );
if ( ! is_array( $plan ) ) {
	echo "FAIL: $path is not valid JSON\n";
	exit( 1 );
}

$plugin = \BeaverMind\Plugin::instance();
$plan['page']['post_status'] = $plan['page']['post_status'] ?? 'draft';
unset( $plan['page']['post_id'] );

$result = $plugin->writer->apply_plan( $plan, $plugin->fragments );
if ( is_wp_error( $result ) ) {
	echo 'FAIL: ' . $result->get_error_message() . "\n";
	exit( 1 );
}

$post_id = (int) $result;
echo "post_id: $post_id\n";
echo 'title:   ' . ( $plan['page']['title'] ?? '(untitled)' ) . "\n";
echo 'fragments: ' . implode( ', ', array_column( (array) ( $plan['fragments'] ?? array() ), 'id' ) ) . "\n";
echo 'edit:    ' . admin_url( "post.php?post=$post_id&action=edit" ) . "\n";
echo 'view:    ' . get_permalink( $post_id ) . "\n";
