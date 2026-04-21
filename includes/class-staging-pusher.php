<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI: push a BeaverMind-generated page to a staging site.
 *
 * Lists recent _beavermind_generated posts; for each, lets the user POST
 * its stored plan JSON to /wp-json/beavermind/v1/apply-plan on the staging
 * site, authenticating via WP Application Passwords. The receiving site
 * runs its own LayoutWriter — staging can have different fragments and
 * the page still lands.
 */
class StagingPusher {

	const SLUG      = 'beavermind-staging';
	const ACTION    = 'beavermind_push_staging';
	const TRANSIENT = 'beavermind_last_push_';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_submit' ) );
	}

	public function add_submenu(): void {
		add_submenu_page(
			Settings::MENU_SLUG,
			__( 'Push to Staging', 'beavermind' ),
			__( 'Push to Staging', 'beavermind' ),
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

		$staging_url      = (string) Plugin::instance()->get_option( 'staging_url', '' );
		$staging_user     = (string) Plugin::instance()->get_option( 'staging_username', '' );
		$staging_password = (string) Plugin::instance()->get_option( 'staging_app_password', '' );
		$is_configured    = '' !== $staging_url && '' !== $staging_user && '' !== $staging_password;

		$pushable = $this->list_pushable_pages();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BeaverMind — Push to Staging', 'beavermind' ); ?></h1>
			<p><?php esc_html_e( 'Pushes a BeaverMind-generated page to a configured staging site by POSTing its stored plan JSON to the staging\'s /wp-json/beavermind/v1/apply-plan endpoint. Staging needs BeaverMind installed and a WP Application Password for an admin user.', 'beavermind' ); ?></p>

			<?php if ( ! $is_configured ) : ?>
				<div class="notice notice-warning">
					<p><?php
					printf(
						/* translators: %s: link to settings */
						wp_kses_post( __( 'Staging credentials not configured. Set them in <a href="%s">BeaverMind settings → Integrations</a>.', 'beavermind' ) ),
						esc_url( admin_url( 'admin.php?page=' . Settings::MENU_SLUG ) )
					);
					?></p>
				</div>
			<?php endif; ?>

			<?php if ( $last && ! empty( $last['error'] ) ) : ?>
				<div class="notice notice-error"><p><strong><?php esc_html_e( 'Push failed:', 'beavermind' ); ?></strong> <?php echo esc_html( $last['error'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( $last && ! empty( $last['remote_post_id'] ) ) : ?>
				<div class="notice notice-success">
					<p>
						<?php
						$remote_edit = $last['remote_edit_url'] ?? '';
						$remote_bb   = $last['remote_bb_url']   ?? '';
						printf(
							wp_kses_post( __( 'Pushed local page #%1$d to staging as page #%2$d. <a href="%3$s" target="_blank">Edit on staging</a> · <a href="%4$s" target="_blank">edit with Beaver Builder</a>.', 'beavermind' ) ),
							(int) $last['local_post_id'],
							(int) $last['remote_post_id'],
							esc_url( $remote_edit ),
							esc_url( $remote_bb )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<h2 style="margin-top:1.5rem;"><?php esc_html_e( 'Generated pages (most recent first)', 'beavermind' ); ?></h2>
			<?php if ( empty( $pushable ) ) : ?>
				<p><em><?php esc_html_e( 'No BeaverMind-generated pages yet.', 'beavermind' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:1000px">
					<thead><tr>
						<th>#</th>
						<th><?php esc_html_e( 'Title', 'beavermind' ); ?></th>
						<th><?php esc_html_e( 'Generated', 'beavermind' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $pushable as $entry ) : ?>
							<tr>
								<td>#<?php echo (int) $entry['id']; ?></td>
								<td><?php echo esc_html( $entry['title'] ); ?></td>
								<td><?php echo esc_html( $entry['generated_at'] ); ?></td>
								<td>
									<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin:0;">
										<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
										<input type="hidden" name="post_id" value="<?php echo (int) $entry['id']; ?>" />
										<?php wp_nonce_field( self::ACTION ); ?>
										<button type="submit" class="button button-primary" <?php disabled( ! $is_configured ); ?>><?php esc_html_e( 'Push to staging', 'beavermind' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_submit(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( self::ACTION );

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$user_id = get_current_user_id();
		$store   = array( 'local_post_id' => $post_id );

		$plan_json = (string) get_post_meta( $post_id, '_beavermind_plan', true );
		if ( '' === $plan_json ) {
			$store['error'] = __( 'That post has no BeaverMind plan stored.', 'beavermind' );
			$this->stash_and_redirect( $user_id, $store );
		}
		$plan = json_decode( $plan_json, true );
		if ( ! is_array( $plan ) ) {
			$store['error'] = __( 'Stored plan is unparseable JSON.', 'beavermind' );
			$this->stash_and_redirect( $user_id, $store );
		}

		// Strip the local post_id from page meta — we want staging to insert
		// a new post, not target an ID that doesn't exist on the other side.
		unset( $plan['page']['post_id'] );

		$staging_url      = rtrim( (string) Plugin::instance()->get_option( 'staging_url', '' ), '/' );
		$staging_user     = (string) Plugin::instance()->get_option( 'staging_username', '' );
		$staging_password = (string) Plugin::instance()->get_option( 'staging_app_password', '' );

		if ( '' === $staging_url || '' === $staging_user || '' === $staging_password ) {
			$store['error'] = __( 'Staging credentials are not configured.', 'beavermind' );
			$this->stash_and_redirect( $user_id, $store );
		}

		$endpoint = $staging_url . '/wp-json/beavermind/v1/apply-plan';
		$resp = wp_remote_post( $endpoint, array(
			'timeout' => 60,
			'headers' => array(
				'Content-Type'  => 'application/json',
				// WP application passwords replace the user's regular password
				// in HTTP Basic auth. Spaces in the password are stripped by
				// WP — we stay tolerant.
				'Authorization' => 'Basic ' . base64_encode( $staging_user . ':' . str_replace( ' ', '', $staging_password ) ),
			),
			'body'    => wp_json_encode( array( 'plan' => $plan ) ),
		) );

		if ( is_wp_error( $resp ) ) {
			$store['error'] = 'HTTP error: ' . $resp->get_error_message();
			$this->stash_and_redirect( $user_id, $store );
		}
		$status = wp_remote_retrieve_response_code( $resp );
		$body   = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $status < 200 || $status >= 300 ) {
			$msg = is_array( $body ) && isset( $body['message'] ) ? $body['message'] : "HTTP $status";
			$store['error'] = "Staging returned $status: $msg";
			$this->stash_and_redirect( $user_id, $store );
		}

		$store['remote_post_id']  = (int) ( $body['post_id'] ?? 0 );
		$store['remote_edit_url'] = (string) ( $body['edit_url']    ?? '' );
		$store['remote_bb_url']   = (string) ( $body['bb_edit_url'] ?? '' );
		$this->stash_and_redirect( $user_id, $store );
	}

	/**
	 * @return array<int, array{id:int, title:string, generated_at:string}>
	 */
	private function list_pushable_pages(): array {
		$query = new \WP_Query( array(
			'post_type'      => 'any',
			'post_status'    => array( 'publish', 'draft', 'future', 'private', 'pending' ),
			'posts_per_page' => 30,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'meta_query'     => array(
				array( 'key' => '_beavermind_plan', 'compare' => 'EXISTS' ),
			),
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		$out = array();
		foreach ( $query->posts as $id ) {
			$out[] = array(
				'id'           => (int) $id,
				'title'        => get_the_title( $id ) ?: '(untitled)',
				'generated_at' => (string) get_post_meta( $id, '_beavermind_generated_at', true ),
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
