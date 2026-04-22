<?php
namespace BeaverMind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * In-WP user documentation. One submenu page under BeaverMind that explains
 * the inputs, the workflow tools (Refine, Multi-page, Push to Staging),
 * variants + theming, settings, and troubleshooting. Cross-links to the
 * other admin pages where relevant and out to GitHub for deeper detail.
 *
 * Rendered inline in PHP rather than from markdown so we don't pull in a
 * markdown parser dep just for one page. Sections are anchored so the TOC
 * jumps work.
 */
class DocsPage {

	const SLUG = 'beavermind-docs';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
	}

	public function add_submenu(): void {
		add_submenu_page(
			Settings::MENU_SLUG,
			__( 'Docs', 'beavermind' ),
			__( 'Docs', 'beavermind' ),
			'edit_pages',
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		?>
		<style>
			.bm-docs { max-width: 940px; }
			.bm-docs h2 { margin-top: 2rem; padding-top: 0.25rem; border-top: 1px solid #dcdcde; }
			.bm-docs h2:first-of-type { border-top: none; padding-top: 0; }
			.bm-docs h3 { margin-top: 1.4rem; }
			.bm-docs code { background: #f0f0f1; padding: 1px 5px; border-radius: 3px; font-size: 13px; }
			.bm-docs pre { background: #1d2327; color: #f0f0f1; padding: 12px 14px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
			.bm-docs table.bm-grid { border-collapse: collapse; width: 100%; margin: 0.75rem 0 1.25rem; }
			.bm-docs table.bm-grid th, .bm-docs table.bm-grid td { padding: 8px 10px; border-bottom: 1px solid #dcdcde; vertical-align: top; text-align: left; }
			.bm-docs table.bm-grid th { background: #f6f7f7; font-weight: 600; }
			.bm-docs ul { margin-left: 1.4rem; }
			.bm-docs .bm-toc { columns: 2; column-gap: 2rem; background: #f6f7f7; padding: 12px 18px 12px 32px; border-radius: 4px; margin: 0.75rem 0 1.5rem; }
			.bm-docs .bm-toc li { margin: 2px 0; }
			.bm-docs .bm-callout { background: #fef3c7; border-left: 3px solid #d97706; padding: 8px 14px; margin: 0.75rem 0; }
			.bm-docs .bm-callout.info { background: #e0f2fe; border-left-color: #0284c7; }
		</style>

		<div class="wrap bm-docs">
			<h1><?php esc_html_e( 'BeaverMind — Documentation', 'beavermind' ); ?></h1>
			<p style="font-size: 14px; color: #50575e;">
				<?php esc_html_e( 'BeaverMind composes Beaver Builder pages by selecting fragments from a curated library and filling their content slots via the Claude API. This page covers the in-admin workflows — settings, every input type, refinement, staging push, and developer tooling.', 'beavermind' ); ?>
			</p>

			<ul class="bm-toc">
				<li><a href="#quick-start"><?php esc_html_e( 'Quick start', 'beavermind' ); ?></a></li>
				<li><a href="#inputs"><?php esc_html_e( 'Five ways to generate a page', 'beavermind' ); ?></a></li>
				<li><a href="#variants"><?php esc_html_e( 'Variants', 'beavermind' ); ?></a></li>
				<li><a href="#theming"><?php esc_html_e( 'Brand-aware theming', 'beavermind' ); ?></a></li>
				<li><a href="#images"><?php esc_html_e( 'Image sourcing', 'beavermind' ); ?></a></li>
				<li><a href="#refine"><?php esc_html_e( 'Refining an existing page', 'beavermind' ); ?></a></li>
				<li><a href="#multipage"><?php esc_html_e( 'Multi-page generation', 'beavermind' ); ?></a></li>
				<li><a href="#staging"><?php esc_html_e( 'Push to staging', 'beavermind' ); ?></a></li>
				<li><a href="#fragments"><?php esc_html_e( 'Fragment library', 'beavermind' ); ?></a></li>
				<li><a href="#settings-ref"><?php esc_html_e( 'Settings reference', 'beavermind' ); ?></a></li>
				<li><a href="#dev"><?php esc_html_e( 'For developers', 'beavermind' ); ?></a></li>
				<li><a href="#troubleshooting"><?php esc_html_e( 'Troubleshooting', 'beavermind' ); ?></a></li>
			</ul>

			<h2 id="quick-start"><?php esc_html_e( 'Quick start', 'beavermind' ); ?></h2>
			<ol>
				<li><?php
					printf(
						wp_kses_post( __( 'Open <a href="%s">BeaverMind settings</a> and paste your Anthropic API key. Pick a model — Claude Opus 4.7 is the default and best for layout planning.', 'beavermind' ) ),
						esc_url( admin_url( 'admin.php?page=' . Settings::MENU_SLUG ) )
					);
				?></li>
				<li><?php
					printf(
						wp_kses_post( __( 'Try it: <a href="%s">Generate</a> with a brief like <em>"a landing page for a SaaS scheduling tool aimed at design agencies"</em>.', 'beavermind' ) ),
						esc_url( admin_url( 'admin.php?page=' . PromptGenerator::SLUG ) )
					);
				?></li>
				<li><?php esc_html_e( 'Open the resulting draft page in the Beaver Builder editor and refine.', 'beavermind' ); ?></li>
			</ol>
			<p class="bm-callout info"><?php esc_html_e( 'Generated pages are saved as drafts by default — review them in the BB editor before publishing.', 'beavermind' ); ?></p>

			<h2 id="inputs"><?php esc_html_e( 'Five ways to generate a page', 'beavermind' ); ?></h2>
			<table class="bm-grid">
				<thead><tr>
					<th><?php esc_html_e( 'Input', 'beavermind' ); ?></th>
					<th><?php esc_html_e( 'Admin page', 'beavermind' ); ?></th>
					<th><?php esc_html_e( 'When to use', 'beavermind' ); ?></th>
				</tr></thead>
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Free-text brief', 'beavermind' ); ?></strong></td>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PromptGenerator::SLUG ) ); ?>"><?php esc_html_e( 'Generate', 'beavermind' ); ?></a></td>
						<td><?php esc_html_e( 'Starting from scratch with a target audience and offering in mind.', 'beavermind' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'URL', 'beavermind' ); ?></strong></td>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . CloneGenerator::SLUG ) ); ?>"><?php esc_html_e( 'Clone from URL', 'beavermind' ); ?></a></td>
						<td><?php esc_html_e( 'Public page you want redesigned. We fetch + extract structure and copy, then rewrite using the fragment library. The OG image is also passed to Claude vision when present.', 'beavermind' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Raw HTML', 'beavermind' ); ?></strong></td>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PasteHTMLGenerator::SLUG ) ); ?>"><?php esc_html_e( 'Paste HTML', 'beavermind' ); ?></a></td>
						<td><?php esc_html_e( 'Page behind auth, email template, local file — anything Clone from URL can\'t fetch.', 'beavermind' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Image', 'beavermind' ); ?></strong></td>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . ImageInputGenerator::SLUG ) ); ?>"><?php esc_html_e( 'From Image', 'beavermind' ); ?></a></td>
						<td><?php esc_html_e( 'Screenshot or mockup. Claude reads the image visually (vision) and mirrors its structure. PNG / JPEG / WebP / GIF, max 3.5 MB.', 'beavermind' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Figma frame', 'beavermind' ); ?></strong></td>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . FigmaGenerator::SLUG ) ); ?>"><?php esc_html_e( 'From Figma', 'beavermind' ); ?></a></td>
						<td><?php esc_html_e( 'Figma share URL. We render the frame as PNG via Figma\'s REST API, then send it through the same vision pipeline. Requires a Figma personal access token in settings.', 'beavermind' ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2 id="variants"><?php esc_html_e( 'Variants', 'beavermind' ); ?></h2>
			<p><?php esc_html_e( 'Every generator has a Variants selector (1, 2, 3, or 5). With more than one, BeaverMind runs the planner sequentially — same input, different draft pages — and Claude\'s adaptive thinking gives you genuinely different layouts to compare. Each variant is one Claude API call.', 'beavermind' ); ?></p>
			<p><?php esc_html_e( 'Use 3 when you want to pick from options. Use 1 when you want speed.', 'beavermind' ); ?></p>

			<h2 id="theming"><?php esc_html_e( 'Brand-aware theming', 'beavermind' ); ?></h2>
			<p><?php esc_html_e( 'When the input is a URL, BeaverMind extracts brand signals from the source: site name, theme color (from <meta name="theme-color">), logo, OG image, and Google Font families. These get passed to Claude as soft hints.', 'beavermind' ); ?></p>
			<p><?php esc_html_e( 'Claude returns a five-color theme — primary, bg_dark, bg_light, text_on_dark, text_on_light — that fragments adopt automatically. The result notice shows the chosen palette as inline color swatches so you can see what Claude picked at a glance.', 'beavermind' ); ?></p>

			<h2 id="images"><?php esc_html_e( 'Image sourcing', 'beavermind' ); ?></h2>
			<p><?php
				printf(
					wp_kses_post( __( 'Fragments with image slots (heroes, feature splits, logos) can be filled with free stock photos from <a href="%1$s" target="_blank" rel="noopener noreferrer">Pexels</a>. Paste a free Pexels API key into <a href="%2$s">Settings → Integrations</a> and every generation from that point on will replace empty / placeholder image URLs with contextually-relevant photos.', 'beavermind' ) ),
					esc_url( 'https://www.pexels.com/api/' ),
					esc_url( admin_url( 'admin.php?page=' . Settings::MENU_SLUG ) )
				);
			?></p>
			<p><?php esc_html_e( 'Search queries are written by Claude Haiku in one batched call per generation — the brief turns into 2-4 word concrete queries ("team meeting laptop", "artisan bread bakery"). Results are cached for a day so 3-variant runs and similar briefs don\'t re-spend budget.', 'beavermind' ); ?></p>
			<p><?php esc_html_e( 'Photographer credit is rendered in a small centered line in the page footer. Pexels\'s license doesn\'t legally require attribution — we give it anyway because it\'s the right thing to do.', 'beavermind' ); ?></p>

			<h2 id="refine"><?php esc_html_e( 'Refining an existing page', 'beavermind' ); ?></h2>
			<p><?php
				printf(
					wp_kses_post( __( 'BeaverMind stores the plan it generated for every page. Open <a href="%s">Refine</a>, pick a previously-generated page, and describe a change like <em>"add a testimonial after the hero, drop the FAQ, make the hero more playful"</em>. Claude sees the prior plan as context and returns a modified plan that gets applied in place.', 'beavermind' ) ),
					esc_url( admin_url( 'admin.php?page=' . RefineGenerator::SLUG ) )
				);
			?></p>
			<p><?php esc_html_e( 'Only pages BeaverMind generated appear as refinable — we need the prior plan as context. Hand-edited BB pages aren\'t refinable yet.', 'beavermind' ); ?></p>

			<h2 id="multipage"><?php esc_html_e( 'Multi-page generation', 'beavermind' ); ?></h2>
			<p><?php
				printf(
					wp_kses_post( __( '<a href="%s">Multi-page</a> takes a sitemap URL or a list of URLs (one per line) and runs each through the clone pipeline. Capped at 10 URLs per run. Useful for porting a multi-page reference site or seeding a fresh site with a consistent set of pages.', 'beavermind' ) ),
					esc_url( admin_url( 'admin.php?page=' . MultipageGenerator::SLUG ) )
				);
			?></p>
			<p><?php esc_html_e( 'Sitemap parsing handles both regular sitemap.xml files and sitemap-index files. Per-URL failures are isolated — one bad fetch doesn\'t block the others.', 'beavermind' ); ?></p>

			<h2 id="staging"><?php esc_html_e( 'Push to staging', 'beavermind' ); ?></h2>
			<p><?php
				printf(
					wp_kses_post( __( 'Configure your staging site\'s URL and a WP Application Password in <a href="%1$s">Settings → Integrations</a>, then visit <a href="%2$s">Push to Staging</a> and click Push next to any BeaverMind-generated page.', 'beavermind' ) ),
					esc_url( admin_url( 'admin.php?page=' . Settings::MENU_SLUG ) ),
					esc_url( admin_url( 'admin.php?page=' . StagingPusher::SLUG ) )
				);
			?></p>
			<p><?php esc_html_e( 'Staging needs BeaverMind installed and active. The push sends only the plan JSON, not the serialized BB layout — staging rebuilds the layout locally using its own fragment library.', 'beavermind' ); ?></p>

			<h2 id="fragments"><?php esc_html_e( 'Fragment library', 'beavermind' ); ?></h2>
			<p><?php esc_html_e( 'BeaverMind ships 10 hand-built fragments spanning hero, features, content, social-proof, and CTA categories. Claude composes pages by sequencing fragments, then filling their declared content slots.', 'beavermind' ); ?></p>
			<p><?php esc_html_e( 'Designers can extend the library by designing rows in the BB editor, exporting them as .dat files, and dropping them in the plugin\'s library/ directory along with metadata in fragments.json. The full workflow is documented at:', 'beavermind' ); ?></p>
			<p><a href="https://github.com/Dependent-Media/beavermind/blob/main/docs/fragment-workflow.md" target="_blank" rel="noopener noreferrer"><code>docs/fragment-workflow.md</code></a></p>

			<h2 id="settings-ref"><?php esc_html_e( 'Settings reference', 'beavermind' ); ?></h2>
			<table class="bm-grid">
				<thead><tr>
					<th><?php esc_html_e( 'Setting', 'beavermind' ); ?></th>
					<th><?php esc_html_e( 'Required', 'beavermind' ); ?></th>
					<th><?php esc_html_e( 'What it does', 'beavermind' ); ?></th>
				</tr></thead>
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'API Key', 'beavermind' ); ?></strong></td>
						<td><?php esc_html_e( 'Yes', 'beavermind' ); ?></td>
						<td><?php
							printf(
								wp_kses_post( __( 'Anthropic API key (starts with %1$s). Get one at the <a href="%2$s" target="_blank" rel="noopener noreferrer">Anthropic Console</a>.', 'beavermind' ) ),
								'<code>sk-ant-</code>',
								esc_url( 'https://console.anthropic.com/settings/keys' )
							);
						?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Model', 'beavermind' ); ?></strong></td>
						<td><?php esc_html_e( 'Yes (default)', 'beavermind' ); ?></td>
						<td><?php esc_html_e( 'Claude Opus 4.7 (highest quality, recommended), Sonnet 4.6 (balanced), or Haiku 4.5 (fastest, cheapest).', 'beavermind' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Figma PAT', 'beavermind' ); ?></strong></td>
						<td><?php esc_html_e( 'Only for Figma input', 'beavermind' ); ?></td>
						<td><?php
							printf(
								wp_kses_post( __( 'Figma personal access token with file_read scope. Generate at <a href="%s" target="_blank" rel="noopener noreferrer">figma.com → Account settings → Personal access tokens</a>.', 'beavermind' ) ),
								esc_url( 'https://www.figma.com/developers/api#access-tokens' )
							);
						?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Pexels API key', 'beavermind' ); ?></strong></td>
						<td><?php esc_html_e( 'Optional', 'beavermind' ); ?></td>
						<td><?php
							printf(
								wp_kses_post( __( 'When set, any empty or placeholder image slot in a generated page is filled with a free stock photo from <a href="%s" target="_blank" rel="noopener noreferrer">Pexels</a>. Photographer credit is rendered in the frontend footer. Free tier is 200 req/hour, 20k/month — more than enough for typical use.', 'beavermind' ) ),
								esc_url( 'https://www.pexels.com/api/' )
							);
						?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Staging URL / Username / App Password', 'beavermind' ); ?></strong></td>
						<td><?php esc_html_e( 'Only for Push to Staging', 'beavermind' ); ?></td>
						<td><?php esc_html_e( 'Receiving site\'s URL and an admin user\'s WP Application Password (Users → Profile → Application Passwords on the staging site).', 'beavermind' ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2 id="dev"><?php esc_html_e( 'For developers', 'beavermind' ); ?></h2>
			<p><?php esc_html_e( 'BeaverMind is a fragment composer, not a raw HTML generator. The architectural reasoning, file layout, and full developer onboarding are in the README:', 'beavermind' ); ?></p>
			<p><a href="https://github.com/Dependent-Media/beavermind#readme" target="_blank" rel="noopener noreferrer">github.com/Dependent-Media/beavermind</a></p>
			<p><?php esc_html_e( 'Notable extras:', 'beavermind' ); ?></p>
			<ul>
				<li><strong><?php esc_html_e( 'Sample plans', 'beavermind' ); ?></strong> — <code>docs/samples/landing-beavermind.json</code> is a real captured plan. Apply with <code>wp eval-file bin/apply-sample-plan.php docs/samples/landing-beavermind.json</code>.</li>
				<li><strong><?php esc_html_e( 'Native macOS TestRunner', 'beavermind' ); ?></strong> — <code>_TestRunner/TestRunner.xcodeproj</code> is a SwiftUI app that runs the Playwright suite with live console + per-test screenshots + finish notifications.</li>
				<li><strong><?php esc_html_e( 'REST API', 'beavermind' ); ?></strong> — <code>POST /wp-json/beavermind/v1/apply-plan</code> applies a plan JSON. Used by Push to Staging; available for any cross-site or external integration.</li>
				<li><strong><?php esc_html_e( 'Releases', 'beavermind' ); ?></strong> — <code>bash bin/release.sh 0.2.0</code> bumps the version, tags, and triggers a GitHub Actions workflow that publishes a distributable zip to GitHub Releases.</li>
			</ul>

			<h2 id="troubleshooting"><?php esc_html_e( 'Troubleshooting', 'beavermind' ); ?></h2>
			<table class="bm-grid">
				<thead><tr>
					<th><?php esc_html_e( 'Symptom', 'beavermind' ); ?></th>
					<th><?php esc_html_e( 'Likely cause / fix', 'beavermind' ); ?></th>
				</tr></thead>
				<tbody>
					<tr>
						<td><code>Claude API key is not configured</code></td>
						<td><?php
							printf(
								wp_kses_post( __( 'Set it in <a href="%s">BeaverMind settings</a>.', 'beavermind' ) ),
								esc_url( admin_url( 'admin.php?page=' . Settings::MENU_SLUG ) )
							);
						?></td>
					</tr>
					<tr>
						<td><code>Claude API is temporarily overloaded</code></td>
						<td><?php esc_html_e( 'Anthropic capacity is bursty. The SDK retries automatically; if you still see this, wait a minute and try again.', 'beavermind' ); ?></td>
					</tr>
					<tr>
						<td><code>Claude API rejected the API key</code></td>
						<td><?php esc_html_e( 'Key is invalid or revoked. Generate a fresh one and paste it back into settings.', 'beavermind' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Generation succeeds but the page looks unstyled / wrong', 'beavermind' ); ?></td>
						<td><?php esc_html_e( 'Beaver Builder Pro must be active. Check the environment block on the BeaverMind settings page — it shows whether BB, Themer, UABB, and PowerPack are detected.', 'beavermind' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'From Image fails with "Image too large"', 'beavermind' ); ?></td>
						<td><?php esc_html_e( 'Anthropic\'s per-image limit is ~5 MB encoded. Resize to under 3.5 MB raw.', 'beavermind' ); ?></td>
					</tr>
					<tr>
						<td><code>Figma fetch failed: Figma API error</code></td>
						<td><?php esc_html_e( 'PAT missing or doesn\'t have file_read scope, or the Figma file isn\'t accessible to that account. Test with a frame URL you can open as the same Figma user.', 'beavermind' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Push to staging fails with HTTP 401', 'beavermind' ); ?></td>
						<td><?php esc_html_e( 'Application Password is wrong, or the staging user can\'t edit_pages. Generate a new app password on staging (Users → Profile → Application Passwords) and re-paste.', 'beavermind' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Images are via.placeholder.com URLs', 'beavermind' ); ?></td>
						<td><?php
							printf(
								wp_kses_post( __( 'No Pexels API key is set, so image slots fall back to placeholders. Set one in <a href="%s">Settings → Integrations</a>.', 'beavermind' ) ),
								esc_url( admin_url( 'admin.php?page=' . Settings::MENU_SLUG ) )
							);
						?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Image came back generic or off-topic', 'beavermind' ); ?></td>
						<td><?php esc_html_e( 'Tighten the brief. Pexels search uses 2-4 word queries distilled by Haiku from the brief — if the brief is vague ("a landing page for a startup"), the queries will be generic. Name the product or the subject and Pexels gets better at picking a photo.', 'beavermind' ); ?></td>
					</tr>
				</tbody>
			</table>

			<p style="margin-top: 2rem; color: #50575e; font-size: 13px;">
				<?php
				$version = defined( 'BEAVERMIND_VERSION' ) ? BEAVERMIND_VERSION : '?';
				printf(
					wp_kses_post( __( 'BeaverMind %1$s · <a href="%2$s" target="_blank" rel="noopener noreferrer">source on GitHub</a> · not affiliated with FastLine Media or the Beaver Builder team', 'beavermind' ) ),
					esc_html( $version ),
					esc_url( 'https://github.com/Dependent-Media/beavermind' )
				);
				?>
			</p>
		</div>
		<?php
	}
}
