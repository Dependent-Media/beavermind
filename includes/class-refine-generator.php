<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI: pick an existing BeaverMind-generated page, describe a change,
 * and get the layout rewritten in place.
 *
 * Only pages with a stored `_beavermind_plan` meta show up — that's the
 * prior plan Claude needs as context for the edit. Non-BeaverMind pages
 * are filtered out (no prior plan = nothing to refine).
 */
class RefineGenerator {

	const SLUG      = 'beavermind-refine';
	const ACTION    = 'beavermind_refine_page';
	const TRANSIENT = 'beavermind_last_refine_';

	private Planner $planner;
	private LayoutWriter $writer;
	private FragmentLibrary $fragments;

	public function __construct( Planner $planner, LayoutWriter $writer, FragmentLibrary $fragments ) {
		$this->planner   = $planner;
		$this->writer    = $writer;
		$this->fragments = $fragments;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_submit' ) );
	}

	public function add_submenu(): void {
		add_submenu_page(
			Settings::MENU_SLUG,
			__( 'Refine', 'beavermind' ),
			__( 'Refine', 'beavermind' ),
			'edit_pages',
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		$last    = get_transient( self::TRANSIENT . $user_id );
		if ( $last ) {
			delete_transient( self::TRANSIENT . $user_id );
		}

		$refinable = $this->list_refinable_pages();
		$selected  = (int) ( $_GET['post_id'] ?? $last['post_id'] ?? 0 );
		$instr_def = (string) ( $last['instruction'] ?? '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BeaverMind — Refine', 'beavermind' ); ?></h1>
			<p><?php esc_html_e( 'Pick a BeaverMind-generated page and describe a change. Claude will rewrite the plan and the page layout in place. The original plan is replaced, not merged — Claude sees it as context.', 'beavermind' ); ?></p>

			<?php if ( $last && ! empty( $last['error'] ) ) : ?>
				<div class="notice notice-error" data-testid="bm-refine-error"><p><strong><?php esc_html_e( 'Failed:', 'beavermind' ); ?></strong> <?php echo esc_html( $last['error'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( $last && ! empty( $last['post_id'] ) && empty( $last['error'] ) ) : ?>
				<div class="notice notice-success" data-testid="bm-refine-success">
					<p>
						<?php
						printf(
							wp_kses_post( __( 'Refined <a href="%1$s" target="_blank" data-testid="bm-refine-edit-link">page #%2$d</a> — <a href="%3$s" target="_blank">edit with Beaver Builder</a>.', 'beavermind' ) ),
							esc_url( get_edit_post_link( (int) $last['post_id'] ) ),
							(int) $last['post_id'],
							esc_url( add_query_arg( 'fl_builder', '', get_permalink( (int) $last['post_id'] ) ) )
						);
						?>
					</p>
				</div>
				<?php $this->render_summary( $last ); ?>
			<?php endif; ?>

			<?php if ( empty( $refinable ) ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'No BeaverMind-generated pages exist yet. Use Generate or Clone from URL to create one first.', 'beavermind' ); ?></p></div>
			<?php else : ?>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" data-testid="bm-refine-form" style="margin-top:1.5rem;">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
					<?php wp_nonce_field( self::ACTION ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="bm_post_id"><?php esc_html_e( 'Page', 'beavermind' ); ?></label></th>
								<td>
									<select id="bm_post_id" name="post_id" data-testid="bm-refine-post-select" required>
										<option value=""><?php esc_html_e( '— Select a page —', 'beavermind' ); ?></option>
										<?php foreach ( $refinable as $entry ) : ?>
											<option value="<?php echo (int) $entry['id']; ?>" <?php selected( $selected, (int) $entry['id'] ); ?>>
												#<?php echo (int) $entry['id']; ?> — <?php echo esc_html( $entry['title'] ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="bm_instruction"><?php esc_html_e( 'Change', 'beavermind' ); ?></label></th>
								<td>
									<?php $this->render_quick_actions(); ?>
									<textarea id="bm_instruction" name="instruction" rows="4" cols="80" class="large-text" data-testid="bm-refine-instruction" placeholder="e.g. Make the hero more playful. Add a testimonials section. Drop the FAQ. Use a warmer tone." required><?php echo esc_textarea( $instr_def ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Quick actions append to the textarea — stack several together (e.g. "Make shorter" + "More playful") in one refinement run.', 'beavermind' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Refine Page', 'beavermind' ), 'primary', 'submit', true, array( 'data-testid' => 'bm-refine-submit' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Quick Refine action button row above the instruction
	 * textarea. Each button is wired in admin.js (data-bm-template-target +
	 * data-bm-template) to APPEND its template text to the textarea, so
	 * users can stack actions ("Make shorter" + "More playful") in one
	 * refinement.
	 *
	 * Templates are intentionally short imperative phrases — they read as
	 * natural-language brief additions, not slot fills.
	 */
	private function render_quick_actions(): void {
		$actions = array(
			__( 'Make shorter', 'beavermind' )       => 'Tighten the page: shorten body copy ~30% and drop one fragment if there\'s a clear least-important section.',
			__( 'Make bolder', 'beavermind' )        => 'Make the hero bolder — punchier headline, stronger CTA verb, more confident voice throughout.',
			__( 'More playful', 'beavermind' )       => 'Use a warmer, more playful tone. Looser sentences, a bit of personality. Keep it professional.',
			__( 'More professional', 'beavermind' )  => 'Tighten the voice to feel more buttoned-up and credible. Drop colloquialisms; lead with concrete claims.',
			__( 'Add testimonial', 'beavermind' )    => 'Add a testimonial-single fragment after the hero with a plausible customer quote.',
			__( 'Add FAQ', 'beavermind' )            => 'Add a faq-list-4 fragment near the bottom answering the obvious objections.',
			__( 'Drop FAQ', 'beavermind' )           => 'Remove the FAQ section.',
			__( 'Hero with image', 'beavermind' )    => 'Swap the current hero for hero-with-image; keep the same copy and CTA.',
		);
		?>
		<div style="margin-bottom: 8px; display:flex; flex-wrap:wrap; gap:6px;">
			<?php foreach ( $actions as $label => $template ) : ?>
				<button type="button"
				        class="button button-small"
				        data-bm-template-target="bm_instruction"
				        data-bm-template="<?php echo esc_attr( $template ); ?>"
				        title="<?php echo esc_attr( $template ); ?>"><?php echo esc_html( $label ); ?></button>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_summary( array $last ): void {
		?>
		<div class="card" style="max-width:900px; padding:1rem;" data-testid="bm-refine-summary">
			<h2 style="margin-top:0;"><?php esc_html_e( 'New plan', 'beavermind' ); ?></h2>
			<p><strong><?php esc_html_e( 'Title:', 'beavermind' ); ?></strong> <?php echo esc_html( $last['title'] ?? '' ); ?></p>
			<ol>
				<?php foreach ( (array) ( $last['fragments'] ?? array() ) as $f ) : ?>
					<li><code><?php echo esc_html( $f['id'] ); ?></code></li>
				<?php endforeach; ?>
			</ol>
			<?php if ( ! empty( $last['usage'] ) ) : ?>
				<p style="color:#666; font-size:12px;">
					<strong><?php esc_html_e( 'Usage:', 'beavermind' ); ?></strong>
					input: <?php echo (int) ( $last['usage']['input_tokens'] ?? 0 ); ?>,
					output: <?php echo (int) ( $last['usage']['output_tokens'] ?? 0 ); ?>,
					cache read: <?php echo (int) ( $last['usage']['cache_read_input_tokens'] ?? 0 ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_submit(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( self::ACTION );

		$post_id     = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$instruction = isset( $_POST['instruction'] ) ? trim( wp_unslash( (string) $_POST['instruction'] ) ) : '';

		$user_id = get_current_user_id();
		$store = array( 'post_id' => $post_id, 'instruction' => $instruction );

		if ( $post_id <= 0 || '' === $instruction ) {
			$store['error'] = __( 'Page and instruction are both required.', 'beavermind' );
			$this->stash_and_redirect( $user_id, $store );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			$store['error'] = __( 'You cannot edit that page.', 'beavermind' );
			$this->stash_and_redirect( $user_id, $store );
		}

		$plan = $this->planner->refine( $post_id, $instruction );
		if ( is_wp_error( $plan ) ) {
			$store['error'] = 'Plan failed: ' . $plan->get_error_message();
			$this->stash_and_redirect( $user_id, $store );
		}

		$result = $this->writer->apply_plan( $plan, $this->fragments, array(
			'image_filler' => Plugin::instance()->image_filler,
			'brief'        => $instruction,
		) );
		if ( is_wp_error( $result ) ) {
			$store['error']     = 'Write failed: ' . $result->get_error_message();
			$store['title']     = $plan['page']['title'] ?? '';
			$store['fragments'] = $plan['fragments'] ?? array();
			$this->stash_and_redirect( $user_id, $store );
		}

		$store['post_id']   = (int) $result;
		$store['title']     = $plan['page']['title'] ?? '';
		$store['fragments'] = $plan['fragments'] ?? array();
		$store['usage']     = $plan['usage'] ?? null;
		$this->stash_and_redirect( $user_id, $store );
	}

	/**
	 * @return array<int, array{id:int, title:string}>
	 */
	private function list_refinable_pages(): array {
		$query = new \WP_Query( array(
			'post_type'      => 'any',
			'post_status'    => array( 'publish', 'draft', 'future', 'private', 'pending' ),
			'posts_per_page' => 50,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => '_beavermind_plan',
					'compare' => 'EXISTS',
				),
			),
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );

		$out = array();
		foreach ( $query->posts as $id ) {
			$out[] = array(
				'id'    => (int) $id,
				'title' => get_the_title( $id ) ?: '(untitled)',
			);
		}
		return $out;
	}

	private function stash_and_redirect( int $user_id, array $store ): void {
		set_transient( self::TRANSIENT . $user_id, $store, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}
