<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches a rendered PNG of a Figma frame given a Figma share URL.
 *
 * Parses the URL to extract the file_key (and optional node_id), calls
 * Figma's REST API to get a signed image URL, then downloads the bytes.
 * Output is shaped exactly like an uploaded image so it can flow into
 * Planner::plan_from_image() without any branching.
 *
 * Auth uses a Figma personal access token stored in plugin settings.
 */
class FigmaFetcher {

	private string $token;

	public function __construct( string $token ) {
		$this->token = trim( $token );
	}

	public function is_configured(): bool {
		return '' !== $this->token;
	}

	/**
	 * Fetch a Figma frame as PNG bytes.
	 *
	 * @return array{bytes: string, media_type: string}|\WP_Error
	 */
	public function fetch( string $share_url ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'beavermind_no_figma_token', 'Figma personal access token is not set in BeaverMind settings.' );
		}

		$parsed = self::parse_share_url( $share_url );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		// If a node ID was given, render that specific node. Otherwise fetch
		// the file's thumbnail URL.
		if ( '' !== $parsed['node_id'] ) {
			$image_url = $this->fetch_node_image( $parsed['file_key'], $parsed['node_id'] );
		} else {
			$image_url = $this->fetch_thumbnail_url( $parsed['file_key'] );
		}
		if ( is_wp_error( $image_url ) ) {
			return $image_url;
		}

		// Download the signed URL. These are S3 URLs that expire quickly.
		$resp = wp_remote_get( $image_url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$status = wp_remote_retrieve_response_code( $resp );
		if ( $status < 200 || $status >= 300 ) {
			return new \WP_Error( 'beavermind_figma_image_dl', "Figma image download returned HTTP $status." );
		}

		return array(
			'bytes'      => (string) wp_remote_retrieve_body( $resp ),
			'media_type' => 'image/png',
		);
	}

	/**
	 * Parse a Figma share URL into file_key + (optional) node_id.
	 *
	 * Accepts /file/, /design/, /proto/ paths. node-id can be in either the
	 * URL-encoded "1%3A2" or hyphenated "1-2" form; Figma's API wants
	 * colon form "1:2".
	 *
	 * @return array{file_key: string, node_id: string}|\WP_Error
	 */
	public static function parse_share_url( string $url ): array|\WP_Error {
		// Use `~` as delimiter — `#` clashes with the literal `#` inside the
		// character class `[^?#]` and breaks PCRE2 on PHP 8.3.
		if ( ! preg_match( '~^https?://(?:www\.)?figma\.com/(?:file|design|proto)/([A-Za-z0-9]+)(?:/[^?#]*)?(?:\?(.*))?~i', $url, $m ) ) {
			return new \WP_Error( 'beavermind_bad_figma_url', 'URL does not look like a Figma share link (https://www.figma.com/{file|design|proto}/...).' );
		}
		$file_key = $m[1];
		$node_id  = '';
		if ( ! empty( $m[2] ) ) {
			parse_str( $m[2], $args );
			$raw = (string) ( $args['node-id'] ?? '' );
			if ( '' !== $raw ) {
				// "1-2" → "1:2"; "1%3A2" already decoded by parse_str → "1:2".
				$node_id = str_replace( '-', ':', $raw );
			}
		}
		return array( 'file_key' => $file_key, 'node_id' => $node_id );
	}

	/**
	 * Render a specific node as a 2x PNG via /v1/images.
	 *
	 * @return string|\WP_Error  Signed S3 URL.
	 */
	private function fetch_node_image( string $file_key, string $node_id ) {
		$endpoint = 'https://api.figma.com/v1/images/' . rawurlencode( $file_key )
			. '?ids=' . rawurlencode( $node_id )
			. '&format=png&scale=2';

		$resp = wp_remote_get( $endpoint, array(
			'timeout' => 30,
			'headers' => array( 'X-Figma-Token' => $this->token ),
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		$status = wp_remote_retrieve_response_code( $resp );
		if ( $status < 200 || $status >= 300 ) {
			$msg = is_array( $body ) && isset( $body['err'] ) ? $body['err'] : "HTTP $status";
			return new \WP_Error( 'beavermind_figma_api', "Figma API error: $msg" );
		}
		if ( ! empty( $body['err'] ) ) {
			return new \WP_Error( 'beavermind_figma_api', 'Figma API error: ' . $body['err'] );
		}
		$image_url = $body['images'][ $node_id ] ?? '';
		if ( '' === $image_url ) {
			return new \WP_Error( 'beavermind_figma_no_image', 'Figma did not return an image for node ' . $node_id );
		}
		return (string) $image_url;
	}

	/**
	 * Use the file's thumbnail when no node_id is provided.
	 *
	 * @return string|\WP_Error  Thumbnail URL.
	 */
	private function fetch_thumbnail_url( string $file_key ) {
		$endpoint = 'https://api.figma.com/v1/files/' . rawurlencode( $file_key ) . '?depth=1';
		$resp = wp_remote_get( $endpoint, array(
			'timeout' => 30,
			'headers' => array( 'X-Figma-Token' => $this->token ),
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		$status = wp_remote_retrieve_response_code( $resp );
		if ( $status < 200 || $status >= 300 ) {
			$msg = is_array( $body ) && isset( $body['err'] ) ? $body['err'] : "HTTP $status";
			return new \WP_Error( 'beavermind_figma_api', "Figma API error: $msg" );
		}
		$thumb = (string) ( $body['thumbnailUrl'] ?? '' );
		if ( '' === $thumb ) {
			return new \WP_Error( 'beavermind_figma_no_thumb', 'Figma file has no thumbnail. Include a node-id in the URL to render a specific frame.' );
		}
		return $thumb;
	}
}
