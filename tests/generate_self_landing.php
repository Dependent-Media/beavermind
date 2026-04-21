<?php
/**
 * Use BeaverMind to design BeaverMind's own marketing landing page,
 * then write the resulting plan to docs/samples/landing-beavermind.json
 * so it's a reproducible artifact that ships with the repo.
 *
 * Run: wp eval-file wp-content/plugins/beavermind/tests/generate_self_landing.php
 *
 * After it succeeds, scp the file back to the repo:
 *   scp testbeavermind:httpdocs/wp-content/plugins/beavermind/docs/samples/landing-beavermind.json \
 *       /Users/josh/.../bb-dm-ai-builder/docs/samples/landing-beavermind.json
 */

$plugin = \BeaverMind\Plugin::instance();

$brief = <<<BRIEF
Design a landing page for BeaverMind, a WordPress plugin that uses Claude
(by Anthropic) to design Beaver Builder pages from any input — text brief,
URL, HTML, image, or Figma frame.

Audience: Beaver Builder agencies, freelancers, and prosumers who want to
ship sites in hours instead of days.

Tone: confident, technical, slightly playful. Not hype-y. Concrete claims
over slogans. Address skepticism head-on (yes, the output is editable
Beaver Builder modules, not static HTML; yes, the layouts look on-brand).

Must mention:
- Five input modalities
- "Composes Beaver Builder pages from a curated fragment library" (NOT raw
  HTML; NOT pixel-perfect clones — design-system-aware redesign)
- Iterative refinement: "make the hero bolder", "add a testimonials section"
- Brand-aware: extracts site name, theme color, logo
- Bulk variant generation: 1-5 plans per click
- Multi-page generation from a sitemap

Brand color: a deep building-mahogany #7c2d12. Page should feel craftsman /
architect-ish — BeaverMind composes pages the way an architect composes
buildings.
BRIEF;

$start = microtime( true );
$plan = $plugin->planner->plan( $brief, array( 'post_status' => 'publish', 'title' => 'BeaverMind — landing sample' ) );
$elapsed = round( microtime( true ) - $start, 1 );

if ( is_wp_error( $plan ) ) {
	echo "FAIL ({$elapsed}s): " . $plan->get_error_message() . "\n";
	exit( 1 );
}

echo "ok ({$elapsed}s)\n";
echo "title: {$plan['page']['title']}\n";
echo 'fragments: ' . implode( ', ', array_column( $plan['fragments'], 'id' ) ) . "\n";
echo 'theme: ' . wp_json_encode( $plan['theme'] ?? null ) . "\n";

// Strip the usage block before serializing — it's per-run telemetry, not
// part of the plan shape.
$plan_to_save = $plan;
unset( $plan_to_save['usage'] );
unset( $plan_to_save['page']['post_id'] );

$out_dir  = WP_PLUGIN_DIR . '/beavermind/docs/samples';
$out_path = $out_dir . '/landing-beavermind.json';
if ( ! is_dir( $out_dir ) ) {
	mkdir( $out_dir, 0755, true );
}
file_put_contents( $out_path, wp_json_encode( $plan_to_save, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
echo "wrote: $out_path (" . filesize( $out_path ) . " bytes)\n";

// Also apply it so we get a real generated post + a screenshot anchor for
// the README. Caller can delete the post after taking the screenshot.
$post_id = $plugin->writer->apply_plan( $plan, $plugin->fragments );
if ( is_wp_error( $post_id ) ) {
	echo "WRITE FAIL: " . $post_id->get_error_message() . "\n";
	exit( 1 );
}
echo "applied to post: $post_id\n";
echo "edit:  " . admin_url( "post.php?post=$post_id&action=edit" ) . "\n";
echo "view:  " . get_permalink( $post_id ) . "\n";
