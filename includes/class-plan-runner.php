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
	 *
	 * @return array{results: array<int, array>, errors: array<int, string>}
	 *   results[i] = ['post_id'=>int, 'title'=>string, 'fragments'=>array,
	 *                 'theme'=>?array, 'usage'=>?array]
	 *   errors[]    = "variant N: <message>"
	 */
	public static function run(
		int $variants,
		callable $plan_factory,
		LayoutWriter $writer,
		FragmentLibrary $fragments
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
			$post_id = $writer->apply_plan( $plan, $fragments );
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
		?>
		<div class="notice notice-success" data-testid="bm-success">
			<p><strong><?php
			printf(
				/* translators: %d: number of variants generated */
				esc_html( _n( 'Generated %d page:', 'Generated %d pages:', count( $results ), 'beavermind' ) ),
				count( $results )
			);
			?></strong></p>
			<ol>
				<?php foreach ( $results as $i => $v ) : ?>
					<?php
					// Tag the FIRST result's links with the testids the
					// Playwright clone spec asserts on. Multi-variant runs
					// still use the same tags on the first link — Playwright
					// only needs to find one valid edit link.
					$edit_attr = ( 0 === $i ) ? ' data-testid="bm-edit-link"' : '';
					$bb_attr   = ( 0 === $i ) ? ' data-testid="bm-bb-link"'   : '';
					?>
					<li>
						<a href="<?php echo esc_url( get_edit_post_link( (int) $v['post_id'] ) ); ?>" target="_blank"<?php echo $edit_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>page #<?php echo (int) $v['post_id']; ?></a>
						— <em><?php echo esc_html( $v['title'] ?? '(untitled)' ); ?></em>
						— <a href="<?php echo esc_url( add_query_arg( 'fl_builder', '', get_permalink( (int) $v['post_id'] ) ) ); ?>" target="_blank"<?php echo $bb_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>edit with Beaver Builder</a>
						&nbsp;<small style="color:#666;">[<?php echo esc_html( implode( ', ', array_column( (array) ( $v['fragments'] ?? array() ), 'id' ) ) ); ?>]</small>
						<?php if ( ! empty( $v['theme']['colors'] ) ) : ?>
							&nbsp;<?php self::render_theme_swatches( (array) $v['theme']['colors'] ); ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
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
