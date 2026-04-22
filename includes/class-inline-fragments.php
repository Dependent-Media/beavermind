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
			'nav-header'          => self::nav_header(),
			'hero-centered'       => self::hero_centered(),
			'hero-with-image'     => self::hero_with_image(),
			'icon-row-3'          => self::icon_row_3(),
			'feature-grid-3col'   => self::feature_grid_3col(),
			'feature-grid-9'      => self::feature_grid_9(),
			'image-text-split'    => self::image_text_split(),
			'process-steps-3'     => self::process_steps_3(),
			'testimonial-single'  => self::testimonial_single(),
			'stats-row-3'         => self::stats_row_3(),
			'logos-row-5'         => self::logos_row_5(),
			'pricing-table-2up'   => self::pricing_table_2up(),
			'faq-list-4'          => self::faq_list_4(),
			'cta-banner'          => self::cta_banner(),
			'two-col-content'     => self::two_col_content(),
			'footer-4col'         => self::footer_4col(),
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
			'theme_bindings' => array(
				'primary' => array(
					array( 'node' => $button, 'field' => 'bg_color' ),
				),
			),
		);

		return array( 'meta' => $meta, 'nodes' => $nodes );
	}

	private static function hero_with_image(): array {
		$row    = 'bm-heroimg-row';
		$grp    = 'bm-heroimg-grp';
		$col_l  = 'bm-heroimg-coll';
		$col_r  = 'bm-heroimg-colr';
		$head   = 'bm-heroimg-head';
		$body   = 'bm-heroimg-body';
		$button = 'bm-heroimg-btn';
		$photo  = 'bm-heroimg-photo';

		$nodes = array(
			$row    => self::row_node( $row, null, 0, array(
				'width'          => 'fixed',
				'content_width'  => 'fixed',
				'padding_top'    => 80,
				'padding_bottom' => 80,
				'padding_left'   => 20,
				'padding_right'  => 20,
			) ),
			$grp    => self::group_node( $grp, $row, 0 ),
			$col_l  => self::col_node( $col_l, $grp, 0, array( 'size' => 55, 'content_alignment' => 'left' ) ),
			$head   => self::module_node( $head, $col_l, 0, 'heading', array(
				'heading' => 'Placeholder Headline',
				'tag'     => 'h1',
			) ),
			$body   => self::module_node( $body, $col_l, 1, 'rich-text', array(
				'text' => '<p>Placeholder subhead copy — one sentence that sharpens the headline.</p>',
			) ),
			$button => self::module_node( $button, $col_l, 2, 'button', array(
				'text'  => 'Placeholder CTA',
				'link'  => '#',
				'align' => 'left',
				'style' => 'flat',
			) ),
			$col_r  => self::col_node( $col_r, $grp, 1, array( 'size' => 45 ) ),
			$photo  => self::module_node( $photo, $col_r, 0, 'photo', array(
				// URL mode: BB reads photo_url and ignores the attachment-ID `photo` field.
				'photo_source' => 'url',
				'photo_url'    => 'https://via.placeholder.com/600x480?text=Hero+image',
				'url_title'    => 'Hero image',
				'align'        => 'center',
				'crop'         => '',
				'link_type'    => '',
			) ),
		);

		$meta = array(
			'name'        => 'Hero with Image (right)',
			'category'    => 'hero',
			'description' => 'Two-column hero: headline + subhead + CTA on the left, hero image on the right. Use when the reference site has a prominent product shot or screenshot.',
			'slots'       => array(
				'headline'   => array( 'node' => $head,   'field' => 'heading' ),
				'subhead'    => array( 'node' => $body,   'field' => 'text' ),
				'cta_label'  => array( 'node' => $button, 'field' => 'text' ),
				'cta_url'    => array( 'node' => $button, 'field' => 'link' ),
				'image_url'  => array( 'node' => $photo,  'field' => 'photo_url' ),
				'image_alt'  => array( 'node' => $photo,  'field' => 'url_title' ),
			),
			'theme_bindings' => array(
				'primary' => array(
					array( 'node' => $button, 'field' => 'bg_color' ),
				),
			),
		);

		return array( 'meta' => $meta, 'nodes' => $nodes );
	}

	private static function image_text_split(): array {
		$row    = 'bm-imgtxt-row';
		$grp    = 'bm-imgtxt-grp';
		$col_l  = 'bm-imgtxt-coll';
		$col_r  = 'bm-imgtxt-colr';
		$photo  = 'bm-imgtxt-photo';
		$head   = 'bm-imgtxt-head';
		$body   = 'bm-imgtxt-body';

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
			$photo  => self::module_node( $photo, $col_l, 0, 'photo', array(
				'photo_source' => 'url',
				'photo_url'    => 'https://via.placeholder.com/540x420?text=Feature+image',
				'url_title'    => 'Feature image',
				'align'        => 'center',
				'crop'         => '',
				'link_type'    => '',
			) ),
			$col_r  => self::col_node( $col_r, $grp, 1, array( 'size' => 50, 'content_alignment' => 'left' ) ),
			$head   => self::module_node( $head, $col_r, 0, 'heading', array(
				'heading' => 'Feature Heading',
				'tag'     => 'h3',
			) ),
			$body   => self::module_node( $body, $col_r, 1, 'rich-text', array(
				'text' => '<p>Feature body — describe what it does, for whom, and why it matters.</p>',
			) ),
		);

		$meta = array(
			'name'        => 'Image + Text Split',
			'category'    => 'content',
			'description' => 'Two-column block with an image on the left and a heading + body paragraph on the right. Use to explain a feature that benefits from a screenshot or diagram.',
			'slots'       => array(
				'image_url'  => array( 'node' => $photo, 'field' => 'photo_url' ),
				'image_alt'  => array( 'node' => $photo, 'field' => 'url_title' ),
				'heading'    => array( 'node' => $head,  'field' => 'heading' ),
				'body'       => array( 'node' => $body,  'field' => 'text' ),
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

	private static function testimonial_single(): array {
		$row   = 'bm-testi-row';
		$grp   = 'bm-testi-grp';
		$col   = 'bm-testi-col';
		$quote = 'bm-testi-quote';
		$attr  = 'bm-testi-attr';

		$nodes = array(
			$row   => self::row_node( $row, null, 0, array(
				'width'             => 'fixed',
				'content_width'     => 'fixed',
				'content_alignment' => 'center',
				'padding_top'       => 80,
				'padding_bottom'    => 80,
				'padding_left'      => 40,
				'padding_right'     => 40,
				'bg_type'           => 'color',
				'bg_color'          => 'f9fafb',
			) ),
			$grp   => self::group_node( $grp, $row, 0 ),
			$col   => self::col_node( $col, $grp, 0, array( 'size' => 80, 'content_alignment' => 'center' ) ),
			$quote => self::module_node( $quote, $col, 0, 'heading', array(
				'heading'   => '"A placeholder pull-quote about the product."',
				'tag'       => 'h3',
				'alignment' => 'center',
			) ),
			$attr  => self::module_node( $attr, $col, 1, 'rich-text', array(
				'text' => '<p style="text-align:center;">— Placeholder Name, Title at Company</p>',
			) ),
		);

		$meta = array(
			'name'        => 'Single Testimonial',
			'category'    => 'social-proof',
			'description' => 'Centered pull-quote with an attribution line. Use when the reference has a single strong customer quote.',
			'slots'       => array(
				'quote'       => array( 'node' => $quote, 'field' => 'heading' ),
				'attribution' => array( 'node' => $attr,  'field' => 'text' ),
			),
			'theme_bindings' => array(
				'bg_light' => array(
					array( 'node' => $row, 'field' => 'bg_color' ),
				),
			),
		);

		return array( 'meta' => $meta, 'nodes' => $nodes );
	}

	private static function stats_row_3(): array {
		$row = 'bm-stats-row';
		$grp = 'bm-stats-grp';

		$nodes = array(
			$row => self::row_node( $row, null, 0, array(
				'width'          => 'fixed',
				'content_width'  => 'fixed',
				'padding_top'    => 60,
				'padding_bottom' => 60,
				'padding_left'   => 20,
				'padding_right'  => 20,
			) ),
			$grp => self::group_node( $grp, $row, 0 ),
		);

		$slots = array();
		foreach ( array( 1, 2, 3 ) as $i ) {
			$col    = "bm-stats-col-$i";
			$number = "bm-stats-num-$i";
			$label  = "bm-stats-lbl-$i";
			$nodes[ $col ]    = self::col_node( $col, $grp, $i - 1, array(
				'size'              => 33.33,
				'content_alignment' => 'center',
			) );
			$nodes[ $number ] = self::module_node( $number, $col, 0, 'heading', array(
				'heading'   => "100+",
				'tag'       => 'h2',
				'alignment' => 'center',
			) );
			$nodes[ $label ]  = self::module_node( $label, $col, 1, 'rich-text', array(
				'text' => "<p style=\"text-align:center;\">Stat $i label</p>",
			) );
			$slots[ "stat_{$i}_number" ] = array( 'node' => $number, 'field' => 'heading' );
			$slots[ "stat_{$i}_label" ]  = array( 'node' => $label,  'field' => 'text' );
		}

		$meta = array(
			'name'        => '3-Column Stats Row',
			'category'    => 'social-proof',
			'description' => 'Three big numbers with short labels beneath. Use for metrics like "10M+ users", "99.9% uptime", "5-min setup".',
			'slots'       => $slots,
		);

		return array( 'meta' => $meta, 'nodes' => $nodes );
	}

	private static function logos_row_5(): array {
		$row      = 'bm-logos-row';
		$intro_g  = 'bm-logos-intro-grp';
		$intro_c  = 'bm-logos-intro-col';
		$intro_h  = 'bm-logos-intro-h';
		$grp      = 'bm-logos-grp';

		$nodes = array(
			$row      => self::row_node( $row, null, 0, array(
				'width'             => 'fixed',
				'content_width'     => 'fixed',
				'padding_top'       => 40,
				'padding_bottom'    => 40,
				'padding_left'      => 20,
				'padding_right'     => 20,
				'bg_type'           => 'color',
				'bg_color'          => 'f9fafb',
			) ),
			$intro_g  => self::group_node( $intro_g, $row, 0 ),
			$intro_c  => self::col_node( $intro_c, $intro_g, 0, array( 'size' => 100, 'content_alignment' => 'center' ) ),
			$intro_h  => self::module_node( $intro_h, $intro_c, 0, 'heading', array(
				'heading'   => 'Trusted by teams at',
				'tag'       => 'h4',
				'alignment' => 'center',
			) ),
			$grp      => self::group_node( $grp, $row, 1 ),
		);

		$slots = array(
			'intro_heading' => array( 'node' => $intro_h, 'field' => 'heading' ),
		);

		foreach ( array( 1, 2, 3, 4, 5 ) as $i ) {
			$col   = "bm-logos-col-$i";
			$photo = "bm-logos-photo-$i";
			$nodes[ $col ]   = self::col_node( $col, $grp, $i - 1, array( 'size' => 20, 'content_alignment' => 'center' ) );
			$nodes[ $photo ] = self::module_node( $photo, $col, 0, 'photo', array(
				'photo_source' => 'url',
				'photo_url'    => "https://via.placeholder.com/160x60?text=Logo+$i",
				'url_title'    => "Logo $i",
				'align'        => 'center',
				'crop'         => '',
				'link_type'    => '',
			) );
			$slots[ "logo_{$i}_url" ] = array( 'node' => $photo, 'field' => 'photo_url' );
			$slots[ "logo_{$i}_alt" ] = array( 'node' => $photo, 'field' => 'url_title' );
		}

		$meta = array(
			'name'        => 'Logos Row (5)',
			'category'    => 'social-proof',
			'description' => 'Horizontal row of 5 customer or partner logos with an intro line. Only use this fragment when the reference site actually shows logos — do not invent them.',
			'slots'       => $slots,
			'theme_bindings' => array(
				'bg_light' => array(
					array( 'node' => $row, 'field' => 'bg_color' ),
				),
			),
		);

		return array( 'meta' => $meta, 'nodes' => $nodes );
	}

	private static function faq_list_4(): array {
		$row       = 'bm-faq-row';
		$intro_g   = 'bm-faq-intro-grp';
		$intro_c   = 'bm-faq-intro-col';
		$intro_h   = 'bm-faq-intro-h';
		$body_g    = 'bm-faq-body-grp';
		$body_c    = 'bm-faq-body-col';

		$nodes = array(
			$row     => self::row_node( $row, null, 0, array(
				'width'          => 'fixed',
				'content_width'  => 'fixed',
				'padding_top'    => 60,
				'padding_bottom' => 60,
				'padding_left'   => 20,
				'padding_right'  => 20,
			) ),
			$intro_g => self::group_node( $intro_g, $row, 0 ),
			$intro_c => self::col_node( $intro_c, $intro_g, 0, array( 'size' => 100, 'content_alignment' => 'center' ) ),
			$intro_h => self::module_node( $intro_h, $intro_c, 0, 'heading', array(
				'heading'   => 'Frequently asked questions',
				'tag'       => 'h2',
				'alignment' => 'center',
			) ),
			$body_g  => self::group_node( $body_g, $row, 1 ),
			$body_c  => self::col_node( $body_c, $body_g, 0, array( 'size' => 100 ) ),
		);

		$slots = array(
			'section_heading' => array( 'node' => $intro_h, 'field' => 'heading' ),
		);

		$position = 0;
		foreach ( array( 1, 2, 3, 4 ) as $i ) {
			$q = "bm-faq-q-$i";
			$a = "bm-faq-a-$i";
			$nodes[ $q ] = self::module_node( $q, $body_c, $position++, 'heading', array(
				'heading' => "Question $i goes here",
				'tag'     => 'h4',
			) );
			$nodes[ $a ] = self::module_node( $a, $body_c, $position++, 'rich-text', array(
				'text' => "<p>Answer $i — explain in 1-3 sentences. Link out to deeper docs if helpful.</p>",
			) );
			$slots[ "q{$i}" ] = array( 'node' => $q, 'field' => 'heading' );
			$slots[ "a{$i}" ] = array( 'node' => $a, 'field' => 'text' );
		}

		$meta = array(
			'name'        => '4-Item FAQ List',
			'category'    => 'content',
			'description' => 'Section heading above 4 stacked Q/A pairs. Use when the reference has an FAQ or a short "Common questions" section. Claude writes both the questions and the answers.',
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
			'theme_bindings' => array(
				'bg_dark' => array(
					array( 'node' => $row, 'field' => 'bg_color' ),
				),
				'text_on_dark' => array(
					array( 'node' => $head,   'field' => 'color' ),
					array( 'node' => $button, 'field' => 'bg_color' ),
				),
				'primary' => array(
					array( 'node' => $button, 'field' => 'text_color' ),
				),
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

	private static function nav_header(): array {
		$row    = 'bm-nav-row';
		$grp    = 'bm-nav-grp';
		$col_l  = 'bm-nav-coll';
		$col_r  = 'bm-nav-colr';
		$logo   = 'bm-nav-logo';
		$brand  = 'bm-nav-brand';
		$nav    = 'bm-nav-links';
		$button = 'bm-nav-btn';

		$nodes = array(
			$row    => self::row_node( $row, null, 0, array(
				'width'             => 'full',
				'content_width'     => 'fixed',
				'padding_top'       => 18,
				'padding_bottom'    => 18,
				'padding_left'      => 20,
				'padding_right'     => 20,
				'bg_type'           => 'color',
				'bg_color'          => '111827',
			) ),
			$grp    => self::group_node( $grp, $row, 0 ),
			$col_l  => self::col_node( $col_l, $grp, 0, array( 'size' => 35, 'content_alignment' => 'left' ) ),
			// BB's photo module honours `align: center|left|right`; logos are
			// left-aligned inline-block so they sit beside the brand text.
			$logo   => self::module_node( $logo, $col_l, 0, 'photo', array(
				'photo_source' => 'url',
				'photo_url'    => 'https://via.placeholder.com/140x40?text=Logo',
				'url_title'    => 'Brand logo',
				'align'        => 'left',
				'crop'         => '',
				'link_type'    => '',
				'width'        => 140,
			) ),
			$brand  => self::module_node( $brand, $col_l, 1, 'heading', array(
				'heading' => 'BrandName',
				'tag'     => 'h4',
				'color'   => 'ffffff',
			) ),
			$col_r  => self::col_node( $col_r, $grp, 1, array( 'size' => 65, 'content_alignment' => 'right' ) ),
			// Nav links rendered as inline anchors inside rich-text — Claude
			// writes them as `<a href="...">Home</a> · <a ...>Pricing</a>`. One
			// slot, arbitrary link count. Works anywhere; no dependency on a
			// registered WP menu.
			$nav    => self::module_node( $nav, $col_r, 0, 'rich-text', array(
				'text' => '<p style="text-align:right;color:#e5e7eb;font-size:14px;"><a href="#" style="color:#e5e7eb;margin-right:18px;">Home</a><a href="#" style="color:#e5e7eb;margin-right:18px;">Pricing</a><a href="#" style="color:#e5e7eb;">Contact</a></p>',
			) ),
			$button => self::module_node( $button, $col_r, 1, 'button', array(
				'text'       => 'Get Started',
				'link'       => '#',
				'align'      => 'right',
				'style'      => 'flat',
				'bg_color'   => '2563eb',
				'text_color' => 'ffffff',
			) ),
		);

		$meta = array(
			'name'        => 'Site Navigation Header',
			'category'    => 'header',
			'description' => 'Full-width dark navigation bar: logo + brand name on the left, inline nav links + a primary CTA button on the right. Use as the FIRST fragment when cloning any site that has a visible top-level nav. Nav links live in a rich-text slot — write them as inline <a> tags so you can include any number of items.',
			'slots'       => array(
				'logo_url'   => array( 'node' => $logo,   'field' => 'photo_url' ),
				'logo_alt'   => array( 'node' => $logo,   'field' => 'url_title' ),
				'site_name'  => array( 'node' => $brand,  'field' => 'heading' ),
				'nav_html'   => array( 'node' => $nav,    'field' => 'text' ),
				'cta_label'  => array( 'node' => $button, 'field' => 'text' ),
				'cta_url'    => array( 'node' => $button, 'field' => 'link' ),
			),
			'theme_bindings' => array(
				'bg_dark' => array(
					array( 'node' => $row, 'field' => 'bg_color' ),
				),
				'text_on_dark' => array(
					array( 'node' => $brand, 'field' => 'color' ),
				),
				'primary' => array(
					array( 'node' => $button, 'field' => 'bg_color' ),
				),
			),
		);

		return array( 'meta' => $meta, 'nodes' => $nodes );
	}

	private static function icon_row_3(): array {
		$row = 'bm-iconrow-row';
		$grp = 'bm-iconrow-grp';

		$nodes = array(
			$row => self::row_node( $row, null, 0, array(
				'width'             => 'full',
				'content_width'     => 'fixed',
				'padding_top'       => 36,
				'padding_bottom'    => 36,
				'padding_left'      => 20,
				'padding_right'     => 20,
				'bg_type'           => 'color',
				'bg_color'          => 'f9fafb',
			) ),
			$grp => self::group_node( $grp, $row, 0 ),
		);

		$slots = array();
		foreach ( array( 1, 2, 3 ) as $i ) {
			$col   = "bm-iconrow-col-$i";
			$icon  = "bm-iconrow-icon-$i";
			$label = "bm-iconrow-label-$i";
			// Icon is a heading with a big emoji / unicode symbol — Claude picks
			// something evocative like "✓", "🔒", "⚡". Cheap, no deps.
			$nodes[ $col ]   = self::col_node( $col, $grp, $i - 1, array(
				'size'              => 33.33,
				'content_alignment' => 'center',
			) );
			$nodes[ $icon ]  = self::module_node( $icon, $col, 0, 'heading', array(
				'heading'   => '✓',
				'tag'       => 'h3',
				'alignment' => 'center',
			) );
			$nodes[ $label ] = self::module_node( $label, $col, 1, 'heading', array(
				'heading'   => "Feature $i",
				'tag'       => 'h5',
				'alignment' => 'center',
			) );
			$slots[ "item_{$i}_icon" ]  = array( 'node' => $icon,  'field' => 'heading' );
			$slots[ "item_{$i}_label" ] = array( 'node' => $label, 'field' => 'heading' );
		}

		$meta = array(
			'name'        => '3-Icon Trust Row',
			'category'    => 'features',
			'description' => 'Compact light-background strip with 3 icon + label pairs. Use for trust signals or short feature highlights right below the hero ("DAS Compliant Forms / Secure & Encrypted / Instant PDF Downloads"). Icon slots accept ANY single character: emoji ("🔒"), unicode checkmark ("✓"), geometric shape. Pick icons that match the label semantics.',
			'slots'       => $slots,
			'theme_bindings' => array(
				'bg_light' => array(
					array( 'node' => $row, 'field' => 'bg_color' ),
				),
			),
		);

		return array( 'meta' => $meta, 'nodes' => $nodes );
	}

	private static function feature_grid_9(): array {
		$row       = 'bm-fg9-row';
		$intro_grp = 'bm-fg9-intro-grp';
		$intro_col = 'bm-fg9-intro-col';
		$intro_h   = 'bm-fg9-intro-h';
		$intro_sub = 'bm-fg9-intro-sub';

		$nodes = array(
			$row       => self::row_node( $row, null, 0, array(
				'width'             => 'fixed',
				'content_width'     => 'fixed',
				'padding_top'       => 60,
				'padding_bottom'    => 60,
				'padding_left'      => 20,
				'padding_right'     => 20,
			) ),
			$intro_grp => self::group_node( $intro_grp, $row, 0 ),
			$intro_col => self::col_node( $intro_col, $intro_grp, 0, array( 'size' => 100, 'content_alignment' => 'center' ) ),
			$intro_h   => self::module_node( $intro_h, $intro_col, 0, 'heading', array(
				'heading'   => 'Section Heading',
				'tag'       => 'h2',
				'alignment' => 'center',
			) ),
			$intro_sub => self::module_node( $intro_sub, $intro_col, 1, 'rich-text', array(
				'text' => '<p style="text-align:center;">Section subhead — 1 sentence that frames the grid below.</p>',
			) ),
		);

		$slots = array(
			'section_heading' => array( 'node' => $intro_h,   'field' => 'heading' ),
			'section_subhead' => array( 'node' => $intro_sub, 'field' => 'text' ),
		);

		// 3x3 grid = three column-groups of three columns. Can't flex-wrap
		// inside one group — BB lays them horizontally without wrapping.
		$card_index = 1;
		foreach ( array( 1, 2, 3 ) as $row_i ) {
			$rgrp = "bm-fg9-rgrp-$row_i";
			$nodes[ $rgrp ] = self::group_node( $rgrp, $row, $row_i );
			foreach ( array( 1, 2, 3 ) as $col_i ) {
				$col   = "bm-fg9-col-$card_index";
				$icon  = "bm-fg9-icon-$card_index";
				$title = "bm-fg9-title-$card_index";
				$body  = "bm-fg9-body-$card_index";
				$nodes[ $col ]   = self::col_node( $col, $rgrp, $col_i - 1, array(
					'size'              => 33.33,
					'content_alignment' => 'left',
					'padding_top'       => 16,
					'padding_bottom'    => 16,
					'padding_left'      => 16,
					'padding_right'     => 16,
				) );
				$nodes[ $icon ]  = self::module_node( $icon, $col, 0, 'heading', array(
					'heading' => '●',
					'tag'     => 'h4',
				) );
				$nodes[ $title ] = self::module_node( $title, $col, 1, 'heading', array(
					'heading' => "Feature $card_index",
					'tag'     => 'h4',
				) );
				$nodes[ $body ]  = self::module_node( $body, $col, 2, 'rich-text', array(
					'text' => "<p>Short description of feature $card_index — 1-2 sentences explaining what it does.</p>",
				) );
				$slots[ "card_{$card_index}_icon" ]  = array( 'node' => $icon,  'field' => 'heading' );
				$slots[ "card_{$card_index}_title" ] = array( 'node' => $title, 'field' => 'heading' );
				$slots[ "card_{$card_index}_body" ]  = array( 'node' => $body,  'field' => 'text' );
				$card_index++;
			}
		}

		$meta = array(
			'name'        => '9-Card Feature Grid (3x3)',
			'category'    => 'features',
			'description' => 'Section heading + subhead above a 3x3 grid of 9 feature cards. Each card has an icon (emoji or unicode symbol), a short title, and a 1-2-sentence description. Use this whenever the source shows 7-9 item cards (e.g. "Nine Essential Forms", service catalogs, capability grids). Prefer this over feature-grid-3col when the source clearly lists more than 4 items — do NOT compress 9 items into 3.',
			'slots'       => $slots,
		);

		return array( 'meta' => $meta, 'nodes' => $nodes );
	}

	private static function process_steps_3(): array {
		$row       = 'bm-step-row';
		$intro_grp = 'bm-step-intro-grp';
		$intro_col = 'bm-step-intro-col';
		$intro_h   = 'bm-step-intro-h';
		$intro_sub = 'bm-step-intro-sub';
		$steps_grp = 'bm-step-grp';

		$nodes = array(
			$row       => self::row_node( $row, null, 0, array(
				'width'             => 'fixed',
				'content_width'     => 'fixed',
				'padding_top'       => 60,
				'padding_bottom'    => 60,
				'padding_left'      => 20,
				'padding_right'     => 20,
			) ),
			$intro_grp => self::group_node( $intro_grp, $row, 0 ),
			$intro_col => self::col_node( $intro_col, $intro_grp, 0, array( 'size' => 100, 'content_alignment' => 'center' ) ),
			$intro_h   => self::module_node( $intro_h, $intro_col, 0, 'heading', array(
				'heading'   => 'How It Works',
				'tag'       => 'h2',
				'alignment' => 'center',
			) ),
			$intro_sub => self::module_node( $intro_sub, $intro_col, 1, 'rich-text', array(
				'text' => '<p style="text-align:center;">A streamlined process designed to save you time.</p>',
			) ),
			$steps_grp => self::group_node( $steps_grp, $row, 1 ),
		);

		$slots = array(
			'section_heading' => array( 'node' => $intro_h,   'field' => 'heading' ),
			'section_subhead' => array( 'node' => $intro_sub, 'field' => 'text' ),
		);

		foreach ( array( 1, 2, 3 ) as $i ) {
			$col    = "bm-step-col-$i";
			$number = "bm-step-num-$i";
			$title  = "bm-step-title-$i";
			$body   = "bm-step-body-$i";
			$nodes[ $col ]    = self::col_node( $col, $steps_grp, $i - 1, array(
				'size'              => 33.33,
				'content_alignment' => 'center',
			) );
			// The step number is baked into the fragment (1, 2, 3) — it's
			// structural, not content. Color is orange-ish by default to read
			// as a badge; theme_bindings swaps to brand primary.
			$nodes[ $number ] = self::module_node( $number, $col, 0, 'heading', array(
				'heading'   => (string) $i,
				'tag'       => 'h2',
				'alignment' => 'center',
				'color'     => 'f97316',
			) );
			$nodes[ $title ]  = self::module_node( $title, $col, 1, 'heading', array(
				'heading'   => "Step $i Title",
				'tag'       => 'h4',
				'alignment' => 'center',
			) );
			$nodes[ $body ]   = self::module_node( $body, $col, 2, 'rich-text', array(
				'text' => "<p style=\"text-align:center;\">Describe what happens in step $i — 1-2 sentences.</p>",
			) );
			$slots[ "step_{$i}_title" ] = array( 'node' => $title, 'field' => 'heading' );
			$slots[ "step_{$i}_body" ]  = array( 'node' => $body,  'field' => 'text' );
		}

		$meta = array(
			'name'        => '3-Step Process',
			'category'    => 'content',
			'description' => 'Section heading above three numbered steps (1, 2, 3) with title + description for each. The numbers are structural — don\'t include them in slot copy. Use for "How It Works" or "Get Started In 3 Steps" flows. Step numbers adopt the brand primary color automatically.',
			'slots'       => $slots,
			'theme_bindings' => array(
				'primary' => array(
					array( 'node' => 'bm-step-num-1', 'field' => 'color' ),
					array( 'node' => 'bm-step-num-2', 'field' => 'color' ),
					array( 'node' => 'bm-step-num-3', 'field' => 'color' ),
				),
			),
		);

		return array( 'meta' => $meta, 'nodes' => $nodes );
	}

	private static function pricing_table_2up(): array {
		$row       = 'bm-price-row';
		$intro_grp = 'bm-price-intro-grp';
		$intro_col = 'bm-price-intro-col';
		$intro_h   = 'bm-price-intro-h';
		$intro_sub = 'bm-price-intro-sub';
		$cards_grp = 'bm-price-cards-grp';

		$nodes = array(
			$row       => self::row_node( $row, null, 0, array(
				'width'             => 'full',
				'content_width'     => 'fixed',
				'padding_top'       => 60,
				'padding_bottom'    => 60,
				'padding_left'      => 20,
				'padding_right'     => 20,
				'bg_type'           => 'color',
				'bg_color'          => 'f9fafb',
			) ),
			$intro_grp => self::group_node( $intro_grp, $row, 0 ),
			$intro_col => self::col_node( $intro_col, $intro_grp, 0, array( 'size' => 100, 'content_alignment' => 'center' ) ),
			$intro_h   => self::module_node( $intro_h, $intro_col, 0, 'heading', array(
				'heading'   => 'Simple, Transparent Pricing',
				'tag'       => 'h2',
				'alignment' => 'center',
			) ),
			$intro_sub => self::module_node( $intro_sub, $intro_col, 1, 'rich-text', array(
				'text' => '<p style="text-align:center;">Pick the plan that fits your workflow.</p>',
			) ),
			$cards_grp => self::group_node( $cards_grp, $row, 1 ),
		);

		$slots = array(
			'section_heading' => array( 'node' => $intro_h,   'field' => 'heading' ),
			'section_subhead' => array( 'node' => $intro_sub, 'field' => 'text' ),
		);

		// Two cards: plan 1 on the left (neutral), plan 2 on the right (best
		// value / highlighted). Each has badge, name, price line, features
		// list (rich-text so Claude writes a real <ul>), and a CTA button.
		$theme_bindings = array( 'primary' => array() );
		foreach ( array( 1, 2 ) as $i ) {
			$col      = "bm-price-col-$i";
			$badge    = "bm-price-badge-$i";
			$name     = "bm-price-name-$i";
			$price    = "bm-price-price-$i";
			$features = "bm-price-features-$i";
			$button   = "bm-price-btn-$i";

			$col_bg = ( 2 === $i ) ? 'ffffff' : 'ffffff';
			$nodes[ $col ]      = self::col_node( $col, $cards_grp, $i - 1, array(
				'size'              => 50,
				'content_alignment' => 'center',
				'padding_top'       => 28,
				'padding_bottom'    => 28,
				'padding_left'      => 28,
				'padding_right'     => 28,
				'bg_type'           => 'color',
				'bg_color'          => $col_bg,
				'border_type'       => 'solid',
				'border_color'      => ( 2 === $i ) ? 'f97316' : 'e5e7eb',
				'border_top'        => 2,
				'border_bottom'     => 2,
				'border_left'       => 2,
				'border_right'      => 2,
				'border_radius'     => 8,
			) );
			// Badge is a small heading — leave it blank by default; Claude
			// fills it with "BEST VALUE" or similar on the highlighted card.
			$nodes[ $badge ]    = self::module_node( $badge, $col, 0, 'heading', array(
				'heading'   => ( 2 === $i ) ? 'BEST VALUE' : '',
				'tag'       => 'h6',
				'alignment' => 'center',
				'color'     => 'f97316',
			) );
			$nodes[ $name ]     = self::module_node( $name, $col, 1, 'heading', array(
				'heading'   => "Plan $i",
				'tag'       => 'h5',
				'alignment' => 'center',
			) );
			$nodes[ $price ]    = self::module_node( $price, $col, 2, 'heading', array(
				'heading'   => '$XX',
				'tag'       => 'h2',
				'alignment' => 'center',
			) );
			$nodes[ $features ] = self::module_node( $features, $col, 3, 'rich-text', array(
				'text' => '<ul><li>Feature one</li><li>Feature two</li><li>Feature three</li></ul>',
			) );
			$nodes[ $button ]   = self::module_node( $button, $col, 4, 'button', array(
				'text'       => 'Get Started',
				'link'       => '#',
				'align'      => 'center',
				'style'      => 'flat',
				'bg_color'   => ( 2 === $i ) ? 'f97316' : 'ffffff',
				'text_color' => ( 2 === $i ) ? 'ffffff' : 'f97316',
			) );

			$slots[ "plan_{$i}_badge" ]     = array( 'node' => $badge,    'field' => 'heading' );
			$slots[ "plan_{$i}_name" ]      = array( 'node' => $name,     'field' => 'heading' );
			$slots[ "plan_{$i}_price" ]     = array( 'node' => $price,    'field' => 'heading' );
			$slots[ "plan_{$i}_features" ]  = array( 'node' => $features, 'field' => 'text' );
			$slots[ "plan_{$i}_cta_label" ] = array( 'node' => $button,   'field' => 'text' );
			$slots[ "plan_{$i}_cta_url" ]   = array( 'node' => $button,   'field' => 'link' );

			// Only the highlighted card's button takes the brand primary as
			// background; the neutral card uses primary as accent text so the
			// two cards stay distinct after theming.
			if ( 2 === $i ) {
				$theme_bindings['primary'][] = array( 'node' => $button, 'field' => 'bg_color' );
				$theme_bindings['primary'][] = array( 'node' => $col,    'field' => 'border_color' );
				$theme_bindings['primary'][] = array( 'node' => $badge,  'field' => 'color' );
			} else {
				$theme_bindings['primary'][] = array( 'node' => $button, 'field' => 'text_color' );
			}
		}
		$theme_bindings['bg_light'] = array(
			array( 'node' => $row, 'field' => 'bg_color' ),
		);

		$meta = array(
			'name'        => 'Pricing Table (2 plans)',
			'category'    => 'cta',
			'description' => 'Section heading + subhead above two side-by-side pricing cards. Left card is neutral; right card is the highlighted "best value" option. Each card has an optional badge, plan name, big price headline ("$49" or "25 Forms"), a bulleted features list (rich-text, use <ul><li> tags), and a CTA button. Use this whenever the source shows comparable plans — do NOT render pricing as plain two-column text.',
			'slots'       => $slots,
			'theme_bindings' => $theme_bindings,
		);

		return array( 'meta' => $meta, 'nodes' => $nodes );
	}

	private static function footer_4col(): array {
		$row     = 'bm-foot-row';
		$grp     = 'bm-foot-grp';
		$col_1   = 'bm-foot-col-1';
		$brand   = 'bm-foot-brand';
		$blurb   = 'bm-foot-blurb';
		$col_2   = 'bm-foot-col-2';
		$h_2     = 'bm-foot-h-2';
		$links_2 = 'bm-foot-links-2';
		$col_3   = 'bm-foot-col-3';
		$h_3     = 'bm-foot-h-3';
		$links_3 = 'bm-foot-links-3';
		$col_4   = 'bm-foot-col-4';
		$h_4     = 'bm-foot-h-4';
		$links_4 = 'bm-foot-links-4';
		$bot_grp = 'bm-foot-bot-grp';
		$bot_col = 'bm-foot-bot-col';
		$copy    = 'bm-foot-copy';

		$nodes = array(
			$row => self::row_node( $row, null, 0, array(
				'width'          => 'full',
				'content_width'  => 'fixed',
				'padding_top'    => 56,
				'padding_bottom' => 24,
				'padding_left'   => 20,
				'padding_right'  => 20,
				'bg_type'        => 'color',
				'bg_color'       => '111827',
			) ),
			$grp => self::group_node( $grp, $row, 0 ),

			$col_1   => self::col_node( $col_1, $grp, 0, array( 'size' => 31 ) ),
			$brand   => self::module_node( $brand, $col_1, 0, 'heading', array(
				'heading' => 'BrandName',
				'tag'     => 'h4',
				'color'   => 'ffffff',
			) ),
			$blurb   => self::module_node( $blurb, $col_1, 1, 'rich-text', array(
				'text' => '<p style="color:#9ca3af;">Short tagline describing what the company does.</p>',
			) ),

			$col_2   => self::col_node( $col_2, $grp, 1, array( 'size' => 23 ) ),
			$h_2     => self::module_node( $h_2, $col_2, 0, 'heading', array(
				'heading' => 'Column',
				'tag'     => 'h5',
				'color'   => 'ffffff',
			) ),
			$links_2 => self::module_node( $links_2, $col_2, 1, 'rich-text', array(
				'text' => '<p><a href="#" style="color:#9ca3af;display:block;margin-bottom:6px;">Link 1</a><a href="#" style="color:#9ca3af;display:block;margin-bottom:6px;">Link 2</a></p>',
			) ),

			$col_3   => self::col_node( $col_3, $grp, 2, array( 'size' => 23 ) ),
			$h_3     => self::module_node( $h_3, $col_3, 0, 'heading', array(
				'heading' => 'Column',
				'tag'     => 'h5',
				'color'   => 'ffffff',
			) ),
			$links_3 => self::module_node( $links_3, $col_3, 1, 'rich-text', array(
				'text' => '<p><a href="#" style="color:#9ca3af;display:block;margin-bottom:6px;">Link 1</a><a href="#" style="color:#9ca3af;display:block;margin-bottom:6px;">Link 2</a></p>',
			) ),

			$col_4   => self::col_node( $col_4, $grp, 3, array( 'size' => 23 ) ),
			$h_4     => self::module_node( $h_4, $col_4, 0, 'heading', array(
				'heading' => 'Column',
				'tag'     => 'h5',
				'color'   => 'ffffff',
			) ),
			$links_4 => self::module_node( $links_4, $col_4, 1, 'rich-text', array(
				'text' => '<p><a href="#" style="color:#9ca3af;display:block;margin-bottom:6px;">Link 1</a><a href="#" style="color:#9ca3af;display:block;margin-bottom:6px;">Link 2</a></p>',
			) ),

			$bot_grp => self::group_node( $bot_grp, $row, 1 ),
			$bot_col => self::col_node( $bot_col, $bot_grp, 0, array( 'size' => 100, 'content_alignment' => 'center' ) ),
			$copy    => self::module_node( $copy, $bot_col, 0, 'rich-text', array(
				'text' => '<p style="text-align:center;color:#6b7280;font-size:13px;margin-top:24px;border-top:1px solid #374151;padding-top:18px;">© BrandName. All Rights Reserved.</p>',
			) ),
		);

		$meta = array(
			'name'        => '4-Column Footer',
			'category'    => 'footer',
			'description' => 'Dark-background site footer: brand name + tagline column on the left, then three link columns (typical: Contact, Navigation, Legal), then a centered copyright line beneath. Link slots are rich-text — write them as inline <a> tags with display:block for stacking. Use as the LAST fragment when cloning any site with a visible footer.',
			'slots'       => array(
				'brand_name'    => array( 'node' => $brand,   'field' => 'heading' ),
				'brand_blurb'   => array( 'node' => $blurb,   'field' => 'text' ),
				'col_1_heading' => array( 'node' => $h_2,     'field' => 'heading' ),
				'col_1_links'   => array( 'node' => $links_2, 'field' => 'text' ),
				'col_2_heading' => array( 'node' => $h_3,     'field' => 'heading' ),
				'col_2_links'   => array( 'node' => $links_3, 'field' => 'text' ),
				'col_3_heading' => array( 'node' => $h_4,     'field' => 'heading' ),
				'col_3_links'   => array( 'node' => $links_4, 'field' => 'text' ),
				'copyright'     => array( 'node' => $copy,    'field' => 'text' ),
			),
			'theme_bindings' => array(
				'bg_dark' => array(
					array( 'node' => $row, 'field' => 'bg_color' ),
				),
				'text_on_dark' => array(
					array( 'node' => $brand, 'field' => 'color' ),
					array( 'node' => $h_2,   'field' => 'color' ),
					array( 'node' => $h_3,   'field' => 'color' ),
					array( 'node' => $h_4,   'field' => 'color' ),
				),
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
