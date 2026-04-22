<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches a URL and extracts a structured summary of its content.
 *
 * Output is intentionally lossy: we strip scripts/styles, keep the semantic
 * structure (title, headings, paragraphs, images, CTAs), and hand the result
 * to Claude as the "reference" for a new page. We do NOT try to reproduce
 * the source visually — BeaverMind is about redesigning with Beaver Builder
 * fragments, not scraping and pasting.
 *
 * Output shape:
 *   [
 *     'url'         => string,
 *     'final_url'   => string,          // after redirects
 *     'title'       => string,
 *     'description' => string,          // meta description
 *     'brand'       => [
 *       'site_name'   => string,        // og:site_name | document title head
 *       'theme_color' => string|null,   // hex from <meta name="theme-color">
 *       'logo_url'    => string|null,   // best-guess logo image
 *       'og_image'    => string|null,   // og:image
 *       'fonts'       => [string, ...], // family names from Google Fonts links
 *     ],
 *     'sections'    => [
 *       [
 *         'heading'    => string,       // h1/h2/h3 text
 *         'level'      => int,          // 1/2/3
 *         'paragraphs' => [string, ...],
 *         'images'     => [['src'=>..., 'alt'=>...], ...],
 *         'ctas'       => [['text'=>..., 'href'=>...], ...],
 *       ],
 *       ...
 *     ],
 *   ]
 */
class SiteCloner {

	const MAX_BODY_BYTES = 2_000_000; // 2 MB cap — avoid memory blowouts on huge pages

	/**
	 * Fetch a URL and extract structured content.
	 *
	 * @return array|\WP_Error
	 */
	public function fetch( string $url ) {
		$url = trim( $url );
		if ( '' === $url ) {
			return new \WP_Error( 'beavermind_empty_url', 'URL is required.' );
		}
		if ( ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'beavermind_bad_url', 'That does not look like a valid URL.' );
		}

		$response = wp_remote_get( $url, array(
			'timeout'     => 20,
			'redirection' => 5,
			'user-agent'  => 'BeaverMind/0.1 (+https://dependentmedia.com/beavermind)',
			'headers'     => array(
				'Accept'          => 'text/html,application/xhtml+xml',
				'Accept-Language' => 'en-US,en;q=0.9',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 400 ) {
			return new \WP_Error( 'beavermind_fetch_failed', "Source returned HTTP $status." );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			$body = substr( $body, 0, self::MAX_BODY_BYTES );
		}

		$final_url = $url;
		$headers   = wp_remote_retrieve_headers( $response );
		if ( isset( $headers['location'] ) ) {
			$final_url = is_array( $headers['location'] ) ? end( $headers['location'] ) : $headers['location'];
		}

		return $this->extract( $body, $url, $final_url );
	}

	/**
	 * Parse raw HTML into the structured shape. Exposed for testing.
	 */
	public function extract( string $html, string $url, string $final_url ): array {
		libxml_use_internal_errors( true );
		$dom = new \DOMDocument();
		// Hint the parser about encoding and suppress warnings from malformed HTML.
		$prefixed = '<?xml encoding="utf-8"?>' . $html;
		$dom->loadHTML( $prefixed, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();

		// Extract brand signals BEFORE stripping nodes — header/nav often
		// contain the logo, and <link>/<meta> tags carry font + color hints.
		$brand = $this->extract_brand( $dom, $final_url );

		// Now remove irrelevant nodes for content extraction.
		foreach ( array( 'script', 'style', 'noscript', 'svg', 'iframe', 'nav', 'footer', 'header', 'form' ) as $tag ) {
			$nodes = iterator_to_array( $dom->getElementsByTagName( $tag ) );
			foreach ( $nodes as $n ) {
				$n->parentNode && $n->parentNode->removeChild( $n );
			}
		}

		$title = '';
		$title_tags = $dom->getElementsByTagName( 'title' );
		if ( $title_tags->length ) {
			$title = trim( (string) $title_tags->item( 0 )->textContent );
		}

		$description = '';
		foreach ( $dom->getElementsByTagName( 'meta' ) as $meta ) {
			$name = strtolower( (string) $meta->getAttribute( 'name' ) );
			$prop = strtolower( (string) $meta->getAttribute( 'property' ) );
			if ( 'description' === $name || 'og:description' === $prop ) {
				$description = trim( (string) $meta->getAttribute( 'content' ) );
				if ( '' !== $description ) {
					break;
				}
			}
		}

		// Walk the body, accumulating sections keyed off h1/h2/h3.
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		$sections = array();
		$current  = $this->new_section( 'Introduction', 1 );

		if ( $body ) {
			$this->walk( $body, $sections, $current, $final_url );
		}

		// Flush the final section.
		if ( $this->section_has_content( $current ) ) {
			$sections[] = $current;
		}

		// Trim noise: drop sections with nothing useful.
		$sections = array_values( array_filter( $sections, array( $this, 'section_has_content' ) ) );

		// Use the document title as a fallback for site_name when og:site_name
		// is missing. Strip the leading product name out of common patterns
		// like "Product — Tagline".
		if ( '' === $brand['site_name'] && '' !== $title ) {
			$brand['site_name'] = preg_split( '/\s+[—–|-]\s+/u', $title )[0] ?? $title;
		}

		return array(
			'url'         => $url,
			'final_url'   => $final_url,
			'title'       => $title,
			'description' => $description,
			'brand'       => $brand,
			'sections'    => $sections,
		);
	}

	/**
	 * Sniff brand signals from <meta>, <link>, CSS, and likely-logo <img> elements.
	 * All values are best-effort — Claude treats them as hints, not requirements.
	 *
	 * theme_color detection cascades through five sources (in priority order):
	 *   1. `<meta name="theme-color">`
	 *   2. Inline `style=""` on buttons / CTA-class anchors
	 *   3. CSS custom properties (`--primary`, `--brand`, etc.) in inline `<style>`
	 *      or same-origin stylesheets
	 *   4. Button-selector rules in the same CSS sources
	 *   5. Most-frequent non-neutral hex color across the collected CSS
	 *
	 * `theme_color_source` is a debug string tagging which tier produced the
	 * final value — surfaced in the admin notice so users can see where the
	 * color came from.
	 *
	 * @return array{site_name:string, theme_color:?string, theme_color_source:?string, logo_url:?string, og_image:?string, fonts:array<int,string>}
	 */
	private function extract_brand( \DOMDocument $dom, string $base_url ): array {
		$brand = array(
			'site_name'          => '',
			'theme_color'        => null,
			'theme_color_source' => null,
			'logo_url'           => null,
			'og_image'           => null,
			'fonts'              => array(),
		);

		// Meta tags: theme-color, og:site_name, og:image.
		foreach ( $dom->getElementsByTagName( 'meta' ) as $meta ) {
			$name    = strtolower( (string) $meta->getAttribute( 'name' ) );
			$prop    = strtolower( (string) $meta->getAttribute( 'property' ) );
			$content = trim( (string) $meta->getAttribute( 'content' ) );
			if ( '' === $content ) {
				continue;
			}
			if ( 'theme-color' === $name && null === $brand['theme_color'] ) {
				$color = $this->normalize_color( $content );
				if ( null !== $color ) {
					$brand['theme_color']        = $color;
					$brand['theme_color_source'] = 'meta';
				}
			} elseif ( 'og:site_name' === $prop && '' === $brand['site_name'] ) {
				$brand['site_name'] = $content;
			} elseif ( 'og:image' === $prop && null === $brand['og_image'] ) {
				$brand['og_image'] = $this->absolutize( $content, $base_url );
			}
		}

		// Fall back through the CSS tiers if <meta name="theme-color"> didn't
		// cover it. Runs ordered priority — each tier returns null when it has
		// nothing to say and passes to the next.
		if ( null === $brand['theme_color'] ) {
			$from_inline_attr = $this->theme_color_from_inline_button_styles( $dom );
			if ( null !== $from_inline_attr ) {
				$brand['theme_color']        = $from_inline_attr;
				$brand['theme_color_source'] = 'button-inline';
			}
		}

		if ( null === $brand['theme_color'] ) {
			$css = $this->collect_css( $dom, $base_url );
			if ( '' !== $css ) {
				$found = $this->theme_color_from_css( $css );
				if ( null !== $found ) {
					$brand['theme_color']        = $found['hex'];
					$brand['theme_color_source'] = $found['source'];
				}
			}
		}

		// Google Fonts links: parse family names out of fonts.googleapis.com hrefs.
		foreach ( $dom->getElementsByTagName( 'link' ) as $link ) {
			$href = (string) $link->getAttribute( 'href' );
			if ( false === stripos( $href, 'fonts.googleapis.com' ) ) {
				continue;
			}
			// css?family=Inter:wght@400;700&family=Lora -> [Inter, Lora]
			// css2?family=Inter:wght@400..700&display=swap                                -> [Inter]
			$query = wp_parse_url( $href, PHP_URL_QUERY ) ?: '';
			parse_str( $query, $args );
			$family_param = $args['family'] ?? '';
			if ( '' === $family_param ) {
				continue;
			}
			// Multiple families repeat the `family=` key — parse_str only keeps the
			// last. Re-parse the raw query for all values.
			preg_match_all( '/(?:^|&)family=([^&]+)/', $query, $matches );
			foreach ( $matches[1] as $raw_family ) {
				$decoded = urldecode( $raw_family );
				$family  = trim( explode( ':', $decoded )[0] );
				$family  = str_replace( '+', ' ', $family );
				if ( '' !== $family && ! in_array( $family, $brand['fonts'], true ) ) {
					$brand['fonts'][] = $family;
				}
			}
		}

		// Logo: <img> with "logo" in src/alt/class. Score candidates and pick the best.
		$best_score = 0;
		foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
			$src   = (string) $img->getAttribute( 'src' );
			$alt   = strtolower( (string) $img->getAttribute( 'alt' ) );
			$class = strtolower( (string) $img->getAttribute( 'class' ) );
			if ( '' === $src ) {
				continue;
			}
			$score = 0;
			if ( str_contains( strtolower( $src ), 'logo' ) ) { $score += 3; }
			if ( str_contains( $alt, 'logo' ) )                { $score += 2; }
			if ( str_contains( $class, 'logo' ) )              { $score += 2; }
			// Prefer SVG/PNG over photo formats (logos are rarely JPEG).
			if ( preg_match( '/\.(svg|png)(\?|$)/i', $src ) )  { $score += 1; }
			if ( $score > $best_score ) {
				$best_score = $score;
				$brand['logo_url'] = $this->absolutize( $src, $base_url );
			}
		}

		return $brand;
	}

	/**
	 * Walk likely-button elements for an inline `style="background:#..."` and
	 * return the first non-neutral hex we find. Fastest signal — no network,
	 * no CSS parsing; works well on Tailwind / utility-class sites that inject
	 * inline styles, and on handcoded sites that happen to inline button
	 * colors.
	 *
	 * Candidates are `<button>` elements and anchors with "btn" / "button" /
	 * "cta" in their class.
	 */
	private function theme_color_from_inline_button_styles( \DOMDocument $dom ): ?string {
		$candidates = array();
		foreach ( $dom->getElementsByTagName( 'button' ) as $el ) {
			$candidates[] = $el;
		}
		foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
			$class = strtolower( (string) $a->getAttribute( 'class' ) );
			if ( str_contains( $class, 'btn' ) || str_contains( $class, 'button' ) || str_contains( $class, 'cta' ) ) {
				$candidates[] = $a;
			}
		}
		foreach ( $candidates as $el ) {
			$style = (string) $el->getAttribute( 'style' );
			if ( '' === $style ) {
				continue;
			}
			if ( preg_match( '/background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,8})/i', $style, $m ) ) {
				$hex = $this->normalize_color( $m[1] );
				if ( null !== $hex && ! $this->is_neutral_color( $hex ) ) {
					return $hex;
				}
			}
		}
		return null;
	}

	/**
	 * Gather all CSS we can reach cheaply: inline `<style>` tags concatenated
	 * with up to two same-origin stylesheets (budget-capped at 500KB each).
	 * Cross-origin stylesheets are skipped to avoid burning time on Google
	 * Fonts, Cloudflare, analytics CSS, etc.
	 *
	 * Failures (timeouts, non-2xx, unreadable bodies) are silent — CSS
	 * collection is an optional enrichment, not a required step. A `null`
	 * from any fetch just means that source didn't contribute.
	 */
	private function collect_css( \DOMDocument $dom, string $base_url ): string {
		$blobs = array();

		foreach ( $dom->getElementsByTagName( 'style' ) as $style ) {
			$text = (string) $style->textContent;
			if ( '' !== $text ) {
				$blobs[] = $text;
			}
		}

		// External stylesheets — same-origin only, first two visible <link>s.
		// Doing this after inline <style> so the order in the concatenated
		// blob mirrors cascade order (which helps the frequency fallback
		// weigh head-of-document critical CSS more heavily).
		$base_parts = wp_parse_url( $base_url );
		$base_host  = isset( $base_parts['host'] ) ? strtolower( (string) $base_parts['host'] ) : '';
		if ( '' !== $base_host ) {
			$fetched = 0;
			foreach ( $dom->getElementsByTagName( 'link' ) as $link ) {
				if ( $fetched >= 2 ) {
					break;
				}
				$rel = strtolower( (string) $link->getAttribute( 'rel' ) );
				if ( 'stylesheet' !== $rel ) {
					continue;
				}
				$href = (string) $link->getAttribute( 'href' );
				if ( '' === $href ) {
					continue;
				}
				$abs = $this->absolutize( $href, $base_url );
				$url_parts = wp_parse_url( $abs );
				$host = isset( $url_parts['host'] ) ? strtolower( (string) $url_parts['host'] ) : '';
				if ( $host !== $base_host ) {
					continue;
				}
				// Skip obvious non-theme stylesheets. Google Fonts never carries
				// brand colors; wp-emoji / dashicons / block-library are from
				// WordPress itself. Saves a pointless round-trip.
				if ( preg_match( '#(?:/wp-emoji|/dashicons|/block-library|/admin-bar|googleapis\.com|gstatic\.com|cloudflare\.com)#i', $abs ) ) {
					continue;
				}
				$css = $this->fetch_stylesheet( $abs );
				if ( null !== $css ) {
					$blobs[]   = $css;
					$fetched++;
				}
			}
		}

		return implode( "\n", $blobs );
	}

	private function fetch_stylesheet( string $url ): ?string {
		$resp = wp_remote_get( $url, array(
			'timeout'     => 8,
			'redirection' => 3,
			'user-agent'  => 'BeaverMind/0.1 (+https://dependentmedia.com/beavermind)',
			'headers'     => array( 'Accept' => 'text/css,*/*;q=0.1' ),
		) );
		if ( is_wp_error( $resp ) ) {
			return null;
		}
		$status = wp_remote_retrieve_response_code( $resp );
		if ( $status < 200 || $status >= 300 ) {
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $resp );
		if ( strlen( $body ) > 500_000 ) {
			$body = substr( $body, 0, 500_000 );
		}
		return $body;
	}

	/**
	 * Apply a three-tier search to a CSS text blob and return the best hex
	 * candidate alongside a source tag describing WHICH tier picked it.
	 *
	 * Tiers, in priority order:
	 *   - css-var:  `--primary: #f97316` and its common aliases
	 *   - button-rule: `.btn-primary { background: #f97316 }` and variants
	 *   - frequency: most-repeated non-neutral hex (min 3 occurrences)
	 *
	 * Each tier filters out grayscale / near-black / near-white so the fallback
	 * doesn't latch onto neutral body text or container backgrounds.
	 *
	 * @return array{hex: string, source: string}|null
	 */
	private function theme_color_from_css( string $css ): ?array {
		// Tier 1: CSS custom properties named primary / brand / accent / theme.
		// Also catches `--wp--preset--color--primary` (WordPress block editor)
		// and the bare `--color-primary` convention.
		$css_var_pattern = '/--(?:primary|brand(?:[-_](?:primary|color))?|accent(?:[-_]color)?|color[-_]primary|primary[-_]color|theme[-_]color|wp--preset--color--(?:primary|brand|accent))\s*:\s*(#[0-9a-fA-F]{3,8})/i';
		if ( preg_match_all( $css_var_pattern, $css, $m ) ) {
			foreach ( $m[1] as $hex ) {
				$norm = $this->normalize_color( $hex );
				if ( null !== $norm && ! $this->is_neutral_color( $norm ) ) {
					return array( 'hex' => $norm, 'source' => 'css-var' );
				}
			}
		}

		// Tier 2: button-selector rules with an explicit background color.
		// Matches `.btn`, `.btn-primary`, `.button`, `.wp-block-button__link`,
		// and bare `button` — with or without modifiers before the opening
		// brace. Lazy `[^{}]*` keeps us inside the same rule block.
		$btn_pattern = '/(?:^|[\s,{}>])(?:\.btn(?:[-_][a-z0-9]+)?|\.button(?:[-_][a-z0-9]+)?|\.wp-block-button__link|button)\b[^{}]*\{[^{}]*background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,8})/i';
		if ( preg_match_all( $btn_pattern, $css, $m ) ) {
			foreach ( $m[1] as $hex ) {
				$norm = $this->normalize_color( $hex );
				if ( null !== $norm && ! $this->is_neutral_color( $norm ) ) {
					return array( 'hex' => $norm, 'source' => 'button-rule' );
				}
			}
		}

		// Tier 3: frequency. Walk every `#xxx` / `#xxxxxx` hex and return the
		// most common non-neutral with 3+ hits. Avoids one-off accent colors
		// (e.g. a single highlight border) masquerading as the brand.
		if ( preg_match_all( '/#([0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?)\b/', $css, $m ) ) {
			$counts = array();
			foreach ( $m[1] as $hex ) {
				$norm = $this->normalize_color( '#' . $hex );
				if ( null !== $norm && ! $this->is_neutral_color( $norm ) ) {
					$counts[ $norm ] = ( $counts[ $norm ] ?? 0 ) + 1;
				}
			}
			if ( ! empty( $counts ) ) {
				arsort( $counts );
				foreach ( $counts as $hex => $count ) {
					if ( $count >= 3 ) {
						return array( 'hex' => $hex, 'source' => 'css-frequency' );
					}
				}
			}
		}

		return null;
	}

	/**
	 * Heuristic neutral filter. Rejects grays (low saturation), near-blacks,
	 * and near-whites so the frequency fallback doesn't surface container
	 * background colors or body text as the "brand".
	 *
	 * Input must be a 6-digit lowercase hex without the leading #.
	 */
	private function is_neutral_color( string $hex ): bool {
		if ( 1 !== preg_match( '/^[0-9a-f]{6}$/', $hex ) ) {
			return true;
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );

		// Low channel spread = gray / black / white. Threshold of 30 tolerates
		// near-neutrals like #333 (range 0) but cuts muted slate colors (range
		// ~20) that rarely function as brand primaries.
		if ( ( $max - $min ) < 30 ) {
			return true;
		}

		// Clip extremes — colors under #191919 or over #e6e6e6 luminosity are
		// almost always container chrome, not brand accents.
		$lum = ( $max + $min ) / 2;
		if ( $lum < 25 || $lum > 230 ) {
			return true;
		}

		return false;
	}

	/**
	 * Normalize a CSS color value to a 6-digit hex without leading #.
	 * Returns null if we can't recognize it (e.g. rgb(), hsl(), keywords).
	 *
	 * Accepts 3, 6, or 8-digit hex forms. 8-digit (RGBA) drops the alpha
	 * channel — we only need the visible color for fragment theming.
	 */
	private function normalize_color( string $value ): ?string {
		$value = trim( $value );
		if ( preg_match( '/^#?([0-9a-f]{6})(?:[0-9a-f]{2})?$/i', $value, $m ) ) {
			return strtolower( $m[1] );
		}
		if ( preg_match( '/^#?([0-9a-f]{3})$/i', $value, $m ) ) {
			$short = strtolower( $m[1] );
			return $short[0] . $short[0] . $short[1] . $short[1] . $short[2] . $short[2];
		}
		return null;
	}

	/**
	 * Render the extracted content as a compact markdown-ish string that
	 * Claude can consume as context. Keeps token cost predictable.
	 */
	public function render_for_prompt( array $extracted, int $max_chars = 12000 ): string {
		$lines = array();
		if ( ! empty( $extracted['title'] ) ) {
			$lines[] = '# ' . $extracted['title'];
		}
		if ( ! empty( $extracted['description'] ) ) {
			$lines[] = '> ' . $extracted['description'];
			$lines[] = '';
		}

		// Brand block — only emit fields we actually found. Claude uses these
		// as soft hints (e.g. site_name in copy, logo_url in a logos slot).
		$brand = (array) ( $extracted['brand'] ?? array() );
		$brand_lines = array();
		if ( ! empty( $brand['site_name'] ) ) {
			$brand_lines[] = '- site_name: ' . $brand['site_name'];
		}
		if ( ! empty( $brand['theme_color'] ) ) {
			$src = ! empty( $brand['theme_color_source'] ) ? ' (from ' . $brand['theme_color_source'] . ')' : '';
			$brand_lines[] = '- theme_color: #' . $brand['theme_color'] . $src;
		}
		if ( ! empty( $brand['logo_url'] ) ) {
			$brand_lines[] = '- logo_url: ' . $brand['logo_url'];
		}
		if ( ! empty( $brand['og_image'] ) ) {
			$brand_lines[] = '- og_image: ' . $brand['og_image'];
		}
		if ( ! empty( $brand['fonts'] ) ) {
			$brand_lines[] = '- fonts: ' . implode( ', ', $brand['fonts'] );
		}
		if ( $brand_lines ) {
			$lines[] = '## Brand signals';
			foreach ( $brand_lines as $bl ) {
				$lines[] = $bl;
			}
			$lines[] = '';
		}
		foreach ( (array) ( $extracted['sections'] ?? array() ) as $section ) {
			$level = max( 1, min( 3, (int) ( $section['level'] ?? 2 ) ) );
			$lines[] = str_repeat( '#', $level + 1 ) . ' ' . $section['heading'];
			foreach ( (array) ( $section['paragraphs'] ?? array() ) as $p ) {
				$lines[] = $p;
			}
			foreach ( (array) ( $section['ctas'] ?? array() ) as $cta ) {
				$lines[] = '- CTA: "' . $cta['text'] . '" -> ' . $cta['href'];
			}
			if ( ! empty( $section['images'] ) ) {
				// Emit as markdown image syntax so Claude can lift the URL into
				// `image_url` slots on image-aware fragments. Cap at 5 per section.
				$seen = array();
				foreach ( array_slice( $section['images'], 0, 5 ) as $img ) {
					$url = (string) ( $img['src'] ?? '' );
					if ( '' === $url || isset( $seen[ $url ] ) ) {
						continue;
					}
					$seen[ $url ] = true;
					$alt = trim( (string) ( $img['alt'] ?? '' ) );
					$lines[] = '![' . $alt . '](' . $url . ')';
				}
			}
			$lines[] = '';
		}

		$out = implode( "\n", $lines );
		if ( strlen( $out ) > $max_chars ) {
			$out = substr( $out, 0, $max_chars ) . "\n\n…(truncated)";
		}
		return $out;
	}

	// ---------- internal helpers ----------

	private function new_section( string $heading, int $level ): array {
		return array(
			'heading'    => $heading,
			'level'      => $level,
			'paragraphs' => array(),
			'images'     => array(),
			'ctas'       => array(),
		);
	}

	private function section_has_content( array $section ): bool {
		return ! empty( $section['paragraphs'] )
			|| ! empty( $section['images'] )
			|| ! empty( $section['ctas'] );
	}

	private function walk( \DOMNode $node, array &$sections, array &$current, string $base_url ): void {
		if ( ! $node->hasChildNodes() ) {
			return;
		}
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			$tag = strtolower( $child->nodeName );

			if ( in_array( $tag, array( 'h1', 'h2', 'h3' ), true ) ) {
				if ( $this->section_has_content( $current ) ) {
					$sections[] = $current;
				}
				$level = (int) substr( $tag, 1 );
				$current = $this->new_section( $this->clean_text( $child->textContent ), $level );
				continue;
			}

			if ( 'p' === $tag || 'li' === $tag ) {
				$text = $this->clean_text( $child->textContent );
				if ( $text && strlen( $text ) > 15 && count( $current['paragraphs'] ) < 8 ) {
					$current['paragraphs'][] = $text;
				}
				continue;
			}

			if ( 'img' === $tag ) {
				$src = (string) $child->getAttribute( 'src' );
				$alt = (string) $child->getAttribute( 'alt' );
				if ( $src ) {
					$current['images'][] = array(
						'src' => $this->absolutize( $src, $base_url ),
						'alt' => $this->clean_text( $alt ),
					);
				}
				continue;
			}

			if ( 'a' === $tag ) {
				$href = (string) $child->getAttribute( 'href' );
				$text = $this->clean_text( $child->textContent );
				// Only keep anchors that look like CTAs: short text, external-ish href.
				if ( $text && strlen( $text ) <= 40 && $href && '#' !== substr( $href, 0, 1 ) ) {
					$current['ctas'][] = array(
						'text' => $text,
						'href' => $this->absolutize( $href, $base_url ),
					);
				}
				// Still recurse in case there's a heading or paragraph inside the <a>.
			}

			$this->walk( $child, $sections, $current, $base_url );
		}
	}

	private function clean_text( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
		return trim( $text );
	}

	private function absolutize( string $url, string $base ): string {
		if ( '' === $url ) {
			return $url;
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		$parts = wp_parse_url( $base );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return $url;
		}
		$scheme = $parts['scheme'] ?? 'https';
		$host   = $parts['host'];
		if ( 0 === strpos( $url, '//' ) ) {
			return $scheme . ':' . $url;
		}
		if ( 0 === strpos( $url, '/' ) ) {
			return $scheme . '://' . $host . $url;
		}
		$path = isset( $parts['path'] ) ? dirname( $parts['path'] ) : '';
		return $scheme . '://' . $host . rtrim( $path, '/' ) . '/' . $url;
	}
}
