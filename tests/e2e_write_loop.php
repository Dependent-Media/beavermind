<?php
/**
 * E2E test: call LayoutWriter::apply_plan against the inline hero-centered fragment
 * and report what actually landed in the DB. Run via: wp eval-file tests/e2e_write_loop.php
 */

$plugin = \BeaverMind\Plugin::instance();

$plan = array(
	'page' => array(
		'title'       => 'BeaverMind E2E Test ' . gmdate( 'H:i:s' ),
		'post_type'   => 'page',
		'post_status' => 'draft',
	),
	'fragments' => array(
		array(
			'id' => 'hero-centered',
			'slots' => array(
				'headline'  => 'Hello from BeaverMind',
				'subhead'   => 'Assembled by the LayoutWriter from a hardcoded fragment.',
				'cta_label' => 'Learn more',
				'cta_url'   => home_url( '/' ),
			),
		),
	),
);

echo "=== Fragment library catalog ===\n";
$catalog = $plugin->fragments->catalog();
foreach ( $catalog as $id => $entry ) {
	echo "- $id (source={$entry['source']}, slots=" . implode( ',', array_keys( $entry['meta']['slots'] ?? array() ) ) . ")\n";
}

echo "\n=== Nodes returned by library->get_nodes('hero-centered') ===\n";
$nodes = $plugin->fragments->get_nodes( 'hero-centered' );
echo 'count: ' . count( $nodes ?? array() ) . "\n";
foreach ( (array) $nodes as $k => $v ) {
	echo "  key=$k node={$v->node} type={$v->type} parent=" . ( $v->parent ?? 'null' ) . " settings_type=" . gettype( $v->settings ) . "\n";
}

echo "\n=== apply_plan ===\n";
$result = $plugin->writer->apply_plan( $plan, $plugin->fragments );
if ( is_wp_error( $result ) ) {
	echo "ERROR: " . $result->get_error_message() . "\n";
	exit( 1 );
}
$post_id = (int) $result;
echo "Post ID: $post_id\n";
echo "Edit: " . admin_url( "post.php?post=$post_id&action=edit" ) . "\n";
echo "Preview: " . get_permalink( $post_id ) . "\n";
echo "BB enabled: " . ( get_post_meta( $post_id, '_fl_builder_enabled', true ) ? 'yes' : 'no' ) . "\n";

echo "\n=== Raw meta on post ===\n";
$raw_pub   = get_post_meta( $post_id, '_fl_builder_data', true );
$raw_draft = get_post_meta( $post_id, '_fl_builder_draft', true );
echo 'raw _fl_builder_data count: ' . ( is_array( $raw_pub ) ? count( $raw_pub ) : gettype( $raw_pub ) ) . "\n";
echo 'raw _fl_builder_draft count: ' . ( is_array( $raw_draft ) ? count( $raw_draft ) : gettype( $raw_draft ) ) . "\n";

echo "\n=== Via FLBuilderModel::get_layout_data ===\n";
$pub = \FLBuilderModel::get_layout_data( 'published', $post_id );
echo 'published count: ' . count( $pub ) . "\n";
foreach ( $pub as $k => $v ) {
	$type = is_object( $v ) ? ( $v->type ?? '?' ) : gettype( $v );
	echo "  key=$k type=$type\n";
	if ( is_object( $v ) && $v->type === 'module' && isset( $v->settings->type ) ) {
		echo "    module slug: {$v->settings->type}\n";
		if ( isset( $v->settings->heading ) ) { echo "    heading: {$v->settings->heading}\n"; }
		if ( isset( $v->settings->text ) )    { echo "    text: " . substr( (string) $v->settings->text, 0, 80 ) . "\n"; }
		if ( isset( $v->settings->link ) )    { echo "    link: {$v->settings->link}\n"; }
	}
}
