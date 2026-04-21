<?php
/**
 * Smoke test: enumerate the fragment catalog, then write each fragment to its
 * own draft page so we can visually inspect them in the BB editor.
 *
 * Run via: wp eval-file wp-content/plugins/beavermind/tests/smoke_fragment_library.php
 */

$plugin = \BeaverMind\Plugin::instance();
$catalog = $plugin->fragments->catalog();

echo "=== Catalog ===\n";
foreach ( $catalog as $id => $entry ) {
	$slot_names = implode( ', ', array_keys( (array) ( $entry['meta']['slots'] ?? array() ) ) );
	echo "- $id [{$entry['source']}]\n";
	echo "  name: {$entry['meta']['name']}\n";
	echo "  category: {$entry['meta']['category']}\n";
	echo "  slots: $slot_names\n";
	$nodes = $plugin->fragments->get_nodes( $id );
	echo '  node count: ' . count( $nodes ?? array() ) . "\n";
}

echo "\n=== Writing one page per fragment ===\n";
$ids = array_keys( $catalog );
foreach ( $ids as $id ) {
	$plan = array(
		'page' => array(
			'title'       => 'BeaverMind smoke: ' . $id,
			'post_type'   => 'page',
			'post_status' => 'draft',
		),
		'fragments' => array(
			array(
				'id'    => $id,
				'slots' => array(),  // use placeholder defaults
			),
		),
	);
	$result = $plugin->writer->apply_plan( $plan, $plugin->fragments );
	if ( is_wp_error( $result ) ) {
		echo "FAIL  $id: " . $result->get_error_message() . "\n";
		continue;
	}
	$node_count = count( \FLBuilderModel::get_layout_data( 'published', (int) $result ) );
	echo "OK    $id -> post #$result ($node_count nodes)  edit: " . admin_url( "post.php?post=$result&action=edit" ) . "\n";
}

echo "\n=== Composed multi-fragment page ===\n";
$plan = array(
	'page' => array(
		'title'       => 'BeaverMind smoke: composed (' . gmdate( 'H:i:s' ) . ')',
		'post_type'   => 'page',
		'post_status' => 'publish',
	),
	'fragments' => array(
		array(
			'id'    => 'hero-centered',
			'slots' => array(
				'headline'  => 'BeaverMind multi-fragment test',
				'subhead'   => 'Composed from 4 fragments end-to-end.',
				'cta_label' => 'Explore',
				'cta_url'   => '#features',
			),
		),
		array(
			'id'    => 'feature-grid-3col',
			'slots' => array(
				'section_heading'   => 'What you get',
				'feature_1_title'   => 'Fast',
				'feature_1_body'    => 'Build a polished page in seconds.',
				'feature_2_title'   => 'Composable',
				'feature_2_body'    => 'Fragments combine like Lego.',
				'feature_3_title'   => 'On-brand',
				'feature_3_body'    => 'Designers own the visual system.',
			),
		),
		array(
			'id'    => 'two-col-content',
			'slots' => array(
				'left_heading'  => 'Before',
				'left_body'     => '<p>Hand-arranging modules took hours.</p>',
				'right_heading' => 'After',
				'right_body'    => '<p>BeaverMind composes them in seconds.</p>',
			),
		),
		array(
			'id'    => 'cta-banner',
			'slots' => array(
				'headline'  => 'Ready to ship?',
				'subhead'   => '<p style="text-align:center; color:#e5e7eb;">Try it on a real site today.</p>',
				'cta_label' => 'Get Started',
				'cta_url'   => '/get-started',
			),
		),
	),
);
$result = $plugin->writer->apply_plan( $plan, $plugin->fragments );
if ( is_wp_error( $result ) ) {
	echo 'FAIL: ' . $result->get_error_message() . "\n";
	exit( 1 );
}
$post_id = (int) $result;
$nodes = \FLBuilderModel::get_layout_data( 'published', $post_id );
$counts = array_count_values( array_map( fn( $n ) => $n->type, $nodes ) );
echo "OK    composed -> post #$post_id  total nodes: " . count( $nodes ) . "\n";
foreach ( $counts as $t => $c ) {
	echo "      $t: $c\n";
}

ob_start();
\FLBuilder::render_content_by_id( $post_id );
$html = ob_get_clean();
echo 'rendered HTML: ' . strlen( $html ) . " bytes\n";
echo 'fl-row count: ' . substr_count( $html, 'class="fl-row' ) . "\n";
echo 'fl-module count: ' . substr_count( $html, 'class="fl-module' ) . "\n";
echo "edit URL: " . admin_url( "post.php?post=$post_id&action=edit" ) . "\n";
echo "view URL: " . get_permalink( $post_id ) . "\n";
