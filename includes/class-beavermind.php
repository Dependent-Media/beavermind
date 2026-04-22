<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	const OPTION_KEY = 'beavermind_options';

	private static ?Plugin $instance = null;

	public Settings $settings;
	public FragmentLibrary $fragments;
	public LayoutWriter $writer;
	public ClaudeClient $claude;
	public Planner $planner;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_dependencies();
		$this->settings  = new Settings();
		$this->fragments = new FragmentLibrary();
		$this->writer    = new LayoutWriter();
		$this->claude    = new ClaudeClient( $this->get_option( 'api_key', '' ) );
		$this->planner   = new Planner(
			$this->claude,
			$this->fragments,
			(string) $this->get_option( 'model', 'claude-opus-4-7' )
		);

		$this->settings->register();
		$this->fragments->register();

		// Bootstrap fragments — inline scaffolding before .dat library lands.
		InlineFragments::register( $this->fragments );

		require_once BEAVERMIND_DIR . 'includes/class-test-page-generator.php';
		( new TestPageGenerator( $this->fragments, $this->writer ) )->register();

		require_once BEAVERMIND_DIR . 'includes/class-prompt-generator.php';
		( new PromptGenerator( $this->planner, $this->writer, $this->fragments ) )->register();

		require_once BEAVERMIND_DIR . 'includes/class-clone-generator.php';
		( new CloneGenerator(
			$this->planner,
			$this->writer,
			$this->fragments,
			new SiteCloner()
		) )->register();

		require_once BEAVERMIND_DIR . 'includes/class-refine-generator.php';
		( new RefineGenerator( $this->planner, $this->writer, $this->fragments ) )->register();

		require_once BEAVERMIND_DIR . 'includes/class-paste-html-generator.php';
		( new PasteHTMLGenerator(
			$this->planner,
			$this->writer,
			$this->fragments,
			new SiteCloner()
		) )->register();

		require_once BEAVERMIND_DIR . 'includes/class-image-input-generator.php';
		( new ImageInputGenerator( $this->planner, $this->writer, $this->fragments ) )->register();

		require_once BEAVERMIND_DIR . 'includes/class-figma-fetcher.php';
		require_once BEAVERMIND_DIR . 'includes/class-figma-generator.php';
		( new FigmaGenerator( $this->planner, $this->writer, $this->fragments ) )->register();

		require_once BEAVERMIND_DIR . 'includes/class-multipage-generator.php';
		( new MultipageGenerator(
			$this->planner,
			$this->writer,
			$this->fragments,
			new SiteCloner()
		) )->register();

		require_once BEAVERMIND_DIR . 'includes/class-rest-api.php';
		( new RestAPI( $this->writer, $this->fragments, $this->planner ) )->register();

		require_once BEAVERMIND_DIR . 'includes/class-staging-pusher.php';
		( new StagingPusher() )->register();

		require_once BEAVERMIND_DIR . 'includes/class-docs-page.php';
		( new DocsPage() )->register();

		// Admin JS for the Enhance Prompt button + Quick Refine actions.
		// Loaded on every BeaverMind admin page (cheap; ~3KB), gated by a
		// page-slug check inside the enqueue callback.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_js' ) );
	}

	private function load_dependencies(): void {
		require_once BEAVERMIND_DIR . 'includes/class-settings.php';
		require_once BEAVERMIND_DIR . 'includes/class-fragment-library.php';
		require_once BEAVERMIND_DIR . 'includes/class-layout-writer.php';
		require_once BEAVERMIND_DIR . 'includes/class-claude-client.php';
		require_once BEAVERMIND_DIR . 'includes/class-site-cloner.php';
		require_once BEAVERMIND_DIR . 'includes/class-planner.php';
		require_once BEAVERMIND_DIR . 'includes/class-inline-fragments.php';
		require_once BEAVERMIND_DIR . 'includes/class-plan-runner.php';
	}

	public function get_option( string $key, $default = null ) {
		$opts = get_option( self::OPTION_KEY, array() );
		return is_array( $opts ) && array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
	}

	/**
	 * Enqueue the small admin JS only on BeaverMind submenu pages.
	 * Adds the REST URL + nonce as a localized config object so the
	 * client doesn't need to hardcode either.
	 */
	public function enqueue_admin_js( string $hook_suffix ): void {
		// All BeaverMind admin pages have a `?page=beavermind…` slug. The
		// hook suffix is always like `beavermind_page_beavermind-clone` (or
		// `toplevel_page_beavermind` for the parent), so a substring match
		// is enough.
		if ( false === strpos( $hook_suffix, 'beavermind' ) ) {
			return;
		}
		$handle = 'beavermind-admin';
		wp_enqueue_script(
			$handle,
			BEAVERMIND_URL . 'admin/js/admin.js',
			array(),
			BEAVERMIND_VERSION,
			true
		);
		wp_localize_script(
			$handle,
			'BeaverMindAdmin',
			array(
				'restRoot'  => esc_url_raw( rest_url( RestAPI::NAMESPACE_VERSION . '/' ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	public static function activate(): void {
		if ( ! get_option( self::OPTION_KEY ) ) {
			add_option( self::OPTION_KEY, array(
				'api_key' => '',
				'model'   => 'claude-opus-4-7',
			) );
		}
	}

	public static function deactivate(): void {
		// Intentionally leave options in place so re-activation preserves config.
	}
}
