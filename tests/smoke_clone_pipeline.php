<?php
/**
 * CLI smoke test for the full clone pipeline: fetch → extract → plan → write.
 * Run via: wp eval-file wp-content/plugins/beavermind/tests/smoke_clone_pipeline.php
 */

$plugin = \BeaverMind\Plugin::instance();
$fixture_url = 'https://testbeavermind.dependentmedia.com/beavermind-fixtures/example-landing.html';

echo "=== Fetch + extract ===\n";
$cloner = new \BeaverMind\SiteCloner();
$ref = $cloner->fetch( $fixture_url );
if ( is_wp_error( $ref ) ) {
	echo 'FAIL: ' . $ref->get_error_message() . "\n";
	exit( 1 );
}
echo "url:          {$ref['url']}\n";
echo "final_url:    {$ref['final_url']}\n";
echo "title:        {$ref['title']}\n";
echo "description:  {$ref['description']}\n";
echo 'sections:     ' . count( $ref['sections'] ) . "\n";
foreach ( $ref['sections'] as $i => $s ) {
	echo "  [$i] h{$s['level']} \"{$s['heading']}\" — " . count( $s['paragraphs'] ) . ' paragraphs, ' . count( $s['ctas'] ) . ' ctas, ' . count( $s['images'] ) . " images\n";
}

echo "\n--- render_for_prompt preview (first 800 chars) ---\n";
echo substr( $cloner->render_for_prompt( $ref ), 0, 800 ) . "\n";

echo "\n=== Plan (calls Claude — may take 30-60s) ===\n";
$start = microtime( true );
$plan = $plugin->planner->plan(
	"Redesign this page using the BeaverMind fragment library. Preserve the page's offering and CTAs; rewrite copy to be sharper.",
	array( 'post_status' => 'publish' ),
	$ref
);
$elapsed = round( microtime( true ) - $start, 1 );

if ( is_wp_error( $plan ) ) {
	echo "FAIL ({$elapsed}s): " . $plan->get_error_message() . "\n";
	exit( 1 );
}
echo "OK ({$elapsed}s)\n";
echo "title: {$plan['page']['title']}\n";
echo 'fragments: ' . count( $plan['fragments'] ) . "\n";
foreach ( $plan['fragments'] as $i => $f ) {
	echo "  [$i] {$f['id']}\n";
	foreach ( $f['slots'] as $name => $value ) {
		$short = substr( (string) $value, 0, 80 );
		echo "        $name: $short\n";
	}
}
if ( ! empty( $plan['usage'] ) ) {
	echo "usage: in={$plan['usage']['input_tokens']} out={$plan['usage']['output_tokens']} cache_w={$plan['usage']['cache_creation_input_tokens']} cache_r={$plan['usage']['cache_read_input_tokens']}\n";
}

echo "\n=== Write ===\n";
$post_id = $plugin->writer->apply_plan( $plan, $plugin->fragments );
if ( is_wp_error( $post_id ) ) {
	echo 'FAIL: ' . $post_id->get_error_message() . "\n";
	exit( 1 );
}
echo "post_id: $post_id\n";
echo 'node count: ' . count( \FLBuilderModel::get_layout_data( 'published', (int) $post_id ) ) . "\n";
echo 'edit:   ' . admin_url( "post.php?post=$post_id&action=edit" ) . "\n";
echo 'view:   ' . get_permalink( (int) $post_id ) . "\n";
echo 'bb-edit: ' . add_query_arg( 'fl_builder', '', get_permalink( (int) $post_id ) ) . "\n";
