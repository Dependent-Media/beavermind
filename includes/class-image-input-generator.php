<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI: upload an image (screenshot, mockup, Figma export), get a page.
 *
 * Pipeline: read uploaded file → Planner::plan_from_image() with Claude
 * vision → LayoutWriter::apply_plan(). The image is sent inline as base64;
 * Anthropic caps per-image at ~5 MB encoded so we cap raw at ~3.5 MB.
 */
class ImageInputGenerator {

	const SLUG       = 'beavermind-image';
	const ACTION     = 'beavermind_image_input';
	const TRANSIENT  = 'beavermind_last_image_';
	const MAX_BYTES  = 3_500_000; // pre-encoding cap so base64 stays under 5 MB

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
			__( 'From Image', 'beavermind' ),
			__( 'From Image', 'beavermind' ),
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

		$brief_default = (string) ( $last['brief'] ?? '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BeaverMind — From Image', 'beavermind' ); ?></h1>
			<p><?php esc_html_e( 'Upload a screenshot, mockup, or Figma export. Claude reads the image (vision) and composes a Beaver Builder page that mirrors its structure using the fragment library.', 'beavermind' ); ?></p>

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

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data" style="margin-top:1.5rem;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo (int) self::MAX_BYTES; ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="bm_image"><?php esc_html_e( 'Image', 'beavermind' ); ?></label></th>
							<td>
								<input type="file" id="bm_image" name="image" accept="image/png,image/jpeg,image/webp,image/gif" required />
								<p class="description"><?php
									printf(
										/* translators: %s: max file size in MB */
										esc_html__( 'PNG, JPEG, WebP, or GIF. Max %s MB. Larger images should be resized first.', 'beavermind' ),
										esc_html( number_format( self::MAX_BYTES / 1024 / 1024, 1 ) )
									);
								?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bm_brief"><?php esc_html_e( 'Brief', 'beavermind' ); ?></label></th>
							<td>
								<textarea id="bm_brief" name="brief" rows="4" cols="80" class="large-text" placeholder="What's the page for? Who's the audience? Any tone or design direction?"><?php echo esc_textarea( $brief_default ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Optional but recommended. Without context Claude has to guess from the image alone.', 'beavermind' ); ?></p>
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

		$brief  = isset( $_POST['brief'] ) ? trim( wp_unslash( (string) $_POST['brief'] ) ) : '';
		$status = isset( $_POST['post_status'] ) && in_array( $_POST['post_status'], array( 'draft', 'publish' ), true )
			? (string) $_POST['post_status']
			: 'draft';

		$user_id = get_current_user_id();
		$store = array( 'brief' => $brief );

		if ( empty( $_FILES['image'] ) || ! isset( $_FILES['image']['tmp_name'] ) || '' === $_FILES['image']['tmp_name'] ) {
			$store['error'] = __( 'No image was uploaded. Pick a file and try again.', 'beavermind' );
			$this->stash_and_redirect( $user_id, $store );
		}

		$file_info  = $_FILES['image'];
		$tmp_name   = (string) $file_info['tmp_name'];
		$size       = (int) ( $file_info['size'] ?? 0 );
		$upload_err = (int) ( $file_info['error'] ?? UPLOAD_ERR_NO_FILE );

		if ( UPLOAD_ERR_OK !== $upload_err ) {
			$store['error'] = self::upload_error_message( $upload_err );
			$this->stash_and_redirect( $user_id, $store );
		}
		if ( $size > self::MAX_BYTES ) {
			$store['error'] = sprintf(
				/* translators: %s: max file size in MB */
				__( 'Image too large. Max %s MB.', 'beavermind' ),
				number_format( self::MAX_BYTES / 1024 / 1024, 1 )
			);
			$this->stash_and_redirect( $user_id, $store );
		}

		// Detect MIME type from the actual file contents, not the (forgeable)
		// browser-supplied name. wp_check_filetype_and_ext is the WP-blessed way.
		$check = wp_check_filetype_and_ext( $tmp_name, $file_info['name'] ?? 'upload' );
		$media_type = (string) ( $check['type'] ?? '' );
		if ( ! in_array( $media_type, array( 'image/png', 'image/jpeg', 'image/webp', 'image/gif' ), true ) ) {
			$store['error'] = __( 'File is not a recognized image (PNG/JPEG/WebP/GIF).', 'beavermind' );
			$this->stash_and_redirect( $user_id, $store );
		}

		$bytes = file_get_contents( $tmp_name );
		if ( false === $bytes ) {
			$store['error'] = __( 'Could not read uploaded file.', 'beavermind' );
			$this->stash_and_redirect( $user_id, $store );
		}

		$plan = $this->planner->plan_from_image( $bytes, $media_type, $brief, array( 'post_status' => $status ) );
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

	private static function upload_error_message( int $code ): string {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE: return __( 'Upload exceeded the server\'s size limit.', 'beavermind' );
			case UPLOAD_ERR_PARTIAL:   return __( 'Upload was interrupted. Try again.', 'beavermind' );
			case UPLOAD_ERR_NO_FILE:   return __( 'No file was uploaded.', 'beavermind' );
			case UPLOAD_ERR_NO_TMP_DIR:return __( 'Server has no temp directory configured for uploads.', 'beavermind' );
			case UPLOAD_ERR_CANT_WRITE:return __( 'Server could not write the upload to disk.', 'beavermind' );
			case UPLOAD_ERR_EXTENSION: return __( 'A PHP extension blocked the upload.', 'beavermind' );
			default:                   return __( 'Unknown upload error.', 'beavermind' );
		}
	}

	private function stash_and_redirect( int $user_id, array $store ): void {
		set_transient( self::TRANSIENT . $user_id, $store, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}
