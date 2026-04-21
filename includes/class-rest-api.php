<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST surface for cross-site BeaverMind operations.
 *
 * One route today: POST /wp-json/beavermind/v1/apply-plan
 * Receives a previously-generated plan JSON, runs LayoutWriter::apply_plan
 * locally, returns the new post_id and edit URLs. Authenticated via standard
 * WP application passwords.
 *
 * Why a custom endpoint instead of /wp/v2/pages with raw _fl_builder_data
 * meta? Because BB stores nodes as PHP-serialized arrays in postmeta. Round-
 * tripping that across sites is fragile (PHP version differences, namespaces,
 * BB plugin version drift). The plan JSON is portable; LayoutWriter on the
 * receiving site rebuilds the serialized blob using its own fragment library,
 * which means staging can even have different fragments and the page still
 * lands.
 */
class RestAPI {

	const NAMESPACE_VERSION = 'beavermind/v1';

	private LayoutWriter $writer;
	private FragmentLibrary $fragments;

	public function __construct( LayoutWriter $writer, FragmentLibrary $fragments ) {
		$this->writer    = $writer;
		$this->fragments = $fragments;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_VERSION,
			'/apply-plan',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'apply_plan' ),
				'permission_callback' => array( $this, 'can_edit_pages' ),
				'args'                => array(
					'plan' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_VERSION,
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					return array(
						'plugin'    => 'beavermind',
						'version'   => defined( 'BEAVERMIND_VERSION' ) ? BEAVERMIND_VERSION : '?',
						'fragments' => array_keys( $this->fragments->catalog() ),
					);
				},
				'permission_callback' => array( $this, 'can_edit_pages' ),
			)
		);
	}

	public function can_edit_pages(): bool {
		return current_user_can( 'edit_pages' );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function apply_plan( \WP_REST_Request $request ) {
		$plan = $request->get_param( 'plan' );
		if ( ! is_array( $plan ) ) {
			return new \WP_Error(
				'beavermind_invalid_plan',
				'plan must be an object/array.',
				array( 'status' => 400 )
			);
		}

		$result = $this->writer->apply_plan( $plan, $this->fragments );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 500 ) );
			return $result;
		}

		$post_id = (int) $result;
		return rest_ensure_response( array(
			'post_id'      => $post_id,
			'edit_url'     => get_edit_post_link( $post_id, 'raw' ),
			'view_url'     => get_permalink( $post_id ),
			'bb_edit_url'  => add_query_arg( 'fl_builder', '', get_permalink( $post_id ) ),
			'fragment_ids' => array_column( (array) ( $plan['fragments'] ?? array() ), 'id' ),
		) );
	}
}
