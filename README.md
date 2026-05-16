# Dementor Blocks

Audit and convert Elementor-built pages into native WordPress blocks. Works without Elementor active — Dementor Blocks reads stored Elementor data straight from post meta, scores each page's migration readiness, and converts supported Elementor structures into portable core blocks.

> **Status:** v0.1 · early development · targets the upcoming WordPress 7.0 release and PHP 8.4.

## What it does

- **Scans** standard WordPress pages for stored Elementor data (`_elementor_data` post meta).
- **Audits** each page with a 0–100 readiness score and a `Ready` / `Review Needed` / `Manual Rebuild` level, plus warnings for unsupported widgets and CSS dependencies.
- **Converts** sections, containers, columns, headings, text, images, buttons, spacers, separators, lists, embeds, and shortcodes into native blocks (`core/group`, `core/columns`, `core/heading`, `core/paragraph`, `core/image`, `core/buttons`, `core/embed`, etc.).
- **Preserves layout nesting** correctly, including Elementor flex containers and inner sections.
- **Falls back gracefully** for unsupported widgets — they become `core/html` blocks tagged with the original widget name, with nested children preserved instead of dropped.
- **Style modes:** `none` (structure only), `inline` (block-level `style` attrs), or `css` (per-page stylesheet stored in post meta and enqueued on the front end).
- **Two destinations:** duplicate to a new draft, or replace the original (with a WP revision + meta backup taken automatically before the overwrite).

## Requirements

- **WordPress** 7.0 or later
- **PHP** 8.4 or later
- Administrator capability (`manage_options`) to access the audit/convert UI

Elementor does **not** need to be active — Dementor Blocks reads Elementor's stored post meta directly.

## Installation

1. Clone or download this repository into `wp-content/plugins/dementor-blocks/`.
2. Run the build step (see [Development](#development) below) so the React admin bundle is available in `build/`.
3. Activate **Dementor Blocks** from the Plugins screen.
4. Open **Tools → Dementor Blocks** to start auditing.

## Usage

1. **Audit.** From the admin screen, run an audit on one or many pages. Each row reports a readiness score, level, widget breakdown, and warnings.
2. **Choose a style mode.** `none` strips visual styling, `inline` writes per-block `style` attrs, `css` emits a scoped stylesheet stored per-page.
3. **Convert.** Pick either:
   - **Duplicate** — a new draft page is created with the converted block content (and excerpt, parent, menu order, featured image, page template all copied over).
   - **Replace** — the original page's content is overwritten. A WordPress revision is taken first, and the prior content is stashed to `_dementor_blocks_pre_replace_backup` for one-click recovery.
4. **Review.** Open the resulting page in Gutenberg, check the conversion warnings, and clean up any unsupported widgets flagged with `core/html` fallbacks.

## REST API

All routes are gated by `manage_options` and live under the `dementor-blocks/v1` namespace:

| Method | Route | Purpose |
| --- | --- | --- |
| `GET`  | `/pages` | Paginated list of pages with Elementor data |
| `POST` | `/audit` | Audit a single page |
| `POST` | `/audit-batch` | Audit up to 50 pages |
| `POST` | `/convert` | Convert a single page |
| `POST` | `/convert-batch` | Convert up to 50 pages |
| `GET`  | `/result/{id}` | Latest stored audit + conversion result |

`/pages` accepts `page` and `per_page` (max 200) and returns `X-WP-Total` / `X-WP-TotalPages` headers, matching core REST conventions.

## Extensibility

Hooks are provided so extensions can plug in without forking:

```php
// Register additional widget types as natively supported.
add_filter( 'dementor_blocks/supported_widgets', function ( $widgets ) {
	$widgets[] = 'accordion';
	return $widgets;
} );

// Convert a custom widget yourself by returning a block array (serialize_blocks() shape).
add_filter( 'dementor_blocks/convert_widget', function ( $block, $widget, $settings, $children, $ctx ) {
	if ( $widget !== 'accordion' ) {
		return $block;
	}
	return [
		'blockName'    => 'core/details',
		'attrs'        => [],
		'innerBlocks'  => [],
		'innerHTML'    => '<details>…</details>',
		'innerContent' => [ '<details>…</details>' ],
	];
}, 10, 5 );

// Map additional Elementor settings onto block attrs.
add_filter( 'dementor_blocks/block_attrs', function ( $attrs, $settings, $ctx ) {
	if ( ! empty( $settings['border_radius']['size'] ) ) {
		$attrs['style']['border']['radius'] = $settings['border_radius']['size'] . 'px';
	}
	return $attrs;
}, 10, 3 );

// Route conversion failures to your logger of choice.
add_action( 'dementor_blocks/conversion_failed', function ( $post_id, $warnings, $result ) {
	error_log( "Dementor Blocks: conversion failed for {$post_id} — " . implode( '; ', $warnings ) );
}, 10, 3 );
```

## Development

```bash
# install PHP + JS deps
composer install
npm install

# build the React admin bundle
npm run build

# watch mode
npm run start
```

### Tests

| Suite | Command |
| --- | --- |
| PHP unit tests (WP test suite) | `composer test` |
| JS unit tests (Jest) | `npm run test:js` |
| E2E in WordPress Playground (PHP 8.4 / WP latest) | `npm run test:e2e` |

The PHP test suite needs WordPress core checked out and a test database. One-time setup on macOS (with [Homebrew](https://brew.sh) installed):

```bash
brew install mariadb subversion
brew services start mariadb
mariadb -u "$USER" -e "CREATE DATABASE IF NOT EXISTS wordpress_test;"
bash bin/install-wp-tests.sh wordpress_test "$USER" '' localhost latest true
```

After that, `composer test` will run the suite. Subsequent runs are fast — only the first invocation downloads WordPress and the test files.

### Project layout

```
src/
├── Admin/             admin screen + per-page generated CSS enqueue
├── Conversion/        ElementorParser, Auditor, BlockConverter, ConversionService
├── Rest/              REST controller (namespace dementor-blocks/v1)
└── MetaKeys.php       single source of truth for post-meta keys
src-js/                React admin app (built into build/)
tests/                 PHPUnit + Playwright + fixtures
bin/install-wp-tests.sh   canonical WP scaffold installer
```

`CLAUDE.md` in this directory documents the architecture and conventions in more detail (useful if you're contributing).

## License

GPL-2.0-or-later
