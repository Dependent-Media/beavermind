<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI: paste a Figma share URL, get a page.
 *
 * Pipeline: FigmaFetcher (Figma REST → signed PNG URL → bytes) →
 * Planner::plan_from_image() → LayoutWriter::apply_plan(). The Figma API
 * call is just an image-fetch detour; everything downstream is the same
 * code path as the From Image flow.
 */
class FigmaGenerator {

	const SLUG      = 'beavermind-figma';
	const ACTION    = 'beavermind_figma_input';
	const TRANSIENT = 'beavermind_last_figma_';

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
			__( 'From Figma', 'beavermind' ),
			__( 'From Figma', 'beavermind' ),
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

		$url_default      = (string) ( $last['url'] ?? '' );
		$brief_default    = (string) ( $last['brief'] ?? '' );
		$variants_default = (int) ( $last['variants'] ?? 1 );
		$has_token        = '' !== trim( (string) Plugin::instance()->get_option( 'figma_token', '' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BeaverMind — From Figma', 'beavermind' ); ?></h1>
			<p><?php esc_html_e( 'Paste a Figma share URL. BeaverMind asks Figma to render the frame as a PNG, then sends it through the same Claude-vision pipeline as From Image. Include a frame-specific link (right-click frame → Copy link) for best results.', 'beavermind' ); ?></p>

			<?php if ( ! $has_token ) : ?>
				<div class="notice notice-warning">
					<p><?php
					printf(
						/* translators: %s: link to settings */
						wp_kses_post( __( 'Figma personal access token is not set. Configure it in <a href="%s">BeaverMind settings</a>. (Generate one at figma.com → Account settings → Personal access tokens — needs file_read scope.)', 'beavermind' ) ),
						esc_url( admin_url( 'admin.php?page=' . Settings::MENU_SLUG ) )
					);
					?></p>
				</div>
			<?php endif; ?>

			<?php if ( $last && ! empty( $last['error'] ) ) : ?>
				<div class="notice notice-error"><p><strong><?php esc_html_e( 'Failed:', 'beavermind' ); ?></strong> <?php echo esc_html( $last['error'] ); ?></p></div>
			<?php endif; ?>

			<?php
			if ( $last && ! empty( $last['results'] ) ) {
				PlanRunner::render_results_notice( (array) $last['results'] );
			}
			?>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin-top:1.5rem;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="bm_figma_url"><?php esc_html_e( 'Figma URL', 'beavermind' ); ?></label></th>
							<td>
								<input type="url" id="bm_figma_url" name="figma_url" value="<?php echo esc_attr( $url_default ); ?>" class="regular-text code" placeholder="https://www.figma.com/design/.../?node-id=1-2" required <?php disabled( ! $has_token ); ?> />
								<p class="description"><?php esc_html_e( 'Right-click a frame in Figma → Copy link to get a node-specific URL. Without a node-id we use the file thumbnail.', 'beavermind' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bm_brief"><?php esc_html_e( 'Brief', 'beavermind' ); ?></label></th>
							<td>
								<textarea id="bm_brief" name="brief" rows="4" cols="80" class="large-text" placeholder="What's the page for? Who's the audience? Any tone or design direction?" <?php disabled( ! $has_token ); ?>><?php echo esc_textarea( $brief_default ); ?></textarea>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bm_post_status"><?php esc_html_e( 'Status', 'beavermind' ); ?></label></th>
							<td>
								<select id="bm_post_status" name="post_status" <?php disabled( ! $has_token ); ?>>
									<option value="draft" selected><?php esc_html_e( 'Draft (recommended)', 'beavermind' ); ?></option>
									<option value="publish"><?php esc_html_e( 'Publish immediately', 'beavermind' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bm_variants"><?php esc_html_e( 'Variants', 'beavermind' ); ?></label></th>
							<td>
								<select id="bm_variants" name="variants" <?php disabled( ! $has_token ); ?>>
									<?php foreach ( array( 1, 2, 3, 5 ) as $n ) : ?>
										<option value="<?php echo (int) $n; ?>" <?php selected( $variants_default, $n ); ?>><?php echo (int) $n; ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Figma render happens once; each variant then asks Claude to interpret the same image independently.', 'beavermind' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Generate Page', 'beavermind' ), 'primary', 'submit', true, $has_token ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_submit(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( self::ACTION );

		$url      = isset( $_POST['figma_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['figma_url'] ) ) : '';
		$brief    = isset( $_POST['brief'] ) ? trim( wp_unslash( (string) $_POST['brief'] ) ) : '';
		$status   = isset( $_POST['post_status'] ) && in_array( $_POST['post_status'], array( 'draft', 'publish' ), true )
			? (string) $_POST['post_status']
			: 'draft';
		$variants = isset( $_POST['variants'] ) ? (int) $_POST['variants'] : 1;

		$user_id = get_current_user_id();
		$store = array( 'url' => $url, 'brief' => $brief, 'variants' => $variants );

		$token = (string) Plugin::instance()->get_option( 'figma_token', '' );
		$fetcher = new FigmaFetcher( $token );

		$result = $fetcher->fetch( $url );
		if ( is_wp_error( $result ) ) {
			$store['error'] = 'Figma fetch failed: ' . $result->get_error_message();
			$this->stash_and_redirect( $user_id, $store );
		}

		$effective_brief = '' !== $brief
			? $brief
			: 'Recreate this Figma frame as a polished landing page using the BeaverMind fragment library.';

		// Figma render happens once; variants are cheap re-interpretations of
		// the same bytes.
		$run = PlanRunner::run(
			$variants,
			fn() => $this->planner->plan_from_image(
				$result['bytes'],
				$result['media_type'],
				$effective_brief,
				array( 'post_status' => $status )
			),
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
