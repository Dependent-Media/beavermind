<?php
/**
 * Sanity-check the Planner without making an API call.
 *
 * Verifies:
 *   - Plugin loads, Anthropic SDK class is autoloaded
 *   - System prompt renders correctly with the catalog inlined
 *   - JSON schema is well-formed and includes the right fragment ID enum
 *   - Calling plan() with no API key returns a clean WP_Error (not a fatal)
 *
 * Run via: wp eval-file wp-content/plugins/beavermind/tests/inspect_planner_prompt.php
 */

$plugin = \BeaverMind\Plugin::instance();

echo "=== Anthropic SDK loaded? ===\n";
echo class_exists( '\Anthropic\Client' ) ? "YES — \\Anthropic\\Client available\n" : "NO\n";

echo "\n=== Planner schema ===\n";
$reflection = new \ReflectionClass( $plugin->planner );
$method = $reflection->getMethod( 'plan_schema' );
$method->setAccessible( true );
$schema = $method->invoke( $plugin->planner, array_keys( $plugin->fragments->catalog() ) );
echo json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";

echo "\n=== System prompt (frozen + catalog) ===\n";
$frozen_method = $reflection->getMethod( 'frozen_system_prompt' );
$frozen_method->setAccessible( true );
$frozen = $frozen_method->invoke( $plugin->planner );
echo $frozen . "\n";

$catalog_method = $reflection->getMethod( 'render_catalog' );
$catalog_method->setAccessible( true );
$catalog_text = $catalog_method->invoke( $plugin->planner, $plugin->fragments->catalog() );
echo "\n--- catalog block ---\n";
echo $catalog_text;

echo "\n=== Token estimate ===\n";
$total_chars = strlen( $frozen ) + strlen( $catalog_text );
echo "Total system prompt chars: $total_chars\n";
echo 'Approx tokens (chars / 4): ~' . (int) ( $total_chars / 4 ) . "\n";
echo "Note: Opus 4.7 cache minimum is 4096 tokens. Cache breakpoint is in place but won't activate until catalog grows.\n";

echo "\n=== Calling plan() with no API key ===\n";
$result = $plugin->planner->plan( 'A test brief.' );
if ( is_wp_error( $result ) ) {
	echo 'OK — got expected WP_Error: ' . $result->get_error_code() . ' / ' . $result->get_error_message() . "\n";
} else {
	echo "UNEXPECTED — plan() did not return WP_Error:\n";
	print_r( $result );
}
