<?php
/**
 * Dementor Blocks uninstall handler.
 *
 * Runs once when the plugin is deleted from the WP admin (not on simple
 * deactivation). Removes all post meta the plugin wrote so nothing lingers in
 * the database. Elementor's own meta (`_elementor_data`, `_elementor_css`) is
 * intentionally left alone — Elementor owns those and uninstalling this plugin
 * shouldn't damage Elementor pages.
 *
 * @package DementorBlocks
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$meta_keys = [
	'_dementor_blocks_audit_result',
	'_dementor_blocks_conversion_result',
	'_dementor_blocks_source_post_id',
	'_dementor_blocks_generated_css',
	'_dementor_blocks_pre_replace_backup',
];

foreach ( $meta_keys as $key ) {
	$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $key ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
}
