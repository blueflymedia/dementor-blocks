# Dementor Blocks Specification

Dementor Blocks is a WordPress plugin that helps administrators migrate Elementor-built pages into native Gutenberg block content. V1 targets WordPress 7.0+ and PHP 8.4+, works without Elementor active, reads stored `_elementor_data`, audits page migration readiness, then converts supported Elementor structures into portable core blocks.

## V1 Scope

- Scan standard WordPress pages only.
- Parse Elementor data from `_elementor_data` post meta.
- Show an admin-only audit table with readiness score, level, warnings, and conversion status.
- Convert selected pages through REST-driven chunks.
- Let admins create a duplicate draft or replace the original page.
- Preserve content and common visual styling where practical.
- Warn about unsupported widgets and Elementor/global CSS dependencies.

## Implementation

- PHP namespace: `DementorBlocks\`
- Text domain: `dementor-blocks`
- REST namespace: `dementor-blocks/v1`
- Admin UI: React through `@wordpress/scripts`
- Storage: latest audit/conversion result in post meta
- External services/MCP: none required in v1
- Local WordPress plugin path: `/Users/mothership/Local Sites/stagingdev/app/public/wp-content/plugins/dementor-blocks`

## Readiness

Audit results include a numeric score from 0-100 and one of three levels: `Ready`, `Review Needed`, or `Manual Rebuild`. Unsupported widgets, complex layouts, invalid JSON, missing media, and Elementor/global CSS dependencies reduce score.

## Conversion

Supported mappings include groups, columns, headings, text, images, buttons, spacers, separators, lists, embeds, and shortcodes. Unsupported widgets become Custom HTML fallback blocks with warnings.

Style migration modes are `none`, `inline`, and `css`; `inline` is the default. Generated CSS is stored per converted page in `_dementor_blocks_generated_css`.
