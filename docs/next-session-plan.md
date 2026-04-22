# Next-session plan: image pipeline + Brand Kit

Three features queued, in build order. Each gets its own PR.

## Current state baseline (when this was written)

- `main` at `a244aee` — PR #25 (Variants gallery + Enhance Prompt + Quick Refine)
- v0.1.0 published at https://github.com/Dependent-Media/beavermind/releases/tag/v0.1.0
- Five input modalities (Generate / Clone from URL / Paste HTML / From Image / From Figma) + Refine + Multi-page + Push to Staging + Docs
- Required CI: `PHP lint + composer` + `Clone-from-URL E2E` (E2E auto-deploys to testbeavermind on every PR)
- Brand extraction already pulls `site_name`, `theme_color`, `logo_url`, `og_image`, `fonts` from URL inputs (`SiteCloner::extract_brand`) — currently used only as soft hints to Claude. Brand Kit (item 3) promotes this to a first-class persisted artifact.
- Image slots exist in fragments (`image_url` / `image_alt` on `hero-with-image` and `image-text-split`); right now they get filled with whatever Claude finds in the reference content (URL `<img src>`s) or default to via.placeholder.com URLs from the inline-fragment defaults. **Items 1 and 2 fix that.**

## Feature 1 — Pexels image API (FREE images)

**Goal:** Any unfilled `image_url` slot gets a contextually-relevant free stock photo from Pexels instead of a Lorem-Picsum / placeholder URL.

**Pexels API basics:**
- Free; sign up at https://www.pexels.com/api/ for a key
- Limits: 200 requests/hour, 20,000/month
- Search: `GET https://api.pexels.com/v1/search?query=<terms>&per_page=15&orientation=landscape`
- Auth header: `Authorization: <api_key>` (note: no `Bearer ` prefix — they use the bare key)
- Response: `photos[]` with `src.{original,large2x,large,medium,small,portrait,landscape,tiny}` and `photographer`, `photographer_url`, `url` (Pexels page)
- **Attribution required** per their license: photographer name + link back to Pexels (or back to the source page). Render this in the page footer or near the image, not just in metadata.

**Architecture:**
- New `includes/class-pexels-client.php` — thin REST wrapper, returns `['url'=>..., 'alt'=>..., 'photographer'=>..., 'photographer_url'=>..., 'pexels_url'=>...]`
- New setting: `pexels_api_key` (Settings → Integrations)
- New `Planner::generate_search_terms_for_slot(string $slot_context, string $brief): string` — Haiku call that turns a slot like "image_url for hero on a SaaS scheduler page" into 2-3 concrete search terms ("calendar planning office", "team meeting laptop")
- New `LayoutWriter::fill_image_slots()` post-pass after `apply_slot_overrides` and `apply_theme`: for any `image_url` slot whose value is empty OR matches a placeholder pattern (via.placeholder.com, picsum.photos), call Pexels and replace
- Attribution: append photographer credit to a new fragment-meta field `image_attributions[]` on the post; render via a `wp_footer` action (or as a small caption in the photo module if BB allows)

**UX additions:**
- Settings → Integrations gains "Pexels API key" field with a link to Pexels' developer page (mirror the Figma PAT pattern)
- Settings page environment block adds a Pexels detection row
- Docs page Troubleshooting: "Image came back generic" → tighten the brief; "Image is a placeholder" → Pexels key not set
- Each generator's success notice optionally lists "X images sourced from Pexels"

**Concerns:**
- Rate limits: 200 req/hour. If a user generates 5 variants × 3 images each = 15 calls — fine. Bulk multi-page (10 pages × 5 images) = 50 calls — also fine. Need a soft cache (transient) keyed by search query → photo URL so repeated runs don't re-spend the budget.
- License nuance: Pexels images are free for commercial use without attribution (legally), but credit is requested. We should give it.

**PR #26 scope:** Pexels client + settings field + LayoutWriter post-pass + attribution rendering + smoke test.

## Feature 2 — Native image generation (PAID, configurable provider)

**Goal:** When the user wants a unique generated image (not a Pexels stock photo), generate via a real image model.

**Provider candidates (ranked by my picks):**

| Provider | Model | Cost/image | Speed | Notes |
|---|---|---|---|---|
| **Replicate** | `black-forest-labs/flux-schnell` | $0.003 | ~2s | Cheapest, fast, decent quality. **My default pick.** |
| Replicate | `black-forest-labs/flux-dev` | $0.025 | ~10s | Higher quality |
| Replicate | `black-forest-labs/flux-pro-1.1` | $0.04 | ~10s | Best quality |
| FAL | `fal-ai/flux/schnell` | $0.003 | ~1s | Same model, often faster, easier streaming |
| OpenAI | `gpt-image-1` (low) | $0.011-0.04 | ~5s | Better text-in-image than Flux; needs OpenAI key |
| OpenAI | `gpt-image-1` (high) | $0.04-0.19 | ~15s | High quality |

**Recommendation:** ship with **Replicate + Flux Schnell** as default (cheapest, $0.003 = ~$0.50 per 150 images), with **provider abstraction** so FAL or OpenAI can be plugged in later via setting.

**Architecture:**
- New `includes/class-image-generator.php` — provider-agnostic facade
  ```php
  $img = ImageGenerator::for_settings()->generate('a calm office workspace at sunrise, photoreal');
  // → ['url' => 'https://...', 'provider' => 'replicate', 'model' => 'flux-schnell', 'cost_cents' => 0.3]
  ```
- Provider adapter classes: `class-replicate-adapter.php`, `class-fal-adapter.php`, `class-openai-image-adapter.php`. Each implements `generate(string $prompt, array $opts): array|WP_Error`.
- New settings: `image_provider` (replicate/fal/openai/none), `image_provider_api_key` (per-provider key field)
- Storage: download the generated image, upload to WP media library via `media_sideload_image()` so it survives the provider's URL TTL (Replicate URLs expire after ~1 hour; OpenAI's URLs expire after ~1 hour too)
- Attribution: track `_beavermind_generated_images` post meta with provider + cost so users can audit spend
- **Hybrid strategy with Pexels (#1):** Settings dropdown — "Image strategy: Pexels first (cheap), Generated first (unique), Generated only (skip Pexels)". Default: Pexels first.

**Where in the pipeline:**
- Same `LayoutWriter::fill_image_slots()` from Feature 1, but tries Pexels → falls back to image gen → falls back to placeholder. Or generates per the strategy setting.
- Image-gen happens AFTER Claude returns the plan. Claude doesn't generate images directly; it just describes what each image should depict (we extend the slot value to be either a URL or a `[GENERATE: ...prompt...]` marker).

**Cost UX:**
- Each generator's success notice shows "$0.XX images" alongside the existing Claude usage line
- Settings page shows lifetime image-gen spend (rolled up from post meta)
- Hard cap setting: "max image gen cost per page" (default $0.10) — fall back to Pexels or placeholder above this

**PR #27 scope:** ImageGenerator facade + Replicate adapter + media-library sideload + strategy dropdown + cost tracking.

**PR #27.5 (optional):** OpenAI + FAL adapters for users who prefer those.

## Feature 3 — Brand Kit panel

**Goal:** Promote the brand signals we already extract into a first-class, editable, persistent artifact a user can save and reuse across pages and sites.

**Current state of brand extraction:**
- `SiteCloner::extract_brand` returns `{site_name, theme_color, logo_url, og_image, fonts}`
- Currently piped into the planner as soft hints in the system prompt rule 8
- Lost after the request — not persisted, not reusable

**What to build:**
- New custom post type `bm_brand_kit` with fields stored as post meta:
  - `name` (post title)
  - `site_name` (string)
  - `primary_color`, `bg_dark`, `bg_light`, `text_on_dark`, `text_on_light` (hex strings — same shape as `theme.colors`)
  - `logo_url` (string)
  - `font_heading`, `font_body` (Google Font family names)
  - `voice` (free-text brand voice description, used as system-prompt addendum)
  - `default_cta_url` (string — autofills `cta_url` slots)
  - `extracted_from_url` (provenance, optional)
- New admin page **BeaverMind → Brand Kits** with list + create/edit forms
- "Extract from URL" button on the create form — runs `SiteCloner::extract_brand` and prefills the form
- Color swatch picker for each color field
- Each generator gets a **"Use brand kit"** dropdown above the brief, default = "(none — derive from input)"
- When a brand kit is selected:
  - Planner is told to use the kit's site_name, voice, and CTA URL
  - LayoutWriter overrides `theme.colors` from the kit (kit wins over Claude's derived theme)
  - Logo URL fills any `logo_url` slot in fragments (e.g. logos-row-5 first slot)

**Backwards compat:**
- Existing pages keep their stored plan; no migration needed
- The "(none)" option preserves today's behaviour

**Multi-tenancy story:**
- One brand kit per client. Agencies running 10 client sites create 10 kits.
- "Push to staging" can include the kit (via REST endpoint extension) so staging gets the same brand.

**PR #28 scope:** CPT + Brand Kits admin (list + edit + extract-from-URL) + dropdown on generators + Planner / LayoutWriter override path.

## Build order rationale

1. **Pexels first** because it's free and stops shipping placeholder images immediately. Single API + low complexity. ~half a day.
2. **Image generation second** because it needs the same `LayoutWriter::fill_image_slots()` plumbing Pexels just built — incremental on top. Provider abstraction adds maybe a day.
3. **Brand Kit last** because it's the largest UX surface (CPT + admin + dropdowns on every generator) and it's additive — none of the above depend on it. Worth a focused day.

## Where to start the next session

```bash
cd /Users/joshuajordan/Projects/Code/dm-software/bb-dm-ai-builder
git checkout main && git pull
git checkout -b feat/pexels-images
```

First file to write: `includes/class-pexels-client.php`. The pattern to mirror is `includes/class-figma-fetcher.php` — small REST wrapper with WP_Error returns and `wp_remote_get`.
