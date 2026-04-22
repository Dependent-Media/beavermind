<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the Pexels Photos API.
 *
 * Pexels gives away free stock photos (200 req/hour, 20k/month). We use it to
 * fill image slots in fragments that would otherwise end up with
 * via.placeholder.com URLs. Attribution is NOT legally required under the
 * Pexels license, but is requested — we return the photographer fields so
 * callers can render a credit line.
 *
 * Results are cached in a transient keyed by query + orientation so repeated
 * generations with similar briefs (or 3-variant runs) don't re-spend budget.
 * Cache TTL is a day — long enough to matter, short enough that new photos on
 * Pexels eventually surface.
 */
class PexelsClient {

	const ENDPOINT     = 'https://api.pexels.com/v1/search';
	const CACHE_PREFIX = 'bm_pexels_';
	const CACHE_TTL    = DAY_IN_SECONDS;

	private string $api_key;

	public function __construct( string $api_key ) {
		$this->api_key = trim( $api_key );
	}

	public function is_configured(): bool {
		return '' !== $this->api_key;
	}

	/**
	 * Search for a photo matching $query and return the top result shaped for
	 * our fill pipeline. Null when nothing matches; WP_Error on HTTP / auth
	 * failure so callers can surface "key invalid" vs. "no results".
	 *
	 * Pexels' auth header is the bare key — no "Bearer " prefix — and the key
	 * starts with an underscore / random chars, not a recognizable scheme.
	 *
	 * @param string $query       Free-text search terms (e.g. "calendar office planning").
	 * @param string $orientation "landscape" | "portrait" | "square" — the photo module in
	 *                            fragments is landscape-biased so that's our default.
	 *
	 * @return array{url:string, alt:string, photographer:string, photographer_url:string, pexels_url:string}|null|\WP_Error
	 */
	public function search( string $query, string $orientation = 'landscape' ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'beavermind_no_pexels_key', 'Pexels API key is not set in BeaverMind settings.' );
		}
		$query = trim( $query );
		if ( '' === $query ) {
			return null;
		}

		$cache_key = self::CACHE_PREFIX . md5( strtolower( $query ) . '|' . $orientation );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'none' === $cached ) {
			return null;
		}

		$url = add_query_arg(
			array(
				'query'       => $query,
				'per_page'    => 15,
				'orientation' => in_array( $orientation, array( 'landscape', 'portrait', 'square' ), true ) ? $orientation : 'landscape',
			),
			self::ENDPOINT
		);

		$resp = wp_remote_get( $url, array(
			'timeout' => 15,
			'headers' => array( 'Authorization' => $this->api_key ),
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$status = wp_remote_retrieve_response_code( $resp );
		if ( 401 === $status || 403 === $status ) {
			return new \WP_Error( 'beavermind_pexels_auth', 'Pexels rejected the API key. Double-check it in settings.' );
		}
		if ( $status < 200 || $status >= 300 ) {
			return new \WP_Error( 'beavermind_pexels_api', "Pexels API returned HTTP $status." );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$photos = is_array( $body ) && isset( $body['photos'] ) && is_array( $body['photos'] )
			? $body['photos']
			: array();

		if ( empty( $photos ) ) {
			// Cache a miss too so a dud query doesn't keep hitting the API.
			set_transient( $cache_key, 'none', self::CACHE_TTL );
			return null;
		}

		// Pick randomly from the top 5 results so 3-variant runs on the same
		// query don't all get the same photo. md5 on query alone would pin us
		// to one image; shuffling breaks that.
		$top = array_slice( $photos, 0, 5 );
		$pick = $top[ array_rand( $top ) ];

		$src = is_array( $pick['src'] ?? null ) ? $pick['src'] : array();
		// `large2x` is ~1880×1250, big enough for heroes and under ~300 KB.
		// Falls back through progressively smaller sizes.
		$photo_url = (string) ( $src['large2x'] ?? $src['large'] ?? $src['medium'] ?? $src['original'] ?? '' );
		if ( '' === $photo_url ) {
			return null;
		}

		$out = array(
			'url'              => $photo_url,
			'alt'              => (string) ( $pick['alt'] ?? $query ),
			'photographer'     => (string) ( $pick['photographer'] ?? '' ),
			'photographer_url' => (string) ( $pick['photographer_url'] ?? '' ),
			'pexels_url'       => (string) ( $pick['url'] ?? '' ),
		);

		set_transient( $cache_key, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Check whether a URL looks like an empty / placeholder value that should
	 * be replaced. Used by ImageFiller before doing network calls.
	 */
	public static function is_placeholder_url( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url ) {
			return true;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return false;
		}
		$host = strtolower( $host );
		return in_array( $host, array( 'via.placeholder.com', 'placehold.co', 'picsum.photos' ), true )
			|| str_ends_with( $host, '.placeholder.com' );
	}
}
