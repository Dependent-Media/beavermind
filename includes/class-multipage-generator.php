<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI: clone many URLs in one go.
 *
 * Accepts either a sitemap.xml URL (parsed into the first MAX_URLS <loc>
 * entries) or a free-form list of URLs (one per line). Each URL runs through
 * the same Clone from URL pipeline — fetch → extract → plan → write — and
 * lands as a draft. Useful for porting a multi-page reference site, or
 * generating a set of pages with a consistent brand at once.
 *
 * Sequential, capped at MAX_URLS to avoid holding admin-post.php hostage
 * for several minutes.
 */
class MultipageGenerator {

	const SLUG      = 'beavermind-multipage';
	const ACTION    = 'beavermind_multipage';
	const TRANSIENT = 'beavermind_last_multipage_';
	const MAX_URLS  = 10;

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
			__( 'Multi-page', 'beavermind' ),
			__( 'Multi-page', 'beavermind' ),
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

		$sitemap_default = (string) ( $last['sitemap'] ?? '' );
		$urls_default    = (string) ( $last['urls']    ?? '' );
		$hint_default    = (string) ( $last['hint']    ?? '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BeaverMind — Multi-page', 'beavermind' ); ?></h1>
			<p><?php
			printf(
				/* translators: %d: max URLs per run */
				esc_html__( 'Clone up to %d pages in one go. Provide a sitemap URL OR a list of URLs (one per line). Each URL runs through the standard clone pipeline and lands as a draft.', 'beavermind' ),
				(int) self::MAX_URLS
			);
			?></p>

			<?php if ( $last && ! empty( $last['error'] ) ) : ?>
				<div class="notice notice-error"><p><strong><?php esc_html_e( 'Failed:', 'beavermind' ); ?></strong> <?php echo esc_html( $last['error'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( $last && ! empty( $last['results'] ) ) : ?>
				<div class="notice notice-success">
					<p><strong><?php
					printf(
						/* translators: %d: count of pages generated */
						esc_html( _n( 'Generated %d page:', 'Generated %d pages:', count( $last['results'] ), 'beavermind' ) ),
						count( $last['results'] )
					);
					?></strong></p>
					<ol>
						<?php foreach ( $last['results'] as $r ) : ?>
							<li>
								<?php if ( ! empty( $r['post_id'] ) ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( (int) $r['post_id'] ) ); ?>" target="_blank">page #<?php echo (int) $r['post_id']; ?></a>
									—
									<code style="font-size:11px;"><?php echo esc_html( $r['url'] ); ?></code>
									—
									<a href="<?php echo esc_url( add_query_arg( 'fl_builder', '', get_permalink( (int) $r['post_id'] ) ) ); ?>" target="_blank"><?php esc_html_e( 'edit with BB', 'beavermind' ); ?></a>
								<?php else : ?>
									<span style="color:#b91c1c;">FAILED:</span>
									<code style="font-size:11px;"><?php echo esc_html( $r['url'] ); ?></code>
									— <?php echo esc_html( $r['error'] ?? 'unknown' ); ?>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ol>
				</div>
			<?php endif; ?>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin-top:1.5rem;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="bm_sitemap"><?php esc_html_e( 'Sitemap URL', 'beavermind' ); ?></label></th>
							<td>
								<input type="url" id="bm_sitemap" name="sitemap" value="<?php echo esc_attr( $sitemap_default ); ?>" class="regular-text code" placeholder="https://example.com/sitemap.xml" />
								<p class="description"><?php
								printf(
									/* translators: %d: max URLs */
									esc_html__( 'A sitemap.xml URL. We fetch it and use the first %d <loc> entries.', 'beavermind' ),
									(int) self::MAX_URLS
								);
								?></p>
							</td>
						</tr>
						<tr>
							<th scope="row" style="text-align:center;"><em><?php esc_html_e( '— or —', 'beavermind' ); ?></em></th>
							<td></td>
						</tr>
						<tr>
							<th scope="row"><label for="bm_urls"><?php esc_html_e( 'URL list', 'beavermind' ); ?></label></th>
							<td>
								<textarea id="bm_urls" name="urls" rows="6" cols="80" class="large-text code" placeholder="https://example.com/page-1&#10;https://example.com/page-2"><?php echo esc_textarea( $urls_default ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One URL per line. Used only if the sitemap field is empty.', 'beavermind' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bm_hint"><?php esc_html_e( 'Design hint (applied to all)', 'beavermind' ); ?></label></th>
							<td>
								<input type="text" id="bm_hint" name="hint" value="<?php echo esc_attr( $hint_default ); ?>" class="regular-text" placeholder="e.g. modern SaaS, calm neutrals" />
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

				<?php submit_button( __( 'Generate Pages', 'beavermind' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_submit(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( self::ACTION );

		$sitemap = isset( $_POST['sitemap'] ) ? esc_url_raw( wp_unslash( (string) $_POST['sitemap'] ) ) : '';
		$urls_raw = isset( $_POST['urls'] ) ? wp_unslash( (string) $_POST['urls'] ) : '';
		$hint   = isset( $_POST['hint'] ) ? trim( wp_unslash( (string) $_POST['hint'] ) ) : '';
		$status = isset( $_POST['post_status'] ) && in_array( $_POST['post_status'], array( 'draft', 'publish' ), true )
			? (string) $_POST['post_status']
			: 'draft';

		$user_id = get_current_user_id();
		$store = array( 'sitemap' => $sitemap, 'urls' => $urls_raw, 'hint' => $hint );

		// Resolve URLs: sitemap wins if both are provided.
		$urls = array();
		if ( '' !== $sitemap ) {
			$urls = $this->urls_from_sitemap( $sitemap );
			if ( is_wp_error( $urls ) ) {
				$store['error'] = 'Sitemap fetch failed: ' . $urls->get_error_message();
				$this->stash_and_redirect( $user_id, $store );
			}
		} else {
			$lines = preg_split( '/\r?\n/', $urls_raw );
			foreach ( (array) $lines as $line ) {
				$line = trim( (string) $line );
				if ( '' !== $line && wp_http_validate_url( $line ) ) {
					$urls[] = $line;
				}
			}
		}
		$urls = array_values( array_unique( $urls ) );
		if ( empty( $urls ) ) {
			$store['error'] = __( 'No valid URLs found. Provide a sitemap or a URL list.', 'beavermind' );
			$this->stash_and_redirect( $user_id, $store );
		}
		$urls = array_slice( $urls, 0, self::MAX_URLS );

		$results = array();
		foreach ( $urls as $url ) {
			$ref = $this->cloner->fetch( $url );
			if ( is_wp_error( $ref ) ) {
				$results[] = array( 'url' => $url, 'error' => 'fetch: ' . $ref->get_error_message() );
				continue;
			}
			$brief = '' !== $hint
				? "Redesign this page using the BeaverMind fragment library. Design direction: $hint. Preserve the offering and CTAs; rewrite copy to be sharper."
				: "Redesign this page using the BeaverMind fragment library. Preserve the offering and CTAs; rewrite copy to be sharper.";
			$plan = $this->planner->plan( $brief, array( 'post_status' => $status ), $ref );
			if ( is_wp_error( $plan ) ) {
				$results[] = array( 'url' => $url, 'error' => 'plan: ' . $plan->get_error_message() );
				continue;
			}
			$post_id = $this->writer->apply_plan( $plan, $this->fragments, array(
				'image_filler' => Plugin::instance()->image_filler,
				'brief'        => trim( (string) ( $ref['brand']['site_name'] ?? '' ) . ' ' . $hint ) ?: $brief,
			) );
			if ( is_wp_error( $post_id ) ) {
				$results[] = array( 'url' => $url, 'error' => 'write: ' . $post_id->get_error_message() );
				continue;
			}
			$results[] = array( 'url' => $url, 'post_id' => (int) $post_id );
		}

		$store['results'] = $results;
		$this->stash_and_redirect( $user_id, $store );
	}

	/**
	 * Fetch a sitemap.xml and return the first MAX_URLS <loc> values.
	 * Handles both standard sitemaps and sitemap-index files (recurses one level).
	 *
	 * @return array<int, string>|\WP_Error
	 */
	private function urls_from_sitemap( string $sitemap_url ) {
		$resp = wp_remote_get( $sitemap_url, array(
			'timeout'    => 20,
			'user-agent' => 'BeaverMind/0.1 (+https://dependentmedia.com/beavermind)',
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$status = wp_remote_retrieve_response_code( $resp );
		if ( $status < 200 || $status >= 300 ) {
			return new \WP_Error( 'beavermind_sitemap_http', "Sitemap returned HTTP $status." );
		}
		$xml = simplexml_load_string( wp_remote_retrieve_body( $resp ) );
		if ( false === $xml ) {
			return new \WP_Error( 'beavermind_sitemap_parse', 'Sitemap XML is malformed.' );
		}
		$out = array();

		// Sitemap index (sitemapindex/sitemap/loc) → recurse into the first
		// child sitemap to find URLs.
		if ( isset( $xml->sitemap[0]->loc ) ) {
			$child = (string) $xml->sitemap[0]->loc;
			$nested = $this->urls_from_sitemap( $child );
			return is_wp_error( $nested ) ? $nested : $nested;
		}

		// Regular urlset (urlset/url/loc).
		foreach ( $xml->url ?? array() as $url ) {
			$loc = (string) ( $url->loc ?? '' );
			if ( '' !== $loc && wp_http_validate_url( $loc ) ) {
				$out[] = $loc;
				if ( count( $out ) >= self::MAX_URLS ) {
					break;
				}
			}
		}
		return $out;
	}

	private function stash_and_redirect( int $user_id, array $store ): void {
		set_transient( self::TRANSIENT . $user_id, $store, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}
