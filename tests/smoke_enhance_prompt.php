<?php
/**
 * Smoke test: hit Planner::enhance_prompt() directly with a deliberately
 * weak brief and verify Haiku returns a tighter version.
 *
 * Run via: wp eval-file wp-content/plugins/beavermind/tests/smoke_enhance_prompt.php
 */

$plugin = \BeaverMind\Plugin::instance();

$weak = 'a landing page for my saas';

echo "input:\n  $weak\n\n";

$start = microtime( true );
$out = $plugin->planner->enhance_prompt( $weak );
$elapsed = round( ( microtime( true ) - $start ) * 1000 );

if ( is_wp_error( $out ) ) {
	echo "FAIL ({$elapsed}ms): " . $out->get_error_message() . "\n";
	exit( 1 );
}

echo "ok ({$elapsed}ms)\n\nenhanced:\n  " . str_replace( "\n", "\n  ", $out['enhanced'] ) . "\n";
