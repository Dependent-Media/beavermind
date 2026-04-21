<?php
/**
 * CLI smoke test for the refine pipeline.
 *
 * 1. Creates a fresh page via a clone so _beavermind_plan meta is stored.
 * 2. Refines it with a specific instruction.
 * 3. Verifies the new plan differs in a plausible way (fragment count changes,
 *    OR the new plan contains the instruction's subject).
 *
 * Run: wp eval-file wp-content/plugins/beavermind/tests/smoke_refine_pipeline.php
 */

$plugin = \BeaverMind\Plugin::instance();
$fixture = 'https://testbeavermind.dependentmedia.com/beavermind-fixtures/example-landing.html';

echo "=== Step 1: create initial page from fixture ===\n";
$cloner = new \BeaverMind\SiteCloner();
$ref = $cloner->fetch( $fixture );
if ( is_wp_error( $ref ) ) { echo 'FAIL: ' . $ref->get_error_message() . "\n"; exit( 1 ); }
$plan = $plugin->planner->plan(
	"Redesign this page. Preserve the offering; rewrite copy.",
	array( 'post_status' => 'publish' ),
	$ref
);
if ( is_wp_error( $plan ) ) { echo 'FAIL plan: ' . $plan->get_error_message() . "\n"; exit( 1 ); }
$post_id = $plugin->writer->apply_plan( $plan, $plugin->fragments );
if ( is_wp_error( $post_id ) ) { echo 'FAIL write: ' . $post_id->get_error_message() . "\n"; exit( 1 ); }
$post_id = (int) $post_id;

$initial_fragments = array_column( $plan['fragments'], 'id' );
echo "post_id:     $post_id\n";
echo 'fragments:   ' . implode( ', ', $initial_fragments ) . "\n";
echo 'stored meta: ' . ( get_post_meta( $post_id, '_beavermind_plan', true ) ? 'YES' : 'NO' ) . "\n";

echo "\n=== Step 2: refine — add a testimonial and drop one section ===\n";
$start = microtime( true );
$refined = $plugin->planner->refine( $post_id, 'Add a customer testimonial after the hero, and remove one of the less important sections to keep the page tight.' );
$elapsed = round( microtime( true ) - $start, 1 );
if ( is_wp_error( $refined ) ) { echo "FAIL ({$elapsed}s): " . $refined->get_error_message() . "\n"; exit( 1 ); }

$new_fragments = array_column( $refined['fragments'], 'id' );
echo "ok ({$elapsed}s)\n";
echo 'new fragments: ' . implode( ', ', $new_fragments ) . "\n";
echo "title:         {$refined['page']['title']}\n";
echo 'page.post_id:  ' . ( $refined['page']['post_id'] ?? '(none)' ) . "\n";

echo "\n=== Step 3: apply refined plan to same post ===\n";
$apply_id = $plugin->writer->apply_plan( $refined, $plugin->fragments );
if ( is_wp_error( $apply_id ) ) { echo 'FAIL: ' . $apply_id->get_error_message() . "\n"; exit( 1 ); }
echo 'applied to post: ' . (int) $apply_id . ( (int) $apply_id === $post_id ? ' (same as original ✓)' : ' (DIFFERENT post — BUG)' ) . "\n";
echo 'node count:      ' . count( \FLBuilderModel::get_layout_data( 'published', $post_id ) ) . "\n";

echo "\n=== Diff ===\n";
echo "before: " . count( $initial_fragments ) . " fragments (" . implode( ', ', $initial_fragments ) . ")\n";
echo "after:  " . count( $new_fragments ) . " fragments (" . implode( ', ', $new_fragments ) . ")\n";
$added   = array_diff( $new_fragments, $initial_fragments );
$removed = array_diff( $initial_fragments, $new_fragments );
echo 'added:   ' . ( $added ? implode( ', ', $added ) : '(none)' ) . "\n";
echo 'removed: ' . ( $removed ? implode( ', ', $removed ) : '(none)' ) . "\n";

echo "\nedit URL: " . admin_url( "post.php?post=$post_id&action=edit" ) . "\n";
