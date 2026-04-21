<?php
/**
 * Sanity-check FigmaFetcher::parse_share_url with no API call.
 * Run via: wp eval-file wp-content/plugins/beavermind/tests/smoke_figma_parser.php
 */

use BeaverMind\FigmaFetcher;

$cases = array(
	'https://www.figma.com/design/abc123XYZ/Project-Name?node-id=1-2',
	'https://www.figma.com/file/oldKey/Old-Project',
	'https://www.figma.com/design/abc123XYZ/Project-Name?node-id=1%3A2',
	'https://figma.com/proto/protoKey/Proto?node-id=10-20&starting-point-node-id=10-20',
	'https://example.com/foo',
);

foreach ( $cases as $url ) {
	$r = FigmaFetcher::parse_share_url( $url );
	if ( is_wp_error( $r ) ) {
		echo "FAIL ($url): " . $r->get_error_message() . "\n";
	} else {
		echo "OK   $url -> file_key={$r['file_key']} node_id={$r['node_id']}\n";
	}
}
