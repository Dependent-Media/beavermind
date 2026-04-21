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
	 * @param string $brief       Free-text description of the desired page.
	 * @param array  $page_meta   Overrides for the page (title, post_status, etc.).
	 * @param array  $reference   Optional extracted reference content from SiteCloner::fetch().
	 *                            When present, the planner is told to preserve meaning
	 *                            (content parity) while using our fragment palette.
	 *
	 * @return array|\WP_Error  Plan in LayoutWriter::apply_plan() shape, or WP_Error.
	 */
	public function plan( string $brief, array $page_meta = array(), array $reference = array() ) {
		if ( ! $this->claude->is_configured() ) {
			return new \WP_Error( 'beavermind_no_key', 'Claude API key is not configured. Set it in BeaverMind settings.' );
		}

		$catalog = $this->fragments->catalog();
		if ( empty( $catalog ) ) {
			return new \WP_Error( 'beavermind_no_fragments', 'No fragments are registered.' );
		}

		$user_content = 'Page brief:' . "\n\n" . $brief;
		if ( $reference ) {
			$cloner = new SiteCloner();
			$ref_text = $cloner->render_for_prompt( $reference );
			$user_content .= "\n\n---\n\nReference site (preserve the meaning and information — don't copy the wording, rewrite cleaner):\n\n" . $ref_text;
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

		// Convert from the model's array-of-pairs slot shape into the dict
		// shape LayoutWriter expects.
		$page = array_merge( array(
			'title'       => isset( $decoded['title'] ) ? (string) $decoded['title'] : 'BeaverMind Generated Page',
			'post_type'   => 'page',
			'post_status' => 'draft',
		), $page_meta );

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

		return array(
			'page'      => $page,
			'fragments' => $fragments_out,
			'usage'     => isset( $response->usage ) ? array(
				'input_tokens'                  => $response->usage->inputTokens ?? null,
				'output_tokens'                 => $response->usage->outputTokens ?? null,
				'cache_creation_input_tokens'   => $response->usage->cacheCreationInputTokens ?? null,
				'cache_read_input_tokens'       => $response->usage->cacheReadInputTokens ?? null,
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
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'title', 'fragments' ),
			'properties'           => array(
				'title' => array(
					'type'        => 'string',
					'description' => 'HTML <title> for the generated page (under 60 chars).',
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
