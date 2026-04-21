<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-only tool for exercising the full BeaverMind write loop against a
 * hardcoded "hero" fragment. No AI involved — purely validates that the
 * LayoutWriter + FragmentLibrary + BB integration produces a rendered page.
 *
 * This is scaffolding that will be replaced once the Claude-driven planner
 * lands in Phase 2.
 */
class TestPageGenerator {

	const SLUG   = 'beavermind-test';
	const ACTION = 'beavermind_generate_test_page';

	private FragmentLibrary $library;
	private LayoutWriter $writer;

	public function __construct( FragmentLibrary $library, LayoutWriter $writer ) {
		$this->library = $library;
		$this->writer  = $writer;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_generate' ) );
	}

	public function add_submenu(): void {
		add_submenu_page(
			Settings::MENU_SLUG,
			__( 'Test Page Generator', 'beavermind' ),
			__( 'Test Generator', 'beavermind' ),
			'edit_pages',
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}

		$catalog = $this->library->catalog();
		$generated_id = isset( $_GET['generated'] ) ? (int) $_GET['generated'] : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BeaverMind Test Generator', 'beavermind' ); ?></h1>
			<p><?php esc_html_e( 'Creates a new draft page using a hardcoded fragment + sample content, to validate that the BeaverMind write loop is working end-to-end. No Claude API call is made.', 'beavermind' ); ?></p>

			<?php if ( $generated_id ) : ?>
				<div class="notice notice-success"><p>
					<?php
					printf(
						wp_kses_post( __( 'Generated page <a href="%1$s" target="_blank">#%2$d</a> — <a href="%3$s" target="_blank">edit with Beaver Builder</a>.', 'beavermind' ) ),
						esc_url( get_edit_post_link( $generated_id ) ),
						(int) $generated_id,
						esc_url( add_query_arg( 'fl_builder', '', get_permalink( $generated_id ) ) )
					);
					?>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Available fragments', 'beavermind' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr>
					<th><?php esc_html_e( 'ID', 'beavermind' ); ?></th>
					<th><?php esc_html_e( 'Name', 'beavermind' ); ?></th>
					<th><?php esc_html_e( 'Category', 'beavermind' ); ?></th>
					<th><?php esc_html_e( 'Source', 'beavermind' ); ?></th>
					<th><?php esc_html_e( 'Slots', 'beavermind' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $catalog as $id => $entry ) : ?>
						<tr>
							<td><code><?php echo esc_html( $id ); ?></code></td>
							<td><?php echo esc_html( $entry['meta']['name'] ?? $id ); ?></td>
							<td><?php echo esc_html( $entry['meta']['category'] ?? '' ); ?></td>
							<td><?php echo esc_html( $entry['source'] ); ?></td>
							<td><?php echo esc_html( implode( ', ', array_keys( (array) ( $entry['meta']['slots'] ?? array() ) ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $catalog ) : ?>
						<tr><td colspan="5"><em><?php esc_html_e( 'No fragments registered.', 'beavermind' ); ?></em></td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Generate', 'beavermind' ); ?></h2>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php submit_button( __( 'Generate Test Page', 'beavermind' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_generate(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( 'forbidden', 403 );
		}
		check_admin_referer( self::ACTION );

		$plan = array(
			'page' => array(
				'title'       => 'BeaverMind Test Page ' . gmdate( 'Y-m-d H:i:s' ),
				'post_type'   => 'page',
				'post_status' => 'draft',
			),
			'fragments' => array(
				array(
					'id' => 'hero-centered',
					'slots' => array(
						'headline'  => 'Hello from BeaverMind',
						'subhead'   => 'This page was assembled by the LayoutWriter from a hardcoded fragment — no AI involved yet.',
						'cta_label' => 'Learn more',
						'cta_url'   => home_url( '/' ),
					),
				),
			),
		);

		$result = $this->writer->apply_plan( $plan, $this->library );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => self::SLUG, 'generated' => (int) $result ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

}
