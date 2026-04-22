<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks Claude to compose a page from the fragment catalog.
 *
 * Architecture:
 *   - Frozen system prompt (cacheable) explains the planner's role and lists
 *     every available fragment with its slots.
 *   - User message (varies per request) is the brief: "a landing page for X".
 *   - Claude returns a structured JSON plan via output_config, validated
 *     against a strict schema.
 *   - The plan is shaped exactly like LayoutWriter::apply_plan() expects, so
 *     the writer is the next call.
 *
 * Prompt caching:
 *   The catalog is rendered into the SECOND system text block with a
 *   cache_control breakpoint. Once the catalog crosses ~4K tokens (Opus 4.7's
 *   minimum cacheable prefix), repeated requests will read from cache. With
 *   today's small library, the marker is a no-op — but it's wired correctly
 *   for when the library grows.
 */
class Planner {

	private ClaudeClient $claude;
	private FragmentLibrary $fragments;
	private string $model;

	public function __construct( ClaudeClient $claude, FragmentLibrary $fragments, string $model = 'claude-opus-4-7' ) {
		$this->claude    = $claude;
		$this->fragments = $fragments;
		$this->model     = $model;
	}

	/**
	 * Plan a page from a free-text brief.
	 *
	 * @param string $brief            Free-text description of the desired page.
	 * @param array  $page_meta        Overrides for the page (title, post_status, etc.).
	 * @param array  $reference        Optional extracted reference content from SiteCloner::fetch().
	 *                                 When present, the planner is told to preserve meaning
	 *                                 (content parity) while using our fragment palette.
	 * @param array  $reference_image  Optional ['bytes' => string, 'media_type' => string] —
	 *                                 if provided, sent inline to Claude as a vision content
	 *                                 block alongside the text reference. Use for hybrid
	 *                                 URL+vision: extracted HTML for structure, the OG image
	 *                                 (or any rendered screenshot) for visual feel.
	 *
	 * @return array|\WP_Error  Plan in LayoutWriter::apply_plan() shape, or WP_Error.
	 */
	public function plan( string $brief, array $page_meta = array(), array $reference = array(), array $reference_image = array() ) {
		if ( ! $this->claude->is_configured() ) {
			return new \WP_Error( 'beavermind_no_key', 'Claude API key is not configured. Set it in BeaverMind settings.' );
		}

		$catalog = $this->fragments->catalog();
		if ( empty( $catalog ) ) {
			return new \WP_Error( 'beavermind_no_fragments', 'No fragments are registered.' );
		}

		$user_text = 'Page brief:' . "\n\n" . $brief;
		if ( $reference ) {
			$cloner = new SiteCloner();
			$ref_text = $cloner->render_for_prompt( $reference );
			$user_text .= "\n\n---\n\nReference site (preserve the meaning and information — don't copy the wording, rewrite cleaner):\n\n" . $ref_text;
		}

		// Build the user message content. Plain string when no image is
		// attached (slightly cheaper / simpler); content-block array when one
		// is. The image goes BEFORE the text so Claude reads the visual
		// context first, mirroring the recommended pattern in the docs.
		$has_image = ! empty( $reference_image['bytes'] ) && ! empty( $reference_image['media_type'] );
		if ( $has_image ) {
			$user_text .= "\n\n---\n\nThe attached image is the reference's hero / OG image. Use it as a soft visual anchor — note dominant colors, mood, any product imagery — when picking fragments and writing copy. Don't try to perfectly reproduce it.";
			$user_content = array(
				array(
					'type'   => 'image',
					'source' => array(
						'type'      => 'base64',
						'media_type' => (string) $reference_image['media_type'],
						'data'      => base64_encode( (string) $reference_image['bytes'] ),
					),
				),
				array( 'type' => 'text', 'text' => $user_text ),
			);
		} else {
			$user_content = $user_text;
		}

		try {
			$client = $this->claude->client();
			$response = $client->messages->create(
				model: $this->model,
				maxTokens: 16000,
				thinking: array( 'type' => 'adaptive' ),
				system: array(
					array(
						'type' => 'text',
						'text' => $this->frozen_system_prompt(),
					),
					array(
						'type'         => 'text',
						'text'         => $this->render_catalog( $catalog ),
						'cacheControl' => array( 'type' => 'ephemeral' ),
					),
				),
				messages: array(
					array(
						'role'    => 'user',
						'content' => $user_content,
					),
				),
				outputConfig: array(
					'format' => array(
						'type'   => 'json_schema',
						'schema' => $this->plan_schema( array_keys( $catalog ) ),
					),
				),
			);
		} catch ( \Anthropic\AuthenticationError $e ) {
			return new \WP_Error( 'beavermind_auth', 'Claude API rejected the API key. ' . $e->getMessage() );
		} catch ( \Anthropic\InvalidRequestError $e ) {
			return new \WP_Error( 'beavermind_bad_request', 'Bad request to Claude API: ' . $e->getMessage() );
		} catch ( \Anthropic\OverloadedError $e ) {
			return new \WP_Error( 'beavermind_overloaded', 'Claude API is temporarily overloaded. Retry in a moment.' );
		} catch ( \Anthropic\RateLimitError $e ) {
			return new \WP_Error( 'beavermind_rate_limit', 'Claude API rate limit hit. Retry in a moment.' );
		} catch ( \Anthropic\APIError $e ) {
			return new \WP_Error( 'beavermind_api_error', 'Claude API error: ' . $e->getMessage() );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'beavermind_unknown', 'Unexpected error: ' . $e->getMessage() );
		}

		return $this->decode_response( $response, $page_meta );
	}

	/**
	 * Plan a page from an uploaded image (screenshot, mockup, Figma export).
	 * Uses Claude's vision capability — the image is sent inline as base64.
	 *
	 * @param string $image_data  Raw bytes of the image (NOT base64-encoded).
	 * @param string $media_type  e.g. "image/png", "image/jpeg", "image/webp", "image/gif".
	 * @param string $brief       Free-text instruction (style, audience, constraints).
	 * @param array  $page_meta   Overrides for the page (title, post_status).
	 *
	 * @return array|\WP_Error
	 */
	public function plan_from_image( string $image_data, string $media_type, string $brief, array $page_meta = array() ) {
		if ( ! $this->claude->is_configured() ) {
			return new \WP_Error( 'beavermind_no_key', 'Claude API key is not configured.' );
		}
		$catalog = $this->fragments->catalog();
		if ( empty( $catalog ) ) {
			return new \WP_Error( 'beavermind_no_fragments', 'No fragments are registered.' );
		}

		// Anthropic accepts image_data inline as base64. 5 MB is the per-image
		// limit (encoded), so cap on the raw bytes before encoding.
		if ( strlen( $image_data ) > 3_500_000 ) {
			return new \WP_Error( 'beavermind_image_too_big', 'Image is too large. Max ~3.5 MB. Resize and retry.' );
		}
		$allowed = array( 'image/png', 'image/jpeg', 'image/webp', 'image/gif' );
		if ( ! in_array( $media_type, $allowed, true ) ) {
			return new \WP_Error( 'beavermind_bad_image_type', 'Unsupported image type. Use PNG, JPEG, WebP, or GIF.' );
		}

		$user_message_text = "Design brief:\n\n" . $brief
			. "\n\nThe attached image is the visual reference. Examine its sections, hierarchy, copy length, and any visible CTAs. Pick fragments that mirror the structure (hero, features, social proof, CTA, etc.) and write copy that fits both the brief and what's visible in the image. If the image shows specific text — headlines, button labels, stat numbers — preserve the meaning. Don't try to perfectly clone the visual; it's a rough guide.";

		try {
			$response = $this->claude->client()->messages->create(
				model: $this->model,
				maxTokens: 16000,
				thinking: array( 'type' => 'adaptive' ),
				system: array(
					array( 'type' => 'text', 'text' => $this->frozen_system_prompt() ),
					array(
						'type'         => 'text',
						'text'         => $this->render_catalog( $catalog ),
						'cacheControl' => array( 'type' => 'ephemeral' ),
					),
				),
				messages: array(
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type'   => 'image',
								'source' => array(
									'type'      => 'base64',
									'media_type' => $media_type,
									'data'      => base64_encode( $image_data ),
								),
							),
							array(
								'type' => 'text',
								'text' => $user_message_text,
							),
						),
					),
				),
				outputConfig: array(
					'format' => array(
						'type'   => 'json_schema',
						'schema' => $this->plan_schema( array_keys( $catalog ) ),
					),
				),
			);
		} catch ( \Anthropic\AuthenticationError $e ) {
			return new \WP_Error( 'beavermind_auth', 'Claude API rejected the API key. ' . $e->getMessage() );
		} catch ( \Anthropic\InvalidRequestError $e ) {
			return new \WP_Error( 'beavermind_bad_request', 'Bad request to Claude API: ' . $e->getMessage() );
		} catch ( \Anthropic\OverloadedError $e ) {
			return new \WP_Error( 'beavermind_overloaded', 'Claude API is temporarily overloaded.' );
		} catch ( \Anthropic\RateLimitError $e ) {
			return new \WP_Error( 'beavermind_rate_limit', 'Claude API rate limit hit.' );
		} catch ( \Anthropic\APIError $e ) {
			return new \WP_Error( 'beavermind_api_error', 'Claude API error: ' . $e->getMessage() );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'beavermind_unknown', 'Unexpected error: ' . $e->getMessage() );
		}

		return $this->decode_response( $response, $page_meta );
	}

	/**
	 * Rewrite a user's prompt to be more specific and structured. Used by
	 * the "Enhance Prompt" button in the admin UI. Always runs on Haiku
	 * (regardless of the selected planning model) so the assist is cheap
	 * and feels free.
	 *
	 * @return array{enhanced: string}|\WP_Error
	 */
	public function enhance_prompt( string $brief ) {
		if ( ! $this->claude->is_configured() ) {
			return new \WP_Error( 'beavermind_no_key', 'Claude API key is not configured.' );
		}
		$brief = trim( $brief );
		if ( '' === $brief ) {
			return new \WP_Error( 'beavermind_empty_brief', 'Prompt is empty — nothing to enhance.' );
		}

		$system = <<<SYS
You are a writing coach for AI page-generation prompts. The user is going to feed their brief to a planner that picks layout fragments and writes copy.

Your job: rewrite their brief into a tighter, more concrete one — a single, finished, ready-to-use brief. Keep their intent and voice; tighten phrasing; fill in plausible specifics where they're missing (audience, tone, primary CTA, key claims). When the input is vague, INVENT reasonable defaults rather than asking for clarification. If the brief is already good, make small improvements only.

Hard rules:
- Reply with ONLY the rewritten brief.
- Plain prose. No markdown headings, no bullet lists, no fields, no fill-in-the-blank brackets.
- Never ask the user a question.
- Never say "[Specify X]" or "[fill in]" — invent the detail.
- Length: 1 to 4 sentences, max ~80 words.
- No preamble, no quotes, no explanation.
SYS;

		try {
			$response = $this->claude->client()->messages->create(
				model: 'claude-haiku-4-5-20251001',
				maxTokens: 1024,
				system: $system,
				messages: array(
					array( 'role' => 'user', 'content' => $brief ),
				),
			);
		} catch ( \Anthropic\OverloadedError $e ) {
			return new \WP_Error( 'beavermind_overloaded', 'Claude API is overloaded — try again in a moment.' );
		} catch ( \Anthropic\APIError $e ) {
			return new \WP_Error( 'beavermind_api_error', 'Claude API error: ' . $e->getMessage() );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'beavermind_unknown', 'Unexpected error: ' . $e->getMessage() );
		}

		$enhanced = '';
		foreach ( $response->content as $block ) {
			if ( isset( $block->type ) && $block->type === 'text' ) {
				$enhanced .= $block->text;
			}
		}
		$enhanced = trim( $enhanced );
		if ( '' === $enhanced ) {
			return new \WP_Error( 'beavermind_empty_response', 'Claude returned no text.' );
		}
		return array( 'enhanced' => $enhanced );
	}

	/**
	 * Refine an existing BeaverMind-generated page: load its stored plan, ask
	 * Claude to modify it per the user's instruction, and return the new plan.
	 *
	 * @param int    $post_id      Post that was previously generated by BeaverMind.
	 * @param string $instruction  Free-text refinement ("make the hero bolder").
	 *
	 * @return array|\WP_Error
	 */
	public function refine( int $post_id, string $instruction ) {
		if ( ! $this->claude->is_configured() ) {
			return new \WP_Error( 'beavermind_no_key', 'Claude API key is not configured.' );
		}

		$stored = get_post_meta( $post_id, '_beavermind_plan', true );
		if ( ! $stored ) {
			return new \WP_Error( 'beavermind_not_refinable', 'That post was not generated by BeaverMind (no plan stored).' );
		}
		$prior = json_decode( (string) $stored, true );
		if ( ! is_array( $prior ) ) {
			return new \WP_Error( 'beavermind_bad_stored_plan', 'Stored plan is unparseable JSON.' );
		}

		$catalog = $this->fragments->catalog();
		if ( empty( $catalog ) ) {
			return new \WP_Error( 'beavermind_no_fragments', 'No fragments are registered.' );
		}

		$prior_summary = $this->render_prior_plan( $prior );

		$user_content  = "You previously generated this page plan:\n\n" . $prior_summary;
		$user_content .= "\n\nThe user now asks you to modify it:\n\n" . $instruction;
		$user_content .= "\n\nReturn a NEW complete plan that incorporates the change. You can add fragments, remove fragments, edit slot values, and reorder. The new plan must still conform to the schema and only use fragments from the catalog.";

		try {
			$client = $this->claude->client();
			$response = $client->messages->create(
				model: $this->model,
				maxTokens: 16000,
				thinking: array( 'type' => 'adaptive' ),
				system: array(
					array( 'type' => 'text', 'text' => $this->frozen_system_prompt() ),
					array(
						'type'         => 'text',
						'text'         => $this->render_catalog( $catalog ),
						'cacheControl' => array( 'type' => 'ephemeral' ),
					),
				),
				messages: array( array( 'role' => 'user', 'content' => $user_content ) ),
				outputConfig: array(
					'format' => array(
						'type'   => 'json_schema',
						'schema' => $this->plan_schema( array_keys( $catalog ) ),
					),
				),
			);
		} catch ( \Anthropic\AuthenticationError $e ) {
			return new \WP_Error( 'beavermind_auth', 'Claude API rejected the API key. ' . $e->getMessage() );
		} catch ( \Anthropic\InvalidRequestError $e ) {
			return new \WP_Error( 'beavermind_bad_request', 'Bad request to Claude API: ' . $e->getMessage() );
		} catch ( \Anthropic\OverloadedError $e ) {
			return new \WP_Error( 'beavermind_overloaded', 'Claude API is temporarily overloaded.' );
		} catch ( \Anthropic\RateLimitError $e ) {
			return new \WP_Error( 'beavermind_rate_limit', 'Claude API rate limit hit.' );
		} catch ( \Anthropic\APIError $e ) {
			return new \WP_Error( 'beavermind_api_error', 'Claude API error: ' . $e->getMessage() );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'beavermind_unknown', 'Unexpected error: ' . $e->getMessage() );
		}

		return $this->decode_response( $response, array( 'post_id' => $post_id ) );
	}

	/**
	 * Compact string form of a prior plan — fragment_id and slot values only.
	 * This is what Claude reads to understand what's on the page now.
	 */
	private function render_prior_plan( array $plan ): string {
		$lines = array();
		$lines[] = 'Title: ' . ( $plan['page']['title'] ?? '(untitled)' );
		$lines[] = 'Fragments:';
		foreach ( (array) ( $plan['fragments'] ?? array() ) as $i => $f ) {
			$n = $i + 1;
			$fid = (string) ( $f['id'] ?? '?' );
			$lines[] = "  $n. $fid";
			foreach ( (array) ( $f['slots'] ?? array() ) as $slot_name => $slot_value ) {
				$trim = wp_strip_all_tags( (string) $slot_value );
				if ( strlen( $trim ) > 120 ) {
					$trim = substr( $trim, 0, 117 ) . '...';
				}
				$lines[] = "     - $slot_name: $trim";
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Decode a Claude response (text block containing JSON) into the
	 * LayoutWriter plan shape. Extracted so plan() and refine() share it.
	 */
	private function decode_response( $response, array $page_overrides = array() ) {
		$json_text = '';
		foreach ( $response->content as $block ) {
			if ( isset( $block->type ) && $block->type === 'text' ) {
				$json_text .= $block->text;
			}
		}
		if ( '' === $json_text ) {
			return new \WP_Error( 'beavermind_empty_response', 'Claude returned no text content.' );
		}
		$decoded = json_decode( $json_text, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'beavermind_bad_json', 'Claude returned non-JSON content: ' . substr( $json_text, 0, 400 ) );
		}

		$page = array_merge( array(
			'title'       => isset( $decoded['title'] ) ? (string) $decoded['title'] : 'BeaverMind Generated Page',
			'post_type'   => 'page',
			'post_status' => 'draft',
		), $page_overrides );

		$fragments_out = array();
		foreach ( (array) ( $decoded['fragments'] ?? array() ) as $entry ) {
			$slots = array();
			foreach ( (array) ( $entry['slots'] ?? array() ) as $pair ) {
				if ( isset( $pair['name'], $pair['value'] ) ) {
					$slots[ (string) $pair['name'] ] = (string) $pair['value'];
				}
			}
			$fragments_out[] = array(
				'id'    => (string) ( $entry['fragment_id'] ?? '' ),
				'slots' => $slots,
			);
		}

		// Pass through Claude's optional theme so LayoutWriter can apply it.
		// Theme schema is a flat dict of { colors: { primary, bg_dark, ... } }.
		$theme = isset( $decoded['theme'] ) && is_array( $decoded['theme'] ) ? $decoded['theme'] : null;

		return array(
			'page'      => $page,
			'fragments' => $fragments_out,
			'theme'     => $theme,
			'usage'     => isset( $response->usage ) ? array(
				'input_tokens'                => $response->usage->inputTokens ?? null,
				'output_tokens'               => $response->usage->outputTokens ?? null,
				'cache_creation_input_tokens' => $response->usage->cacheCreationInputTokens ?? null,
				'cache_read_input_tokens'     => $response->usage->cacheReadInputTokens ?? null,
			) : null,
		);
	}

	private function frozen_system_prompt(): string {
		return <<<PROMPT
You are BeaverMind's layout planner. Your job is to compose a web page by
selecting fragments from a curated library and filling each fragment's content
slots with copy that fits the user's brief.

Rules:
1. Pick 2 to 6 fragments. Use a hero-style fragment first if the brief implies
   a landing page. End with a CTA banner if the brief implies driving action.
2. Use each fragment AT MOST ONCE per page unless the brief clearly calls for
   repetition (e.g. "two pricing tiers").
3. Only use slot names that are listed under the fragment you chose. Unknown
   slot names are silently ignored, so don't waste them.
4. Keep copy concrete, scannable, and on-brief. Avoid filler like "Lorem ipsum"
   or generic claims like "the best in the industry".
5. Headlines: 4-9 words. Sub-heads: one sentence. Body copy: 1-3 sentences.
   Button labels: 1-3 words, action-first ("Get Started", not "Click Here").
6. Page title (top of your output) is the HTML <title>, not a heading. Keep it
   under 60 chars.
7. Images: if the reference includes an image in a section (look for
   `![alt](url)` in the brief), prefer a fragment variant that has an
   `image_url` slot and pass the captured URL through verbatim. Put the alt
   text in `image_alt` if the fragment has one. Do NOT invent image URLs;
   only use URLs that appear in the reference.
8. Brand signals: when the brief contains a "Brand signals" block, use them
   as soft hints. Refer to the product by `site_name` in headings/CTAs.
   If `logo_url` is present and you're picking a logos fragment, you may use
   it as one of the logo slots.
9. Theme colors: optionally include a `theme.colors` object in your output
   to override the fragments' default colors. Keys (all optional):
   - `primary`        — main brand color, used for CTA button backgrounds
   - `bg_dark`        — dark section backgrounds (CTA banner)
   - `bg_light`       — subtle/alternate-section backgrounds (testimonial,
                        logos rows)
   - `text_on_dark`   — heading/text color on dark sections
   - `text_on_light`  — text color on light sections (rarely needed; defaults
                        are fine)
   Values must be 6-digit hex without leading `#` (e.g. "2563eb"). Derive
   `primary` from the brand's `theme_color` when present. Pick `bg_dark`
   either from a darker shade of `primary` or as a neutral dark like
   "111827"/"1f2937". `text_on_dark` should be near-white ("ffffff" or
   "f9fafb"). Skip the whole `theme` block if you have no signal — defaults
   are fine.

Output a JSON plan that conforms to the provided schema. Do not include any
prose outside the JSON.
PROMPT;
	}

	private function render_catalog( array $catalog ): string {
		$lines = array( '## Fragment library', '' );
		foreach ( $catalog as $id => $entry ) {
			$meta = $entry['meta'];
			$slot_lines = array();
			foreach ( (array) ( $meta['slots'] ?? array() ) as $slot_name => $_def ) {
				$slot_lines[] = "  - $slot_name";
			}
			$lines[] = "### $id";
			$lines[] = '**' . ( $meta['name'] ?? $id ) . "** _(category: " . ( $meta['category'] ?? '?' ) . ")_";
			$lines[] = '';
			$lines[] = (string) ( $meta['description'] ?? '' );
			$lines[] = '';
			$lines[] = 'Slots:';
			$lines[] = implode( "\n", $slot_lines );
			$lines[] = '';
		}
		return implode( "\n", $lines );
	}

	/**
	 * Strict JSON schema for the plan output. Slots are an array of
	 * {name, value} pairs because JSON Schema can't express
	 * "object whose keys depend on a sibling enum field".
	 */
	private function plan_schema( array $fragment_ids ): array {
		// Hex color hint — pattern constraint is dropped because structured
		// outputs treat it as unsupported. LayoutWriter::normalize_color
		// strips the leading # and validates 3 / 6-digit hex.
		$hex = array(
			'type'        => 'string',
			'description' => '6-digit hex without leading # (e.g. "2563eb"). Leading # is tolerated.',
		);
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'title', 'fragments' ),
			'properties'           => array(
				'title' => array(
					'type'        => 'string',
					'description' => 'HTML <title> for the generated page (under 60 chars).',
				),
				'theme' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'description'          => 'Optional color overrides. Omit when no brand signal is available — fragment defaults are sane.',
					'properties'           => array(
						'colors' => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => array(
								'primary'       => $hex,
								'bg_dark'       => $hex,
								'bg_light'      => $hex,
								'text_on_dark'  => $hex,
								'text_on_light' => $hex,
							),
						),
					),
				),
				'fragments' => array(
					'type'  => 'array',
					'description' => 'Sequence of fragments to render, top to bottom.',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'fragment_id', 'slots' ),
						'properties'           => array(
							'fragment_id' => array(
								'type'        => 'string',
								'enum'        => array_values( $fragment_ids ),
								'description' => 'ID of a fragment from the library.',
							),
							'slots' => array(
								'type'        => 'array',
								'description' => 'Filled content slots for this fragment. Each item is a name/value pair where name is one of the fragment\'s declared slots.',
								'items'       => array(
									'type'                 => 'object',
									'additionalProperties' => false,
									'required'             => array( 'name', 'value' ),
									'properties'           => array(
										'name'  => array( 'type' => 'string' ),
										'value' => array(
											'type'        => 'string',
											'description' => 'Copy to insert into the slot. May contain plain text or simple HTML.',
										),
									),
								),
							),
						),
					),
				),
			),
		);
	}
}
