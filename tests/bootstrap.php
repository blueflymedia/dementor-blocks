<?php
/**
 * PHPUnit bootstrap for Dementor Blocks.
 *
 * @package DementorBlocks
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php. Have you run wp-env start?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

function _dementor_blocks_manually_load_plugin(): void {
	require dirname( __DIR__ ) . '/dementor-blocks.php';
}
tests_add_filter( 'muplugins_loaded', '_dementor_blocks_manually_load_plugin' );

require "{$_tests_dir}/includes/bootstrap.php";
