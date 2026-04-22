<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper for running 1..N variants of a plan + apply pipeline.
 *
 * Each generator (PromptGenerator, CloneGenerator, PasteHTMLGenerator,
 * ImageInputGenerator, FigmaGenerator) has the same shape: build a plan,
 * apply it, redirect back with results. With variants > 1 the plan step
 * runs N times sequentially. Per-variant failures are isolated — a 529 on
 * variant 2 doesn't block variant 3.
 *
 * Caller passes a closure that returns the plan (or WP_Error). PlanRunner
 * doesn't know or care what produced it (URL clone, image vision, Figma,
 * paste HTML, free text — all fungible from here on).
 */
class PlanRunner {

	const MAX_VARIANTS = 5;

	/**
	 * @param int             $variants      Desired variant count (clamped to [1, MAX_VARIANTS]).
	 * @param callable():(array|\WP_Error) $plan_factory  Returns a fresh plan per call.
	 * @param LayoutWriter    $writer
	 * @param FragmentLibrary $fragments
	 * @param array           $apply_options Forwarded to LayoutWriter::apply_plan; used to
	 *                                       thread in the Pexels image filler + brief
	 *                                       context. Shape matches apply_plan()'s $options.
	 *
	 * @return array{results: array<int, array>, errors: array<int, string>}
	 *   results[i] = ['post_id'=>int, 'title'=>string, 'fragments'=>array,
	 *                 'theme'=>?array, 'usage'=>?array, 'images'=>?int]
	 *   errors[]    = "variant N: <message>"
	 */
	public static function run(
		int $variants,
		callable $plan_factory,
		LayoutWriter $writer,
		FragmentLibrary $fragments,
		array $apply_options = array()
	): array {
		$variants = max( 1, min( self::MAX_VARIANTS, $variants ) );
		$results = array();
		$errors  = array();

		for ( $i = 1; $i <= $variants; $i++ ) {
			$plan = $plan_factory();
			if ( is_wp_error( $plan ) ) {
				$errors[] = "variant $i: " . $plan->get_error_message();
				continue;
			}
			$post_id = $writer->apply_plan( $plan, $fragments, $apply_options );
			if ( is_wp_error( $post_id ) ) {
				$errors[] = "variant $i: " . $post_id->get_error_message();
				continue;
			}
			$results[] = array(
				'post_id'   => (int) $post_id,
				'title'     => $plan['page']['title'] ?? '',
				'fragments' => $plan['fragments'] ?? array(),
				'theme'     => $plan['theme'] ?? null,
				'usage'     => $plan['usage'] ?? null,
				// ImageFiller stamps attributions on the plan; surfacing the
				// count lets generators render "X images from Pexels" notices.
				'images'    => isset( $plan['image_attributions'] ) && is_array( $plan['image_attributions'] )
					? count( $plan['image_attributions'] )
					: 0,
			);
		}

		return array( 'results' => $results, 'errors' => $errors );
	}

	/**
	 * Render a results notice block (one or many post links).
	 * Generators call this from their render_page() instead of duplicating
	 * the markup.
	 *
	 * @param array<int, array> $results from run()['results']
	 */
	public static function render_results_notice( array $results ): void {
		if ( empty( $results ) ) {
			return;
		}
		// CSS-grid gallery for multi-variant runs (matches Elementor's "pick
		// one of N" pattern). Single-variant runs collapse to one full-width
		// card so the layout is consistent. Cards lead with the theme
		// swatches and fragment count so visual differences pop at a glance.
		$count = count( $results );
		?>
		<div class="notice notice-success" data-testid="bm-success" style="padding: 12px 16px;">
			<p style="margin: 0 0 12px 0;"><strong><?php
			printf(
				/* translators: %d: number of variants generated */
				esc_html( _n( 'Generated %d page — pick one to keep working in:', 'Generated %d variants — compare and pick one to keep working in:', $count, 'beavermind' ) ),
				$count
			);
			?></strong></p>
			<div style="display:grid; grid-template-columns: repeat(<?php echo (int) min( 3, max( 1, $count ) ); ?>, minmax(0, 1fr)); gap: 12px; max-width: 1080px;">
				<?php foreach ( $results as $i => $v ) : ?>
					<?php
					$edit_attr = ( 0 === $i ) ? ' data-testid="bm-edit-link"' : '';
					$bb_attr   = ( 0 === $i ) ? ' data-testid="bm-bb-link"'   : '';
					$fragments = array_column( (array) ( $v['fragments'] ?? array() ), 'id' );
					?>
					<div class="bm-variant-card" style="border:1px solid #c3c4c7; border-radius:6px; padding:12px 14px; background:#fff;">
						<?php if ( ! empty( $v['theme']['colors'] ) ) : ?>
							<div style="margin-bottom:8px;"><?php self::render_theme_swatches( (array) $v['theme']['colors'] ); ?></div>
						<?php endif; ?>
						<div style="font-weight:600; font-size:13px; line-height:1.35; margin-bottom:6px;">
							<?php echo esc_html( $v['title'] ?? '(untitled)' ); ?>
						</div>
						<div style="font-size:11px; color:#646970; margin-bottom:10px;">
							<?php
							printf(
								/* translators: 1: count, 2: comma-separated fragment IDs */
								esc_html__( '%1$d fragments: %2$s', 'beavermind' ),
								count( $fragments ),
								esc_html( implode( ', ', $fragments ) )
							);
							$images = (int) ( $v['images'] ?? 0 );
							if ( $images > 0 ) {
								echo '<br>';
								printf(
									/* translators: %d: number of images sourced from Pexels */
									esc_html( _n( '%d image from Pexels', '%d images from Pexels', $images, 'beavermind' ) ),
									$images
								);
							}
							?>
						</div>
						<div style="display:flex; gap:6px; align-items:center; font-size:12px;">
							<a class="button button-primary button-small" href="<?php echo esc_url( add_query_arg( 'fl_builder', '', get_permalink( (int) $v['post_id'] ) ) ); ?>" target="_blank"<?php echo $bb_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php esc_html_e( 'Edit in BB', 'beavermind' ); ?></a>
							<a class="button button-small" href="<?php echo esc_url( get_edit_post_link( (int) $v['post_id'] ) ); ?>" target="_blank"<?php echo $edit_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php esc_html_e( 'Edit in WP', 'beavermind' ); ?></a>
							<span style="color:#8c8f94; margin-left:auto;">#<?php echo (int) $v['post_id']; ?></span>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Inline color swatches so users can see what palette Claude picked.
	 */
	public static function render_theme_swatches( array $colors ): void {
		echo '<span style="display:inline-block; vertical-align:middle;">';
		foreach ( $colors as $name => $hex ) {
			$hex = ltrim( (string) $hex, '#' );
			if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
				continue;
			}
			printf(
				'<span title="%1$s: #%2$s" style="display:inline-block; width:14px; height:14px; background:#%2$s; border:1px solid rgba(0,0,0,0.15); margin-right:2px; vertical-align:middle;"></span>',
				esc_attr( $name ),
				esc_attr( $hex )
			);
		}
		echo '</span>';
	}
}
