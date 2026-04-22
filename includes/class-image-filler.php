<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post-pass that replaces empty / placeholder `image_url` slots in a plan
 * with real photos sourced from Pexels.
 *
 * Detects image slots by looking at each fragment's `slots_meta` and filtering
 * for slots that write into a BB `photo` module's `photo_url` field. The brief
 * is passed to Claude Haiku to turn "hero on a SaaS scheduler page" into
 * concrete search terms ("team calendar workspace"), in one batched call for
 * the whole plan so we don't fan out N Haiku requests per variant.
 *
 * Mutates the plan in place:
 *   - Fills `image_url` and `image_alt` (when the fragment declares an alt slot).
 *   - Appends an `image_attributions` array to the plan; LayoutWriter lifts this
 *     onto `_beavermind_image_attributions` post meta, which the footer hook
 *     reads to render photographer credits.
 */
class ImageFiller {

	private PexelsClient $pexels;
	private Planner $planner;
	private FragmentLibrary $fragments;

	public function __construct( PexelsClient $pexels, Planner $planner, FragmentLibrary $fragments ) {
		$this->pexels    = $pexels;
		$this->planner   = $planner;
		$this->fragments = $fragments;
	}

	/**
	 * Fill image slots on the plan. No-ops when Pexels is not configured,
	 * the plan has no fragments, or no slots need filling. Safe to call on
	 * every generation.
	 *
	 * @param array  $plan       Plan in LayoutWriter shape (mutated).
	 * @param string $brief      The user's brief — used to generate search terms.
	 *                           Pass empty string when there's no natural brief
	 *                           (e.g. Figma renders) and the page title will be used.
	 */
	public function fill( array &$plan, string $brief = '' ): void {
		if ( ! $this->pexels->is_configured() ) {
			return;
		}
		if ( empty( $plan['fragments'] ) || ! is_array( $plan['fragments'] ) ) {
			return;
		}

		$catalog  = $this->fragments->catalog();
		$targets  = $this->collect_image_slots( $plan, $catalog );
		if ( empty( $targets ) ) {
			return;
		}

		$page_title  = (string) ( $plan['page']['title'] ?? $plan['title'] ?? '' );
		$brief_hint  = '' !== trim( $brief ) ? $brief : $page_title;
		$search_map  = $this->planner->generate_search_terms_for_slots( $brief_hint, $targets );

		$attributions = array();
		foreach ( $targets as $key => $target ) {
			$query = isset( $search_map[ $key ] ) && '' !== trim( (string) $search_map[ $key ] )
				? (string) $search_map[ $key ]
				: $this->fallback_query( $brief_hint, $target );

			$photo = $this->pexels->search( $query );
			if ( is_wp_error( $photo ) || null === $photo ) {
				continue;
			}

			$fragment_idx = $target['fragment_idx'];
			$plan['fragments'][ $fragment_idx ]['slots'][ $target['url_slot'] ] = $photo['url'];
			if ( $target['alt_slot'] && '' !== $photo['alt'] ) {
				$plan['fragments'][ $fragment_idx ]['slots'][ $target['alt_slot'] ] = $photo['alt'];
			}

			$attributions[] = array(
				'url'              => $photo['url'],
				'photographer'     => $photo['photographer'],
				'photographer_url' => $photo['photographer_url'],
				'pexels_url'       => $photo['pexels_url'],
				'provider'         => 'pexels',
			);
		}

		if ( ! empty( $attributions ) ) {
			$plan['image_attributions'] = array_merge(
				(array) ( $plan['image_attributions'] ?? array() ),
				$attributions
			);
		}
	}

	/**
	 * Walk the plan's fragments and collect image slots that look like they
	 * need filling.
	 *
	 * Return keys are synthetic "<fragment_idx>:<slot_name>" strings; Planner
	 * uses them to tie Haiku's output back to the right slot.
	 *
	 * @param array<string, array{meta:array, source:string}> $catalog
	 * @return array<string, array{fragment_idx:int, fragment_id:string, url_slot:string, alt_slot:?string, current:string, category:string}>
	 */
	private function collect_image_slots( array $plan, array $catalog ): array {
		$out = array();
		foreach ( (array) $plan['fragments'] as $idx => $entry ) {
			$fragment_id = (string) ( $entry['id'] ?? '' );
			if ( '' === $fragment_id || ! isset( $catalog[ $fragment_id ] ) ) {
				continue;
			}
			$meta       = $catalog[ $fragment_id ]['meta'] ?? array();
			$slots_meta = is_array( $meta['slots'] ?? null ) ? $meta['slots'] : array();
			$category   = (string) ( $meta['category'] ?? 'content' );

			foreach ( $slots_meta as $slot_name => $slot_def ) {
				$field = (string) ( $slot_def['field'] ?? '' );
				// BB's photo module stores its URL in `photo_url`. That's the
				// one reliable signal across both inline fragments and any
				// designer-authored .dat fragments.
				if ( 'photo_url' !== $field ) {
					continue;
				}
				$current = (string) ( $entry['slots'][ $slot_name ] ?? '' );
				if ( '' !== $current && ! PexelsClient::is_placeholder_url( $current ) ) {
					continue;
				}

				// Find a sibling alt slot targeting the same node (field
				// `url_title` in our fragments). Allows the filler to caption
				// the photo too.
				$alt_slot = null;
				$node_id  = (string) ( $slot_def['node'] ?? '' );
				if ( '' !== $node_id ) {
					foreach ( $slots_meta as $other_name => $other_def ) {
						if ( $other_name === $slot_name ) {
							continue;
						}
						if ( ( $other_def['node'] ?? '' ) === $node_id && 'url_title' === ( $other_def['field'] ?? '' ) ) {
							$alt_slot = (string) $other_name;
							break;
						}
					}
				}

				$key = $idx . ':' . $slot_name;
				$out[ $key ] = array(
					'fragment_idx' => (int) $idx,
					'fragment_id'  => $fragment_id,
					'url_slot'     => (string) $slot_name,
					'alt_slot'     => $alt_slot,
					'current'      => $current,
					'category'     => $category,
				);
			}
		}
		return $out;
	}

	/**
	 * When Haiku is unavailable or returns nothing, build a plausible query
	 * from the fragment's category, slot name, and brief snippet. This is
	 * crude but beats showing a placeholder.
	 *
	 * @param array{fragment_id:string, url_slot:string, category:string} $target
	 */
	private function fallback_query( string $brief_hint, array $target ): string {
		$parts = array();
		$slot  = str_replace( array( '_url', '-' ), array( '', ' ' ), $target['url_slot'] );
		$parts[] = $slot;
		$parts[] = $target['category'];
		$snippet = trim( wp_strip_all_tags( (string) $brief_hint ) );
		if ( '' !== $snippet ) {
			$parts[] = mb_substr( $snippet, 0, 80 );
		}
		$joined = trim( implode( ' ', array_filter( $parts ) ) );
		return '' !== $joined ? $joined : 'business workspace';
	}
}
