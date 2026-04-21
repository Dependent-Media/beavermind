<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads BeaverMind's curated fragment library.
 *
 * Two complementary sources:
 *   1. A .dat file at library/fragments.dat — the canonical, BB-native binary that
 *      ships fragments as saved templates. Registered via FLBuilder::register_templates().
 *   2. A JSON metadata index at library/fragments.json — what Claude sees when planning.
 *      Maps fragment_id -> { name, category, description, content_slots[] }.
 *
 * Until the .dat exists, fragments can also be defined inline via register_inline()
 * to bootstrap the test loop.
 */
class FragmentLibrary {

	private string $dat_path;
	private string $meta_path;

	/** @var array<string, array> */
	private array $inline_fragments = array();

	public function __construct() {
		$this->dat_path  = BEAVERMIND_DIR . 'library/fragments.dat';
		$this->meta_path = BEAVERMIND_DIR . 'library/fragments.json';
	}

	public function register(): void {
		add_action( 'init', array( $this, 'register_dat' ), 20 );
	}

	public function register_dat(): void {
		if ( ! file_exists( $this->dat_path ) ) {
			return;
		}
		if ( ! class_exists( 'FLBuilder' ) || ! method_exists( 'FLBuilder', 'register_templates' ) ) {
			return;
		}
		\FLBuilder::register_templates( $this->dat_path );
	}

	/**
	 * Register a fragment defined as a raw node array (for bootstrap / test only).
	 * Production fragments should live in fragments.dat.
	 */
	public function register_inline( string $id, array $meta, array $nodes ): void {
		$this->inline_fragments[ $id ] = array(
			'meta'  => $meta,
			'nodes' => $nodes,
		);
	}

	/**
	 * @return array<string, array{meta: array, source: string}>
	 */
	public function catalog(): array {
		$catalog = array();

		foreach ( $this->inline_fragments as $id => $f ) {
			$catalog[ $id ] = array(
				'meta'   => $f['meta'],
				'source' => 'inline',
			);
		}

		if ( file_exists( $this->meta_path ) ) {
			$json = json_decode( (string) file_get_contents( $this->meta_path ), true );
			if ( is_array( $json ) ) {
				foreach ( $json as $id => $meta ) {
					$catalog[ $id ] = array(
						'meta'   => $meta,
						'source' => 'dat',
					);
				}
			}
		}

		return $catalog;
	}

	/**
	 * Resolve a fragment ID to its raw node array (the data shape BB stores in
	 * _fl_builder_data: associative array of stdClass nodes keyed by node ID).
	 *
	 * @return array<string, \stdClass>|null
	 */
	public function get_nodes( string $id ): ?array {
		if ( isset( $this->inline_fragments[ $id ] ) ) {
			return $this->inline_fragments[ $id ]['nodes'];
		}

		// Fragments shipped via .dat live in BB's saved-template post type after
		// register_templates() has imported them. Look them up by template slug.
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			return null;
		}

		$template_post = get_page_by_path( $id, OBJECT, 'fl-builder-template' );
		if ( ! $template_post ) {
			return null;
		}

		$nodes = \FLBuilderModel::get_layout_data( 'published', $template_post->ID );
		return is_array( $nodes ) ? $nodes : null;
	}
}
