<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes a BeaverMind plan to a WordPress post as Beaver Builder layout data.
 *
 * Plan shape:
 *   [
 *     'page'      => [ 'title' => string, 'post_type' => string, 'post_status' => string, 'post_id' => ?int ],
 *     'fragments' => [
 *       [ 'id' => 'hero-centered', 'slots' => [ 'headline' => '...', 'cta_url' => '...' ] ],
 *       ...
 *     ],
 *   ]
 *
 * Each fragment is cloned (node IDs re-keyed for uniqueness) and appended to the
 * target post's layout. Slot overrides are mapped via the fragment metadata's
 * "slots" definition, which points at original node IDs + the settings field to set.
 */
class LayoutWriter {

	/**
	 * Apply a plan and return the resulting post ID (or WP_Error).
	 *
	 * @return int|\WP_Error
	 */
	public function apply_plan( array $plan, FragmentLibrary $library ) {
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			return new \WP_Error( 'beavermind_no_bb', 'Beaver Builder is not active.' );
		}

		$page = isset( $plan['page'] ) && is_array( $plan['page'] ) ? $plan['page'] : array();
		$post_id = isset( $page['post_id'] ) ? (int) $page['post_id'] : 0;

		if ( ! $post_id ) {
			$post_id = wp_insert_post( array(
				'post_title'   => isset( $page['title'] ) ? (string) $page['title'] : 'BeaverMind Generated Page',
				'post_type'    => isset( $page['post_type'] ) ? (string) $page['post_type'] : 'page',
				'post_status'  => isset( $page['post_status'] ) ? (string) $page['post_status'] : 'draft',
				'post_content' => '',
			), true );

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
		}

		$catalog = $library->catalog();
		$merged = array();
		$position = 0;

		foreach ( (array) ( $plan['fragments'] ?? array() ) as $entry ) {
			$id = isset( $entry['id'] ) ? (string) $entry['id'] : '';
			if ( '' === $id || ! isset( $catalog[ $id ] ) ) {
				continue;
			}

			$nodes = $library->get_nodes( $id );
			if ( ! $nodes ) {
				continue;
			}

			$slots_meta = $catalog[ $id ]['meta']['slots'] ?? array();
			$slot_overrides = isset( $entry['slots'] ) && is_array( $entry['slots'] ) ? $entry['slots'] : array();

			[ $cloned, $id_map ] = $this->clone_nodes( $nodes );
			$this->apply_slot_overrides( $cloned, $id_map, $slots_meta, $slot_overrides );

			// Append top-level rows in order to the merged layout.
			foreach ( $cloned as $node_id => $node ) {
				if ( null === $node->parent ) {
					$node->position = $position++;
				}
				$merged[ $node_id ] = $node;
			}
		}

		\FLBuilderModel::update_layout_data( $merged, 'published', $post_id );
		\FLBuilderModel::update_layout_data( $merged, 'draft', $post_id );
		\FLBuilderModel::update_layout_settings( new \stdClass(), 'published', $post_id );
		update_post_meta( $post_id, '_fl_builder_enabled', true );

		return $post_id;
	}

	/**
	 * Deep-clone a fragment's nodes with fresh unique node IDs.
	 * Returns [cloned_nodes, old_id => new_id map].
	 *
	 * @param array<string, \stdClass> $nodes
	 * @return array{0: array<string, \stdClass>, 1: array<string, string>}
	 */
	private function clone_nodes( array $nodes ): array {
		$id_map = array();
		foreach ( $nodes as $old_id => $_node ) {
			$id_map[ $old_id ] = $this->generate_node_id();
		}

		$cloned = array();
		foreach ( $nodes as $old_id => $node ) {
			$copy = $this->deep_clone( $node );
			$copy->node = $id_map[ $old_id ];
			if ( ! empty( $copy->parent ) && isset( $id_map[ $copy->parent ] ) ) {
				$copy->parent = $id_map[ $copy->parent ];
			}
			$cloned[ $id_map[ $old_id ] ] = $copy;
		}
		return array( $cloned, $id_map );
	}

	private function deep_clone( $value ) {
		if ( is_object( $value ) ) {
			return unserialize( serialize( $value ) );
		}
		return $value;
	}

	private function generate_node_id(): string {
		if ( class_exists( 'FLBuilderModel' ) && method_exists( 'FLBuilderModel', 'generate_node_id' ) ) {
			return \FLBuilderModel::generate_node_id();
		}
		return uniqid();
	}

	/**
	 * @param array<string, \stdClass> $cloned
	 * @param array<string, string>    $id_map      old_id => new_id
	 * @param array<string, array>     $slots_meta  slot_name => { node: old_id, field: settings_field }
	 * @param array<string, mixed>     $overrides   slot_name => value
	 */
	private function apply_slot_overrides( array &$cloned, array $id_map, array $slots_meta, array $overrides ): void {
		foreach ( $overrides as $slot_name => $value ) {
			if ( ! isset( $slots_meta[ $slot_name ] ) ) {
				continue;
			}
			$slot = $slots_meta[ $slot_name ];
			$old_node_id = $slot['node'] ?? null;
			$field       = $slot['field'] ?? null;
			if ( ! $old_node_id || ! $field || ! isset( $id_map[ $old_node_id ] ) ) {
				continue;
			}
			$new_node_id = $id_map[ $old_node_id ];
			if ( ! isset( $cloned[ $new_node_id ] ) ) {
				continue;
			}
			$node = $cloned[ $new_node_id ];
			if ( ! is_object( $node->settings ) ) {
				$node->settings = new \stdClass();
			}
			$node->settings->{$field} = $value;
		}
	}
}
