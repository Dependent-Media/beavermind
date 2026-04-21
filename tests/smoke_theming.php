<?php
/**
 * Smoke-test per-fragment theming end-to-end. Clone the Lumen fixture
 * (theme-color = #2563eb), expect Claude to derive a primary color and
 * pass it through theme.colors. Then verify the generated nodes carry
 * the themed values.
 *
 * Run via: wp eval-file wp-content/plugins/beavermind/tests/smoke_theming.php
 */

$plugin = \BeaverMind\Plugin::instance();
$fixture = 'https://testbeavermind.dependentmedia.com/beavermind-fixtures/example-landing.html';

$cloner = new \BeaverMind\SiteCloner();
$ref = $cloner->fetch( $fixture );
if ( is_wp_error( $ref ) ) { echo 'fetch FAIL: ' . $ref->get_error_message() . "\n"; exit(1); }
echo "extracted brand: " . wp_json_encode( $ref['brand'] ) . "\n\n";

$start = microtime( true );
$plan = $plugin->planner->plan(
	'Redesign this page using the BeaverMind fragment library. Preserve the offering; rewrite copy.',
	array( 'post_status' => 'publish' ),
	$ref
);
$elapsed = round( microtime( true ) - $start, 1 );
if ( is_wp_error( $plan ) ) { echo "plan FAIL ({$elapsed}s): " . $plan->get_error_message() . "\n"; exit(1); }

echo "plan ok ({$elapsed}s)\n";
echo "theme returned: " . wp_json_encode( $plan['theme'] ?? null ) . "\n";
echo 'fragments: ' . implode( ', ', array_column( $plan['fragments'], 'id' ) ) . "\n";

$post_id = $plugin->writer->apply_plan( $plan, $plugin->fragments );
if ( is_wp_error( $post_id ) ) { echo 'write FAIL: ' . $post_id->get_error_message() . "\n"; exit(1); }
$post_id = (int) $post_id;
echo "post_id: $post_id\n\n";

// Verify theme propagated to actual nodes.
$nodes = \FLBuilderModel::get_layout_data( 'published', $post_id );
$themed_examples = array();
foreach ( $nodes as $node ) {
	if ( ! is_object( $node->settings ) ) { continue; }
	foreach ( array( 'bg_color', 'color', 'text_color' ) as $field ) {
		if ( isset( $node->settings->{$field} ) && '' !== (string) $node->settings->{$field} ) {
			$themed_examples[] = "{$node->type}/{$node->settings->type}".".{$field} = #{$node->settings->{$field}}";
		}
	}
}
echo "color fields actually set on the layout (first 12):\n";
foreach ( array_slice( array_unique( $themed_examples ), 0, 12 ) as $line ) {
	echo "  $line\n";
}
echo "\nedit: " . admin_url( "post.php?post=$post_id&action=edit" ) . "\n";
echo "view: " . get_permalink( $post_id ) . "?fl_builder\n";
