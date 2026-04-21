<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads BeaverMind's curated fragment library.
 *
 * Two complementary sources, merged transparently:
 *
 *   1. INLINE — registered at runtime via register_inline(), defined in PHP.
 *      Used by InlineFragments for the bootstrap library; designers can also
 *      hand-author here.
 *   2. .DAT — library/*.dat files (PHP-serialized in BB's template format).
 *      Each .dat entry's `name` is slugified into the fragment ID. Metadata
 *      (slots, theme_bindings) lives in library/fragments.json keyed by
 *      that ID. This is how designers extend the library: design a row in
 *      the BB editor, export the saved template via Tools → Template Data
 *      Exporter, drop the .dat in library/, write the metadata in
 *      fragments.json.
 *
 * On ID collision, .dat fragments override inline ones — designers can
 * supersede a built-in fragment without modifying our PHP.
 *
 * BB's FLBuilder::register_templates() registers .dat templates with BB's
 * template panel; we DON'T rely on that path for our own lookups, because
 * (a) it's slow (creates fl-builder-template posts), (b) it exposes our
 * fragments in the BB UI which is noise. We unserialize the .dat directly
 * and treat each entry as a portable node tree.
 */
class FragmentLibrary {

	private string $library_dir;
	private string $meta_path;

	/** @var array<string, array> */
	private array $inline_fragments = array();

	/** @var array<string, array<string, \stdClass>>|null lazy: id => node array */
	private ?array $dat_fragments_cache = null;

	public function __construct() {
		$this->library_dir = BEAVERMIND_DIR . 'library';
		$this->meta_path   = $this->library_dir . '/fragments.json';
	}

	public function register(): void {
		// No bootstrap hook needed — .dat loading is lazy. Method retained
		// for back-compat with existing callers.
	}

	/**
	 * Register a fragment defined as a raw node array (in-PHP).
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

		// .dat-loaded entries override inline by ID. Metadata for them lives
		// in fragments.json (slots + theme_bindings).
		$dat = $this->dat_fragments();
		if ( ! empty( $dat ) ) {
			$json_meta = $this->json_metadata();
			foreach ( $dat as $id => $_nodes ) {
				$meta = $json_meta[ $id ] ?? array(
					'name'     => $id,
					'category' => 'uncategorized',
				);
				$catalog[ $id ] = array(
					'meta'   => $meta,
					'source' => 'dat',
				);
			}
		}

		return $catalog;
	}

	/**
	 * Resolve a fragment ID to its raw node array.
	 *
	 * @return array<string, \stdClass>|null
	 */
	public function get_nodes( string $id ): ?array {
		// .dat takes precedence on collision (matches catalog() behaviour).
		$dat = $this->dat_fragments();
		if ( isset( $dat[ $id ] ) ) {
			return $dat[ $id ];
		}
		if ( isset( $this->inline_fragments[ $id ] ) ) {
			return $this->inline_fragments[ $id ]['nodes'];
		}
		return null;
	}

	/**
	 * Unserialize every .dat in library/ and flatten into id => node-array.
	 * Cached for the request.
	 *
	 * @return array<string, array<string, \stdClass>>
	 */
	private function dat_fragments(): array {
		if ( null !== $this->dat_fragments_cache ) {
			return $this->dat_fragments_cache;
		}
		$out = array();
		foreach ( glob( $this->library_dir . '/*.dat' ) ?: array() as $path ) {
			$entries = self::read_dat_file( $path );
			foreach ( $entries as $id => $nodes ) {
				$out[ $id ] = $nodes;
			}
		}
		$this->dat_fragments_cache = $out;
		return $out;
	}

	/**
	 * Read a single .dat file. Format: serialized array keyed by template type:
	 *   ['layout' => [...], 'row' => [...], 'module' => [...]]
	 * Each entry has {name, type, global, nodes, settings}. We treat each
	 * entry's name as the fragment ID (slugified) and return id => nodes.
	 *
	 * @return array<string, array<string, \stdClass>>
	 */
	public static function read_dat_file( string $path ): array {
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			return array();
		}
		$data = @unserialize( $raw );
		if ( ! is_array( $data ) ) {
			return array();
		}
		$out = array();
		foreach ( array( 'layout', 'row', 'module' ) as $type ) {
			$entries = (array) ( $data[ $type ] ?? array() );
			foreach ( $entries as $entry ) {
				if ( ! is_object( $entry ) ) {
					continue;
				}
				$name = (string) ( $entry->name ?? '' );
				if ( '' === $name ) {
					continue;
				}
				$id = sanitize_title( $name );
				if ( '' === $id ) {
					continue;
				}
				$nodes = $entry->nodes ?? null;
				// BB sometimes double-serializes nodes inside the cache to save
				// memory — see FLBuilderModel::get_templates(). Detect and unwrap.
				if ( is_string( $nodes ) ) {
					$maybe = @unserialize( $nodes );
					if ( is_array( $maybe ) ) {
						$nodes = $maybe;
					}
				}
				if ( ! is_array( $nodes ) ) {
					continue;
				}
				$out[ $id ] = $nodes;
			}
		}
		return $out;
	}

	/**
	 * Read fragments.json metadata sidecar keyed by fragment ID.
	 *
	 * @return array<string, array>
	 */
	private function json_metadata(): array {
		if ( ! file_exists( $this->meta_path ) ) {
			return array();
		}
		$json = json_decode( (string) file_get_contents( $this->meta_path ), true );
		return is_array( $json ) ? $json : array();
	}
}
