# CLAUDE.md — Dementor Blocks

Plugin that audits and converts Elementor-built pages into native WordPress block markup. Lives inside the `wordpress-plugins` multi-root workspace; the workspace-level [../CLAUDE.md](../CLAUDE.md) applies on top of this file.

## Platform targets

- **WordPress 7.0** (upcoming release) — not a typo. Use WP 7 APIs freely: `serialize_blocks()` / `serialize_block()`, `WP_HTML_Tag_Processor`, `WP_HTML_Processor`, `safecss_filter_attr`, Block Bindings, Interactivity API, Block Hooks.
- **PHP 8.4** — use property hooks, asymmetric visibility (`public private(set)`), `array_find` / `array_any` / `array_all`, `#[\Deprecated]`, lazy objects (`ReflectionClass::newLazyProxy`), and `new Foo()->bar()` chaining where they clarify intent.
- Do **not** flag the `Requires at least: 7.0` / `Requires PHP: 8.4` headers as mistakes.

## Architecture

Singleton `DementorBlocks\Plugin` in `dementor-blocks.php` instantiates a small service graph on plugin load and wires hooks in `boot()` (runs on `init`):

```
ElementorParser ──┬─► Auditor
                  └─► BlockConverter ──► ConversionService ──► REST\Controller
                                                              Admin\Page
                                                              Admin\GeneratedCss
```

- `src/Conversion/ElementorParser.php` — reads `_elementor_data` post meta and JSON-decodes. `flatten()` is a depth-first walker.
- `src/Conversion/Auditor.php` — scores a page for conversion readiness; declares `SUPPORTED_WIDGETS`. Currently writes audit results to post meta as a side effect (flagged for change).
- `src/Conversion/BlockConverter.php` — turns Elementor nodes into block markup. **Being rewritten around `serialize_blocks()`** (tree of block arrays instead of `sprintf`-ed `<!-- wp:... -->` strings).
- `src/Conversion/ConversionService.php` — orchestrates audit + convert + persistence. `destination ∈ {duplicate, replace}`, `style_mode ∈ {none, inline, css}`.
- `src/Rest/Controller.php` — namespace `dementor-blocks/v1`. All routes gated by `manage_options` via `can_manage`.
- `src/Admin/Page.php` — Tools menu page mounting the React app from `build/index.js`.
- `src/Admin/GeneratedCss.php` — enqueues per-page `_dementor_blocks_generated_css` meta as inline style on the front end.
- `src/MetaKeys.php` — single source of truth for all post-meta keys (`_dementor_blocks_*` and `_elementor_*`).

## Block-output conventions

- Always build blocks as arrays with `blockName / attrs / innerBlocks / innerHTML / innerContent` and emit via `serialize_blocks()`. Do not hand-write `<!-- wp:... -->` comments.
- `serialize_block()` strips the `core/` namespace — output is `<!-- wp:heading -->`, not `<!-- wp:core/heading -->`. Tests must assert the short form.
- For wrapper blocks (group, columns, column, buttons) build `innerContent` as `[openHTML, null, null, …, closeHTML]` — one `null` per inner block.
- CSS-mode style classes are hashed `dementor-blocks-style-<10-char-md5>` and must be deduped per `convert_post()` call (track emitted classes on the converter instance).

## Elementor → Gutenberg mapping rules

- `elType: section` — children are `column` nodes; bundle them into a single `core/columns`. The section itself becomes `core/group`.
- `elType: container` — flex container; children are typically other containers or widgets. Becomes `core/group` (later: with `layout` attr derived from `flex_direction`). Do **not** force a `core/columns` wrapper.
- `elType: column` — emits `core/column`. Orphan columns (no section parent) must still be wrapped in `core/columns` defensively or they fail block validation.
- `elType: widget` — dispatched by `widgetType` in `BlockConverter::convert_widget()`. Unknown widgets fall back to `core/html` and **must** recurse into `elements` so nested children aren't lost.

## Security invariants

Inherited from workspace CLAUDE.md, repeated here because they're easy to break in this codebase:
- Every REST `permission_callback` is explicit (`can_manage`), never `return true`.
- Every REST `args` entry has a `sanitize_callback`. Batch endpoints must also have an `args` schema for `post_ids` and a hard size cap.
- Generated CSS sent to `wp_add_inline_style` must go through `safecss_filter_attr` (or equivalent) per declaration — values originate from `sanitize_text_field` fallbacks that don't strip `;` or `}`.
- `register_post_meta` (when added) uses `auth_callback`; raw `$wpdb` (none yet) uses `$wpdb->prepare`.

## Build / test commands

Run from this directory:

- `npm run build` — `@wordpress/scripts` build of the React admin app into `build/`.
- `npm run start` — watch mode.
- `composer install` then `vendor/bin/phpunit` — PHP unit tests in `tests/`.
- `npx playwright test` — E2E against `wp-now`/Playground (config in `playwright.config.js`).
- Jest tests live in `__tests__/`.

The plugin **must** be built before the React admin renders (`build/index.asset.php` is required by `Admin\Page::enqueue`). If you're testing PHP-only paths, that's irrelevant.

## Known active work

See [SPECS.md](SPECS.md) for product scope. Live refactor in flight:

1. `BlockConverter` rewrite around `serialize_blocks()` — fixes double-conversion of section/container children, orphan-column emission, and CSS dedupe.
2. Then widget HTML generation moves to `WP_HTML_Tag_Processor` (image, button, text-editor).
3. Then `GeneratedCss` runs declarations through `safecss_filter_attr`.
4. Audit/conversion result shapes get promoted from `array<string,mixed>` to PHP 8.4 classes with property hooks; `SUPPORTED_WIDGETS` is shared between `Auditor` and `BlockConverter` to prevent drift.

Per the workspace rule: when something ships and doesn't work, log it in `bugs.md` with the error and attempted fix; update with the real fix when found.
