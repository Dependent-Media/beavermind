<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI: free-text brief in, generated draft page out.
 *
 * The actual AI work lives in Planner; this class is just the WP admin shell —
 * menu page, form, nonce/capability gating, redirect-back-with-results.
 *
 * Generated pages are stored as drafts so the user can review before
 * publishing. Plan and usage data are stashed in a transient for one display
 * cycle and shown after redirect.
 */
class PromptGenerator {

	const SLUG       = 'beavermind-generate';
	const ACTION     = 'beavermind_generate_from_prompt';
	const TRANSIENT  = 'beavermind_last_generation_';

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
			__( 'Generate from Prompt', 'beavermind' ),
			__( 'Generate', 'beavermind' ),
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

		$brief_default    = isset( $_GET['brief'] ) ? wp_unslash( (string) $_GET['brief'] ) : ( $last['brief'] ?? '' );
		// Default to 3 variants — matches the Elementor "always show 3 to
		// pick from" pattern. Users still get the single-shot path by
		// dropping the count to 1.
		$variants_default = (int) ( $last['variants'] ?? 3 );
		$catalog          = $this->fragments->catalog();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BeaverMind — Generate from Prompt', 'beavermind' ); ?></h1>
			<p><?php esc_html_e( 'Describe the page you want. Claude will pick fragments from the library and fill in the copy. The result is saved as a draft page for review.', 'beavermind' ); ?></p>

			<?php if ( $last && ! empty( $last['error'] ) ) : ?>
				<div class="notice notice-error"><p><strong><?php esc_html_e( 'Generation failed:', 'beavermind' ); ?></strong> <?php echo esc_html( $last['error'] ); ?></p></div>
			<?php endif; ?>

			<?php
			if ( $last && ! empty( $last['results'] ) ) {
				PlanRunner::render_results_notice( (array) $last['results'] );
				if ( count( $last['results'] ) === 1 ) {
					$this->render_plan_summary( $last['results'][0] );
				}
			}
			?>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin-top:1.5rem;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="bm_brief"><?php esc_html_e( 'Page brief', 'beavermind' ); ?></label></th>
							<td>
								<textarea id="bm_brief" name="brief" rows="6" cols="80" class="large-text code" placeholder="e.g. A landing page for an AI-powered project management app aimed at design agencies. Highlight speed, integrations with Figma, and team collaboration features."><?php echo esc_textarea( $brief_default ); ?></textarea>
								<p class="description" style="display:flex; align-items:center; gap:8px;">
									<button type="button" class="button button-secondary" data-bm-enhance-target="bm_brief">✨ <?php esc_html_e( 'Enhance Prompt', 'beavermind' ); ?></button>
									<span><?php esc_html_e( 'Describe the audience, the offering, and what the page should achieve. Specifics produce better copy. Click Enhance to let Claude tighten and structure your prompt (free, runs on Haiku).', 'beavermind' ); ?></span>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bm_post_status"><?php esc_html_e( 'Status', 'beavermind' ); ?></label></th>
							<td>
								<select id="bm_post_status" name="post_status">
									<option value="draft" selected><?php esc_html_e( 'Draft (recommended)', 'beavermind' ); ?></option>
									<option value="publish"><?php esc_html_e( 'Publish immediately', 'beavermind' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bm_variants"><?php esc_html_e( 'Variants', 'beavermind' ); ?></label></th>
							<td>
								<select id="bm_variants" name="variants">
									<?php foreach ( array( 1, 2, 3, 5 ) as $n ) : ?>
										<option value="<?php echo (int) $n; ?>" <?php selected( $variants_default, $n ); ?>><?php echo (int) $n; ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Generate N independent plans for the same brief. Each variant adds ~15-30s and one Claude API call.', 'beavermind' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Generate', 'beavermind' ) ); ?>
			</form>

			<h2 style="margin-top:2rem;"><?php esc_html_e( 'Available fragments', 'beavermind' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr>
					<th><?php esc_html_e( 'ID', 'beavermind' ); ?></th>
					<th><?php esc_html_e( 'Name', 'beavermind' ); ?></th>
					<th><?php esc_html_e( 'Category', 'beavermind' ); ?></th>
					<th><?php esc_html_e( 'Slots', 'beavermind' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $catalog as $id => $entry ) : ?>
						<tr>
							<td><code><?php echo esc_html( $id ); ?></code></td>
							<td><?php echo esc_html( $entry['meta']['name'] ?? $id ); ?></td>
							<td><?php echo esc_html( $entry['meta']['category'] ?? '' ); ?></td>
							<td><?php echo esc_html( implode( ', ', array_keys( (array) ( $entry['meta']['slots'] ?? array() ) ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_plan_summary( array $last ): void {
		?>
		<div class="card" style="max-width:900px; padding:1rem;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Plan', 'beavermind' ); ?></h2>
			<p><strong><?php esc_html_e( 'Title:', 'beavermind' ); ?></strong> <?php echo esc_html( $last['title'] ?? '' ); ?></p>
			<ol>
				<?php foreach ( (array) ( $last['fragments'] ?? array() ) as $f ) : ?>
					<li>
						<code><?php echo esc_html( $f['id'] ); ?></code>
						<?php if ( ! empty( $f['slots'] ) ) : ?>
							<ul style="margin:0.25rem 0 0.5rem 1rem;">
								<?php foreach ( $f['slots'] as $name => $value ) : ?>
									<li><strong><?php echo esc_html( $name ); ?>:</strong> <?php echo esc_html( wp_strip_all_tags( (string) $value ) ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
			<?php if ( ! empty( $last['usage'] ) ) : ?>
				<p style="color:#666; font-size:12px;">
					<strong><?php esc_html_e( 'Usage:', 'beavermind' ); ?></strong>
					input: <?php echo (int) ( $last['usage']['input_tokens'] ?? 0 ); ?>,
					output: <?php echo (int) ( $last['usage']['output_tokens'] ?? 0 ); ?>,
					cache write: <?php echo (int) ( $last['usage']['cache_creation_input_tokens'] ?? 0 ); ?>,
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

		$brief = isset( $_POST['brief'] ) ? trim( wp_unslash( (string) $_POST['brief'] ) ) : '';
		$status = isset( $_POST['post_status'] ) && in_array( $_POST['post_status'], array( 'draft', 'publish' ), true )
			? (string) $_POST['post_status']
			: 'draft';
		$variants = isset( $_POST['variants'] ) ? (int) $_POST['variants'] : 1;

		$user_id = get_current_user_id();
		$store = array( 'brief' => $brief, 'variants' => $variants );

		if ( '' === $brief ) {
			$store['error'] = __( 'Brief is required.', 'beavermind' );
			$this->stash_and_redirect( $user_id, $store );
		}

		$run = PlanRunner::run(
			$variants,
			fn() => $this->planner->plan( $brief, array( 'post_status' => $status ) ),
			$this->writer,
			$this->fragments
		);

		if ( empty( $run['results'] ) ) {
			$store['error'] = 'All variants failed: ' . implode( ' · ', $run['errors'] );
		} else {
			$store['results'] = $run['results'];
			if ( ! empty( $run['errors'] ) ) {
				$store['error'] = 'Some variants failed: ' . implode( ' · ', $run['errors'] );
			}
		}
		$this->stash_and_redirect( $user_id, $store );
	}

	private function stash_and_redirect( int $user_id, array $store ): void {
		set_transient( self::TRANSIENT . $user_id, $store, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}
