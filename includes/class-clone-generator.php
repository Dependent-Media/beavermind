<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI: URL in, generated draft page out.
 *
 * Pipeline: SiteCloner::fetch() → Planner::plan(with reference) → LayoutWriter::apply_plan().
 * Errors at any step surface as inline admin notices.
 */
class CloneGenerator {

	const SLUG      = 'beavermind-clone';
	const ACTION    = 'beavermind_clone_from_url';
	const TRANSIENT = 'beavermind_last_clone_';

	private Planner $planner;
	private LayoutWriter $writer;
	private FragmentLibrary $fragments;
	private SiteCloner $cloner;

	public function __construct( Planner $planner, LayoutWriter $writer, FragmentLibrary $fragments, SiteCloner $cloner ) {
		$this->planner   = $planner;
		$this->writer    = $writer;
		$this->fragments = $fragments;
		$this->cloner    = $cloner;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_submit' ) );
	}

	public function add_submenu(): void {
		add_submenu_page(
			Settings::MENU_SLUG,
			__( 'Clone from URL', 'beavermind' ),
			__( 'Clone from URL', 'beavermind' ),
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

		$url_default  = $last['url'] ?? ( isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( (string) $_GET['url'] ) ) : '' );
		$hint_default = $last['hint'] ?? '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BeaverMind — Clone from URL', 'beavermind' ); ?></h1>
			<p id="bm-clone-intro"><?php esc_html_e( 'Paste a URL. BeaverMind fetches the page, extracts its structure and copy, and asks Claude to compose a nicer version using the Beaver Builder fragment library. The result is saved as a draft for review.', 'beavermind' ); ?></p>

			<?php if ( $last && ! empty( $last['error'] ) ) : ?>
				<div class="notice notice-error" data-testid="bm-error"><p><strong><?php esc_html_e( 'Failed:', 'beavermind' ); ?></strong> <?php echo esc_html( $last['error'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( $last && ! empty( $last['post_id'] ) ) : ?>
				<div class="notice notice-success" data-testid="bm-success">
					<p>
						<?php
						printf(
							wp_kses_post( __( 'Cloned <a href="%1$s" target="_blank">%2$s</a> into draft <a href="%3$s" target="_blank" data-testid="bm-edit-link">page #%4$d</a> — <a href="%5$s" target="_blank" data-testid="bm-bb-link">edit with Beaver Builder</a>.', 'beavermind' ) ),
							esc_url( $last['url'] ),
							esc_html( $last['url'] ),
							esc_url( get_edit_post_link( (int) $last['post_id'] ) ),
							(int) $last['post_id'],
							esc_url( add_query_arg( 'fl_builder', '', get_permalink( (int) $last['post_id'] ) ) )
						);
						?>
					</p>
				</div>
				<?php $this->render_summary( $last ); ?>
			<?php endif; ?>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin-top:1.5rem;" data-testid="bm-clone-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="bm_url"><?php esc_html_e( 'Reference URL', 'beavermind' ); ?></label></th>
							<td>
								<input type="url" id="bm_url" name="url" value="<?php echo esc_attr( $url_default ); ?>" class="regular-text code" data-testid="bm-url-input" placeholder="https://example.com/landing" required />
								<p class="description"><?php esc_html_e( 'Public URL of the page to use as the content source.', 'beavermind' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bm_hint"><?php esc_html_e( 'Design hint (optional)', 'beavermind' ); ?></label></th>
							<td>
								<input type="text" id="bm_hint" name="hint" value="<?php echo esc_attr( $hint_default ); ?>" class="regular-text" data-testid="bm-hint-input" placeholder="e.g. modern SaaS, minimal, dark hero" />
								<p class="description"><?php esc_html_e( 'Short note about the visual direction. Leave blank to let Claude decide.', 'beavermind' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bm_post_status"><?php esc_html_e( 'Status', 'beavermind' ); ?></label></th>
							<td>
								<select id="bm_post_status" name="post_status" data-testid="bm-status-select">
									<option value="draft" selected><?php esc_html_e( 'Draft (recommended)', 'beavermind' ); ?></option>
									<option value="publish"><?php esc_html_e( 'Publish immediately', 'beavermind' ); ?></option>
								</select>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Clone Page', 'beavermind' ), 'primary', 'submit', true, array( 'data-testid' => 'bm-clone-submit' ) ); ?>
			</form>
		</div>
		<?php
	}

	private function render_summary( array $last ): void {
		?>
		<div class="card" style="max-width:900px; padding:1rem;" data-testid="bm-plan-summary">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Plan', 'beavermind' ); ?></h2>
			<p><strong><?php esc_html_e( 'Title:', 'beavermind' ); ?></strong> <?php echo esc_html( $last['title'] ?? '' ); ?></p>
			<p><strong><?php esc_html_e( 'Source title:', 'beavermind' ); ?></strong> <?php echo esc_html( $last['source_title'] ?? '' ); ?></p>
			<p><strong><?php esc_html_e( 'Source sections extracted:', 'beavermind' ); ?></strong> <?php echo (int) ( $last['source_sections'] ?? 0 ); ?></p>
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

		$url    = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['url'] ) ) : '';
		$hint   = isset( $_POST['hint'] ) ? trim( wp_unslash( (string) $_POST['hint'] ) ) : '';
		$status = isset( $_POST['post_status'] ) && in_array( $_POST['post_status'], array( 'draft', 'publish' ), true )
			? (string) $_POST['post_status']
			: 'draft';

		$user_id = get_current_user_id();
		$store = array( 'url' => $url, 'hint' => $hint );

		if ( '' === $url ) {
			$store['error'] = __( 'URL is required.', 'beavermind' );
			$this->stash_and_redirect( $user_id, $store );
		}

		// Step 1: fetch & extract.
		$ref = $this->cloner->fetch( $url );
		if ( is_wp_error( $ref ) ) {
			$store['error'] = 'Fetch failed: ' . $ref->get_error_message();
			$this->stash_and_redirect( $user_id, $store );
		}
		$store['source_title']    = $ref['title'] ?? '';
		$store['source_sections'] = count( (array) ( $ref['sections'] ?? array() ) );

		// Step 2: plan with reference.
		$brief = '' !== $hint
			? "Redesign this page using the BeaverMind fragment library. Design direction: $hint. Preserve the page's actual offering, headings, and CTAs, but rewrite copy to be sharper."
			: "Redesign this page using the BeaverMind fragment library. Preserve the page's actual offering, headings, and CTAs, but rewrite copy to be sharper.";

		$plan = $this->planner->plan( $brief, array( 'post_status' => $status ), $ref );
		if ( is_wp_error( $plan ) ) {
			$store['error'] = 'Plan failed: ' . $plan->get_error_message();
			$this->stash_and_redirect( $user_id, $store );
		}

		// Step 3: write.
		$post_id = $this->writer->apply_plan( $plan, $this->fragments );
		if ( is_wp_error( $post_id ) ) {
			$store['error'] = 'Write failed: ' . $post_id->get_error_message();
			$store['title'] = $plan['page']['title'] ?? '';
			$store['fragments'] = $plan['fragments'] ?? array();
			$this->stash_and_redirect( $user_id, $store );
		}

		$store['post_id']   = (int) $post_id;
		$store['title']     = $plan['page']['title'] ?? '';
		$store['fragments'] = $plan['fragments'] ?? array();
		$store['usage']     = $plan['usage'] ?? null;
		$this->stash_and_redirect( $user_id, $store );
	}

	private function stash_and_redirect( int $user_id, array $store ): void {
		set_transient( self::TRANSIENT . $user_id, $store, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}
