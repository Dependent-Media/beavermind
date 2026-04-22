<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a small photographer-credit line at the bottom of any frontend page
 * that had its images sourced by the Pexels ImageFiller.
 *
 * Pexels' license doesn't legally require credit — they just request it. We
 * give it. Credits are aggregated (one line per unique photographer) so a
 * 5-image page doesn't get a 5-line footer.
 *
 * Hooked at `wp_footer` on singular views only. If no attributions are stored
 * for the current post, this is a no-op.
 */
class ImageAttributions {

	public static function register(): void {
		add_action( 'wp_footer', array( __CLASS__, 'render' ) );
	}

	public static function render(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}
		$entries = get_post_meta( $post_id, '_beavermind_image_attributions', true );
		if ( ! is_array( $entries ) || empty( $entries ) ) {
			return;
		}

		// Collapse duplicate photographers so one artist with three photos on
		// the page shows up once.
		$by_photographer = array();
		foreach ( $entries as $entry ) {
			$name = isset( $entry['photographer'] ) ? trim( (string) $entry['photographer'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$url = isset( $entry['photographer_url'] ) ? (string) $entry['photographer_url'] : '';
			if ( ! isset( $by_photographer[ $name ] ) ) {
				$by_photographer[ $name ] = $url;
			}
		}
		if ( empty( $by_photographer ) ) {
			return;
		}

		$links = array();
		foreach ( $by_photographer as $name => $url ) {
			if ( '' !== $url ) {
				$links[] = sprintf(
					'<a href="%s" rel="noopener noreferrer" target="_blank">%s</a>',
					esc_url( $url ),
					esc_html( $name )
				);
			} else {
				$links[] = esc_html( $name );
			}
		}

		$pexels_link = sprintf(
			'<a href="%s" rel="noopener noreferrer" target="_blank">%s</a>',
			esc_url( 'https://www.pexels.com' ),
			esc_html__( 'Pexels', 'beavermind' )
		);

		printf(
			'<div class="bm-image-credits" style="max-width:960px;margin:2rem auto;padding:0 1rem;font-size:12px;color:#6b7280;text-align:center;">%s · %s</div>',
			sprintf(
				/* translators: %s: comma-separated photographer names (may contain links) */
				esc_html__( 'Photos by %s', 'beavermind' ),
				implode( ', ', $links )
			),
			sprintf(
				/* translators: %s: link to pexels.com */
				esc_html__( 'via %s', 'beavermind' ),
				$pexels_link
			)
		);
	}
}
