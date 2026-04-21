<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap fragment library, defined inline as PHP arrays.
 *
 * These are scaffolding — they exercise the LayoutWriter and give Claude a
 * starting palette before designers contribute hand-crafted .dat fragments via
 * the Beaver Builder editor. All fragments use BB core modules (heading,
 * rich-text, button) so they render reliably without depending on UABB/PP
 * module-specific field names.
 *
 * To add a fragment: append to all_fragments() with a unique ID, metadata
 * (name, category, description, slots), and a node array. Each slot maps a
 * human-friendly name (e.g. "headline") to a (node_id, settings_field) pair.
 */
class InlineFragments {

	public static function register( FragmentLibrary $library ): void {
		foreach ( self::all_fragments() as $id => $fragment ) {
			$library->register_inline( $id, $fragment['meta'], $fragment['nodes'] );
		}
	}

	/**
	 * @return array<string, array{meta: array, nodes: array<string, \stdClass>}>
	 */
	private static function all_fragments(): array {
		return array(
			'hero-centered'    => self::hero_centered(),
			'feature-grid-3col' => self::feature_grid_3col(),
			'cta-banner'       => self::cta_banner(),
			'two-col-content'  => self::two_col_content(),
		);
	}

	// ---------- Fragment definitions ----------

	private static function hero_centered(): array {
		$row    = 'bm-herocnt-row';
		$grp    = 'bm-herocnt-grp';
		$col    = 'bm-herocnt-col';
		$head   = 'bm-herocnt-head';
		$body   = 'bm-herocnt-body';
		$button = 'bm-herocnt-btn';

		$nodes = array(
			$row    => self::row_node( $row, null, 0, array(
				'width'             => 'fixed',
				'content_width'     => 'fixed',
				'content_alignment' => 'center',
				'padding_top'       => 80,
				'padding_bottom'    => 80,
				'padding_left'      => 20,
				'padding_right'     => 20,
				'bg_type'           => 'none',
			) ),
			$grp    => self::group_node( $grp, $row, 0 ),
			$col    => self::col_node( $col, $grp, 0, array( 'size' => 100, 'content_alignment' => 'center' ) ),
			$head   => self::module_node( $head, $col, 0, 'heading', array(
				'heading'  => 'Placeholder Headline',
				'tag'      => 'h1',
				'alignment' => 'center',
			) ),
			$body   => self::module_node( $body, $col, 1, 'rich-text', array(
				'text' => '<p style="text-align:center;">Placeholder subhead copy.</p>',
			) ),
			$button => self::module_node( $button, $col, 2, 'button', array(
				'text'  => 'Placeholder CTA',
				'link'  => '#',
				'align' => 'center',
				'style' => 'flat',
			) ),
		);

		$meta = array(
			'name'        => 'Centered Hero',
			'category'    => 'hero',
			'description' => 'Large centered headline, subhead paragraph, and a single CTA button. Use for the top of a landing page.',
			'slots'       => array(
				'headline'  => array( 'node' => $head,   'field' => 'heading' ),
				'subhead'   => array( 'node' => $body,   'field' => 'text' ),
				'cta_label' => array( 'node' => $button, 'field' => 'text' ),
				'cta_url'   => array( 'node' => $button, 'field' => 'link' ),
			),
		);

		return array( 'meta' => $meta, 'nodes' => $nodes );
	}

	private static function feature_grid_3col(): array {
		$row = 'bm-feat3-row';
		$grp = 'bm-feat3-grp';

		$nodes = array(
			$row => self::row_node( $row, null, 0, array(
				'width'             => 'fixed',
				'content_width'     => 'fixed',
				'padding_top'       => 60,
				'padding_bottom'    => 60,
				'padding_left'      => 20,
				'padding_right'     => 20,
			) ),
			$grp => self::group_node( $grp, $row, 0 ),
		);

		// Column intro: optional section heading above the 3 columns. Renders
		// in its own column-group so the 3-up grid stays clean.
		$intro_grp = 'bm-feat3-intro-grp';
		$intro_col = 'bm-feat3-intro-col';
		$intro_h   = 'bm-feat3-intro-h';
		$nodes[ $intro_grp ] = self::group_node( $intro_grp, $row, 0 );
		$nodes[ $intro_col ] = self::col_node( $intro_col, $intro_grp, 0, array( 'size' => 100, 'content_alignment' => 'center' ) );
		$nodes[ $intro_h ]   = self::module_node( $intro_h, $intro_col, 0, 'heading', array(
			'heading'   => 'Section Heading',
			'tag'       => 'h2',
			'alignment' => 'center',
		) );

		// Reset the second group's position to come after the intro.
		$nodes[ $grp ]->position = 1;

		$slots = array(
			'section_heading' => array( 'node' => $intro_h, 'field' => 'heading' ),
		);

		// Three feature columns.
		foreach ( array( 1, 2, 3 ) as $i ) {
			$col   = "bm-feat3-col-$i";
			$head  = "bm-feat3-head-$i";
			$body  = "bm-feat3-body-$i";
			$nodes[ $col ]  = self::col_node( $col, $grp, $i - 1, array(
				'size'              => 33.33,
				'content_alignment' => 'center',
			) );
			$nodes[ $head ] = self::module_node( $head, $col, 0, 'heading', array(
				'heading'   => "Feature $i",
				'tag'       => 'h3',
				'alignment' => 'center',
			) );
			$nodes[ $body ] = self::module_node( $body, $col, 1, 'rich-text', array(
				'text' => "<p style=\"text-align:center;\">Description of feature $i.</p>",
			) );
			$slots[ "feature_{$i}_title" ] = array( 'node' => $head, 'field' => 'heading' );
			$slots[ "feature_{$i}_body"  ] = array( 'node' => $body, 'field' => 'text' );
		}

		$meta = array(
			'name'        => '3-Column Feature Grid',
			'category'    => 'features',
			'description' => 'Section heading above three equal feature columns, each with a sub-heading and short description. Use for "why choose us" or "what you get" sections.',
			'slots'       => $slots,
		);

		return array( 'meta' => $meta, 'nodes' => $nodes );
	}

	private static function cta_banner(): array {
		$row    = 'bm-ctab-row';
		$grp    = 'bm-ctab-grp';
		$col    = 'bm-ctab-col';
		$head   = 'bm-ctab-head';
		$body   = 'bm-ctab-body';
		$button = 'bm-ctab-btn';

		$nodes = array(
			$row    => self::row_node( $row, null, 0, array(
				'width'             => 'full',
				'content_width'     => 'fixed',
				'content_alignment' => 'center',
				'padding_top'       => 100,
				'padding_bottom'    => 100,
				'padding_left'      => 20,
				'padding_right'     => 20,
				'bg_type'           => 'color',
				'bg_color'          => '1f2937',
			) ),
			$grp    => self::group_node( $grp, $row, 0 ),
			$col    => self::col_node( $col, $grp, 0, array( 'size' => 100, 'content_alignment' => 'center' ) ),
			$head   => self::module_node( $head, $col, 0, 'heading', array(
				'heading'   => 'Ready to get started?',
				'tag'       => 'h2',
				'alignment' => 'center',
				'color'     => 'ffffff',
			) ),
			$body   => self::module_node( $body, $col, 1, 'rich-text', array(
				'text' => '<p style="text-align:center; color:#e5e7eb;">A short supporting line for the call to action.</p>',
			) ),
			$button => self::module_node( $button, $col, 2, 'button', array(
				'text'         => 'Start Now',
				'link'         => '#',
				'align'        => 'center',
				'style'        => 'flat',
				'bg_color'     => 'ffffff',
				'text_color'   => '1f2937',
			) ),
		);

		$meta = array(
			'name'        => 'CTA Banner (dark)',
			'category'    => 'cta',
			'description' => 'Full-width dark banner with a centered headline, supporting line, and a primary CTA button. Use as a closing section on a landing page or between content blocks.',
			'slots'       => array(
				'headline'  => array( 'node' => $head,   'field' => 'heading' ),
				'subhead'   => array( 'node' => $body,   'field' => 'text' ),
				'cta_label' => array( 'node' => $button, 'field' => 'text' ),
				'cta_url'   => array( 'node' => $button, 'field' => 'link' ),
			),
		);

		return array( 'meta' => $meta, 'nodes' => $nodes );
	}

	private static function two_col_content(): array {
		$row     = 'bm-twocol-row';
		$grp     = 'bm-twocol-grp';
		$col_l   = 'bm-twocol-coll';
		$col_r   = 'bm-twocol-colr';
		$head_l  = 'bm-twocol-headl';
		$body_l  = 'bm-twocol-bodyl';
		$head_r  = 'bm-twocol-headr';
		$body_r  = 'bm-twocol-bodyr';

		$nodes = array(
			$row    => self::row_node( $row, null, 0, array(
				'width'          => 'fixed',
				'content_width'  => 'fixed',
				'padding_top'    => 60,
				'padding_bottom' => 60,
				'padding_left'   => 20,
				'padding_right'  => 20,
			) ),
			$grp    => self::group_node( $grp, $row, 0 ),
			$col_l  => self::col_node( $col_l, $grp, 0, array( 'size' => 50 ) ),
			$head_l => self::module_node( $head_l, $col_l, 0, 'heading', array(
				'heading' => 'Left Column Heading',
				'tag'     => 'h3',
			) ),
			$body_l => self::module_node( $body_l, $col_l, 1, 'rich-text', array(
				'text' => '<p>Left column body copy. A paragraph or two of supporting prose.</p>',
			) ),
			$col_r  => self::col_node( $col_r, $grp, 1, array( 'size' => 50 ) ),
			$head_r => self::module_node( $head_r, $col_r, 0, 'heading', array(
				'heading' => 'Right Column Heading',
				'tag'     => 'h3',
			) ),
			$body_r => self::module_node( $body_r, $col_r, 1, 'rich-text', array(
				'text' => '<p>Right column body copy. A paragraph or two of supporting prose.</p>',
			) ),
		);

		$meta = array(
			'name'        => '2-Column Content Block',
			'category'    => 'content',
			'description' => 'Two equal-width columns side by side, each with a heading and rich-text body. Use for compare/contrast, before/after, or two-topic sections.',
			'slots'       => array(
				'left_heading'  => array( 'node' => $head_l, 'field' => 'heading' ),
				'left_body'     => array( 'node' => $body_l, 'field' => 'text' ),
				'right_heading' => array( 'node' => $head_r, 'field' => 'heading' ),
				'right_body'    => array( 'node' => $body_r, 'field' => 'text' ),
			),
		);

		return array( 'meta' => $meta, 'nodes' => $nodes );
	}

	// ---------- Node constructors ----------

	private static function row_node( string $id, ?string $parent, int $position, array $settings ): \stdClass {
		return (object) array(
			'node'     => $id,
			'type'     => 'row',
			'parent'   => $parent,
			'position' => $position,
			'settings' => (object) $settings,
		);
	}

	private static function group_node( string $id, string $parent, int $position ): \stdClass {
		return (object) array(
			'node'     => $id,
			'type'     => 'column-group',
			'parent'   => $parent,
			'position' => $position,
			'settings' => '',
		);
	}

	private static function col_node( string $id, string $parent, int $position, array $settings ): \stdClass {
		return (object) array(
			'node'     => $id,
			'type'     => 'column',
			'parent'   => $parent,
			'position' => $position,
			'settings' => (object) $settings,
		);
	}

	private static function module_node( string $id, string $parent, int $position, string $module_slug, array $settings ): \stdClass {
		$settings['type'] = $module_slug;
		return (object) array(
			'node'     => $id,
			'type'     => 'module',
			'parent'   => $parent,
			'position' => $position,
			'settings' => (object) $settings,
		);
	}
}
