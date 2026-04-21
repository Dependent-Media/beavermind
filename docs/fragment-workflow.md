# Fragment authoring workflow

BeaverMind's fragment library is the visual palette Claude composes pages from. There are two ways to add a fragment:

1. **Inline (PHP)** — for plugin authors. Add to `includes/class-inline-fragments.php`.
2. **`.dat` (Beaver Builder editor)** — for designers. Build the row visually, export, drop in `library/`.

Both shapes flow through the same runtime (`FragmentLibrary` merges them on boot; on ID collision, `.dat` wins).

---

## Path 1 — Inline (plugin author)

Open `includes/class-inline-fragments.php` and add a new method that returns `['meta' => [...], 'nodes' => [...]]`. Reference it from `all_fragments()`. The node-shape helpers at the bottom (`row_node`, `group_node`, `col_node`, `module_node`) keep the boilerplate small.

Each fragment's `meta` exposes:

- `name` — human-readable label (also used to derive the `.dat` ID if exported)
- `category` — `hero` / `features` / `content` / `social-proof` / `cta` (free-form; surfaces in Claude's catalog)
- `description` — sentence Claude reads when picking fragments
- `slots` — `{ slot_name: { node: <orig_node_id>, field: <settings_field> } }`
- `theme_bindings` — `{ theme_key: [ {node, field}, ... ] }` (optional)

Slot resolution is by ORIGINAL node ID (the helper hardcodes them per fragment). `LayoutWriter` keeps an old → new ID map after cloning so resolution stays deterministic.

---

## Path 2 — `.dat` (designer)

The visual workflow:

1. Open the BB editor on any page (test site is fine).
2. Design a row exactly the way you want it — use the modules you want, set padding/colors/typography to whatever the fragment's defaults should be.
3. Right-click the row → **Save as → Row template**. Give it a clear name; the slugified name becomes the fragment ID.
4. **Tools → Templates → Beaver Builder Templates**. Find your template. Use the **Template Data Exporter** to download a `.dat` containing it.
5. Drop the `.dat` in this plugin's `library/` directory. Filename can be anything ending in `.dat`; `FragmentLibrary` globs the directory.
6. Author the metadata in `library/fragments.json`, keyed by the slugified name:

   ```json
   {
     "your-template-slug": {
       "name": "Your Template",
       "category": "hero",
       "description": "What this fragment is for; when Claude should pick it.",
       "slots": {
         "headline":  { "node": "<bb-node-id>", "field": "heading" },
         "cta_label": { "node": "<bb-node-id>", "field": "text" }
       },
       "theme_bindings": {
         "primary": [ { "node": "<bb-node-id>", "field": "bg_color" } ]
       }
     }
   }
   ```

   The node IDs are BB's auto-generated 13-char `uniqid()` values. Find them by opening the saved template in BB and inspecting `_fl_builder_data` post meta (any wp-cli `wp post meta get <id> _fl_builder_data` works), or by hovering nodes in the BB editor's outline panel.

7. Activate. The new fragment appears in the BeaverMind catalog and Claude can pick it.

### Helper: regenerating `fragments.dat` from the inline library

If you want a `.dat` that mirrors the built-in inline fragments — useful as a starting point or for porting the library to another site — run:

```bash
ssh <site> 'bash -lc "cd ~/httpdocs && wp eval-file wp-content/plugins/beavermind/bin/export-fragments-to-dat.php"'
```

The script writes `library/fragments.dat` + `library/fragments.json` and verifies every fragment round-trips. Note that the slugified-name IDs in the generated `.dat` don't match the original inline IDs (`centered-hero` vs `hero-centered`); see the WARN lines in the script's output. This is intentional — to avoid double-loading every fragment under two IDs, neither file is shipped with the plugin.

---

## What FragmentLibrary actually does at boot

- Reads `library/*.dat` (any filename, `.dat` extension) — each `.dat` is a serialized `['layout' => [...], 'row' => [...], 'module' => [...]]` array. Each entry's `name` is slugified into the fragment ID; `nodes` becomes the cloneable tree.
- Reads `library/fragments.json` — one big dict keyed by fragment ID, each value is the metadata block (slots, theme_bindings, etc.).
- Merges with inline fragments registered via `register_inline()`. On ID collision, `.dat` wins.

There's no admin UI for authoring fragments yet — that's a future enhancement. For now the workflow is "design in BB, export, write JSON".

---

## Why we don't lean on BB's `FLBuilder::register_templates()`

Earlier versions of `FragmentLibrary` registered `.dat` files via BB's own `register_templates()` so they'd appear in BB's template panel. Two reasons we stopped:

1. **It creates `fl-builder-template` posts** on every load — a slow, side-effecting boot step that's invisible from BeaverMind's surface.
2. **It exposes our internal fragments in the BB UI**, where users could insert them directly into pages, bypassing the plan + slot-fill pipeline.

We unserialize `.dat` files directly and treat each entry as a portable node tree. BB's template UI stays clean.
