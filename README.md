# BeaverMind

AI-powered design automation for Beaver Builder. BeaverMind asks [Claude](https://claude.com) to compose a WordPress page by selecting fragments from a curated Beaver Builder template library and filling their content slots — rather than asking the model to generate raw Beaver Builder node JSON.

**Not affiliated with FastLine Media or the Beaver Builder team.** "Beaver Builder" is their trademark; BeaverMind is a third-party extension.

## Why fragment composition instead of raw generation?

Beaver Builder stores layouts as a flat PHP-serialized array of node objects (`row → column-group → column → module`) in the `_fl_builder_data` post meta key. Even the simplest module (`heading`) has ~45 fields including responsive variants. For a full page across BB Pro + UABB + PowerPack's 192-module palette, asking Claude to produce raw node JSON:

- blows the token budget (~50K output per page)
- hallucinates module slugs or omits required responsive siblings
- drifts visually between requests

Instead, BeaverMind ships a small library of hand-designed fragments (hero, feature grid, CTA banner, etc.), each with declared **content slots**. Claude's job shrinks to two decisions:

1. **Plan** — pick 2–6 fragments from the catalog.
2. **Fill** — write copy for each slot.

PHP handles the rest: clone the fragment's node tree, re-key node IDs for uniqueness, patch the slot fields, and hand it to `FLBuilderModel::update_layout_data()`.

The result: ~3K tokens per page, visually consistent output, and upgrade-safe across UABB / PowerPack module changes.

## Stack

- WordPress 6.0+
- PHP 7.4+ (tested on 8.3)
- Beaver Builder Pro 2.10+
- Beaver Themer (optional, used on the target site)
- Ultimate Addons for Beaver Builder (UABB) Pro (optional)
- PowerPack for Beaver Builder (optional)
- An Anthropic API key ([console.anthropic.com](https://console.anthropic.com/settings/keys))

## Install

### From a release zip (end users)

1. Download the latest release.
2. In WordPress admin → Plugins → Add New → Upload Plugin, install the zip.
3. Activate.
4. Settings → **BeaverMind** → paste your Claude API key.

### From source (developers)

```bash
git clone https://github.com/Dependent-Media/beavermind.git wp-content/plugins/beavermind
cd wp-content/plugins/beavermind
composer install --no-dev
```

`vendor/` is committed so most users can skip the Composer step, but run it if you modify `composer.json`.

## Usage

Three admin pages under **BeaverMind**:

| Page | What it does |
|---|---|
| **BeaverMind** | Settings (API key, model) + environment check (detects BB, Themer, UABB, PowerPack). |
| **Test Generator** | Hardcoded fragment → page, no AI. Use to verify the write loop is healthy. |
| **Generate** | Free-text brief → Claude plans & fills → draft page. |
| **Clone from URL** | Paste a URL → BeaverMind fetches, extracts content, asks Claude to redesign → draft page. |

Every generated page is stored as a **draft** by default — review in the Beaver Builder editor before publishing.

## How it works

```
┌──────────┐   ┌──────────────┐   ┌──────────┐   ┌──────────────┐   ┌─────────┐
│ Admin UI │──▶│  SiteCloner  │──▶│ Planner  │──▶│ LayoutWriter │──▶│ WP post │
│  (URL)   │   │ fetch+extract│   │  Claude  │   │ clone + slot │   │ (draft) │
└──────────┘   └──────────────┘   └──────────┘   └──────────────┘   └─────────┘
```

- `SiteCloner` — fetches a URL with `wp_remote_get` and walks the DOM into structured sections (headings, paragraphs, CTAs, images).
- `Planner` — calls the Anthropic Messages API with Opus 4.7, adaptive thinking, a cache breakpoint on the fragment catalog, and a strict JSON schema via `output_config.format`. Returns a plan shaped for LayoutWriter.
- `LayoutWriter` — clones the chosen fragment(s) from the library, re-keys node IDs for uniqueness, patches slot fields, then writes the merged layout to `_fl_builder_data`.
- `FragmentLibrary` — loads fragments from an inline PHP registry and/or a `.dat` file registered via `FLBuilder::register_templates()`. See [`includes/class-inline-fragments.php`](includes/class-inline-fragments.php) for the current bootstrap library.

## Development

### Repository layout

```
beavermind/
├── beavermind.php              # plugin entry (WP headers, bootstrap)
├── composer.json / composer.lock
├── vendor/                     # anthropic-ai/sdk + PSR-17/18 deps (committed)
├── includes/                   # plugin classes
│   ├── class-beavermind.php    # singleton, dependency wiring
│   ├── class-settings.php
│   ├── class-fragment-library.php
│   ├── class-inline-fragments.php
│   ├── class-layout-writer.php
│   ├── class-claude-client.php
│   ├── class-planner.php
│   ├── class-site-cloner.php
│   ├── class-test-page-generator.php
│   ├── class-prompt-generator.php
│   └── class-clone-generator.php
├── library/
│   └── module-catalog.json     # BB + UABB + PowerPack module inventory
└── tests/
    ├── *.php                   # wp-cli smoke scripts (run via `wp eval-file`)
    ├── fixtures/               # reference HTML for clone tests
    └── playwright/             # E2E tests (TypeScript, Playwright)
```

### Running the wp-cli smoke tests

From your WordPress install:

```bash
wp eval-file wp-content/plugins/beavermind/tests/smoke_fragment_library.php
wp eval-file wp-content/plugins/beavermind/tests/smoke_clone_pipeline.php   # requires API key set
wp eval-file wp-content/plugins/beavermind/tests/enumerate_modules.php
wp eval-file wp-content/plugins/beavermind/tests/inspect_planner_prompt.php
```

### Running the Playwright E2E test locally

```bash
cd tests/playwright
cp .env.example .env            # then edit with your target site details
npm install
npx playwright install chromium
bash scripts/generate-auth-state.sh   # mints a WP auth cookie via wp-cli over SSH
npx playwright test
npx playwright show-report             # interactive HTML report with trace
```

The test records a video per run under `test-results/` so you can see the full clone flow.

### Continuous integration

Two workflows under `.github/workflows/`:

- **CI** (`ci.yml`) — PHP syntax lint + `composer validate`. Runs on every PR and push to `main`. Required before merge.
- **E2E** (`e2e.yml`) — Playwright clone-from-URL spec. Runs on PRs touching plugin source, and on manual dispatch. Uploads video + screenshots as artifacts. Requires SSH secrets (see `docs/ci-setup.md`).

### Architecture notes

Detailed internal notes (Beaver Builder data model, node structure, module catalog quirks) live in the maintainer's local memory and in commit history. Key design decisions:

- **Fragment composition over raw generation** — see the rationale at the top of this README.
- **`.dat` + inline registration parity** — `FragmentLibrary` treats inline PHP fragments and `.dat`-shipped fragments identically. Bootstrap with inline, scale with `.dat`.
- **Content slots are addressed by original node ID** — when `LayoutWriter` clones a fragment, it keeps an old-id → new-id map so slot resolution stays deterministic.
- **Cookie-based test auth** — Playwright skips the WP login UI (brittle across security plugins) by pre-loading an auth cookie minted via `wp-cli`. See `tests/playwright/scripts/generate-auth-state.sh`.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
