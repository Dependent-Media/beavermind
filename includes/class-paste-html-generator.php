<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI: paste raw HTML, get a redesigned page.
 *
 * Same pipeline as Clone from URL but skips the fetch step. Useful for HTML
 * behind auth, email templates, locally-saved pages, or anything else we
 * can't reach via wp_remote_get. The optional Source URL field is used to
 * absolutize relative <img src="/foo.png"> references in the extracted
 * content so Claude can lift the URLs into image slots.
 */
class PasteHTMLGenerator {

	const SLUG      = 'beavermind-paste-html';
	const ACTION    = 'beavermind_paste_html';
	const TRANSIENT = 'beavermind_last_paste_';

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
			__( 'Paste HTML', 'beavermind' ),
			__( 'Paste HTML', 'beavermind' ),
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

		$html_default = (string) ( $last['html'] ?? '' );
		$base_default = (string) ( $last['base_url'] ?? '' );
		$hint_default = (string) ( $last['hint'] ?? '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BeaverMind — Paste HTML', 'beavermind' ); ?></h1>
			<p><?php esc_html_e( 'Paste the HTML of a page you want redesigned. BeaverMind extracts its structure and copy and asks Claude to compose a Beaver Builder version. Use this for pages behind auth, email templates, or HTML on disk that Clone-from-URL can\'t reach.', 'beavermind' ); ?></p>

			<?php if ( $last && ! empty( $last['error'] ) ) : ?>
				<div class="notice notice-error"><p><strong><?php esc_html_e( 'Failed:', 'beavermind' ); ?></strong> <?php echo esc_html( $last['error'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( $last && ! empty( $last['post_id'] ) ) : ?>
				<div class="notice notice-success">
					<p>
						<?php
						printf(
							wp_kses_post( __( 'Generated draft <a href="%1$s" target="_blank">page #%2$d</a> — <a href="%3$s" target="_blank">edit with Beaver Builder</a>.', 'beavermind' ) ),
							esc_url( get_edit_post_link( (int) $last['post_id'] ) ),
							(int) $last['post_id'],
							esc_url( add_query_arg( 'fl_builder', '', get_permalink( (int) $last['post_id'] ) ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin-top:1.5rem;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="bm_html"><?php esc_html_e( 'HTML source', 'beavermind' ); ?></label></th>
							<td>
								<textarea id="bm_html" name="html" rows="14" cols="80" class="large-text code" placeholder="<!DOCTYPE html><html>..." required><?php echo esc_textarea( $html_default ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Full or partial HTML. Scripts, styles, and chrome (nav/footer/headers) are stripped before extraction.', 'beavermind' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bm_base_url"><?php esc_html_e( 'Source URL (optional)', 'beavermind' ); ?></label></th>
							<td>
								<input type="url" id="bm_base_url" name="base_url" value="<?php echo esc_attr( $base_default ); ?>" class="regular-text code" placeholder="https://example.com/landing" />
								<p class="description"><?php esc_html_e( 'Used to absolutize relative URLs (image src, link href). Leave blank if the HTML is already self-contained.', 'beavermind' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bm_hint"><?php esc_html_e( 'Design hint (optional)', 'beavermind' ); ?></label></th>
							<td>
								<input type="text" id="bm_hint" name="hint" value="<?php echo esc_attr( $hint_default ); ?>" class="regular-text" placeholder="e.g. modern SaaS, minimal, dark hero" />
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
					</tbody>
				</table>

				<?php submit_button( __( 'Generate Page', 'beavermind' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_submit(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( self::ACTION );

		$html     = isset( $_POST['html'] ) ? wp_unslash( (string) $_POST['html'] ) : '';
		$base_url = isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['base_url'] ) ) : '';
		$hint     = isset( $_POST['hint'] ) ? trim( wp_unslash( (string) $_POST['hint'] ) ) : '';
		$status   = isset( $_POST['post_status'] ) && in_array( $_POST['post_status'], array( 'draft', 'publish' ), true )
			? (string) $_POST['post_status']
			: 'draft';

		$user_id = get_current_user_id();
		$store = array( 'html' => $html, 'base_url' => $base_url, 'hint' => $hint );

		if ( '' === trim( $html ) ) {
			$store['error'] = __( 'HTML source is required.', 'beavermind' );
			$this->stash_and_redirect( $user_id, $store );
		}

		// Use 'about:blank' as the URL when no base is given — SiteCloner only
		// uses it for absolutizing relative URLs, so a blank base means
		// relative URLs stay relative (which Claude will see and ignore).
		$base = '' !== $base_url ? $base_url : 'about:blank';
		$ref  = $this->cloner->extract( $html, $base, $base );

		$brief = '' !== $hint
			? "Redesign this HTML using the BeaverMind fragment library. Design direction: $hint. Preserve the page's offering, headings, and CTAs; rewrite copy to be sharper."
			: "Redesign this HTML using the BeaverMind fragment library. Preserve the page's offering, headings, and CTAs; rewrite copy to be sharper.";

		$plan = $this->planner->plan( $brief, array( 'post_status' => $status ), $ref );
		if ( is_wp_error( $plan ) ) {
			$store['error'] = 'Plan failed: ' . $plan->get_error_message();
			$this->stash_and_redirect( $user_id, $store );
		}

		$post_id = $this->writer->apply_plan( $plan, $this->fragments );
		if ( is_wp_error( $post_id ) ) {
			$store['error'] = 'Write failed: ' . $post_id->get_error_message();
			$this->stash_and_redirect( $user_id, $store );
		}

		$store['post_id'] = (int) $post_id;
		$this->stash_and_redirect( $user_id, $store );
	}

	private function stash_and_redirect( int $user_id, array $store ): void {
		set_transient( self::TRANSIENT . $user_id, $store, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}
