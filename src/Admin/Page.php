<?php
/**
 * Admin page registration and asset loading.
 *
 * @package DementorBlocks
 */

declare(strict_types=1);

namespace DementorBlocks\Admin;

final class Page {
	private string $hook = '';

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function register_menu(): void {
		$this->hook = add_management_page(
			__( 'Dementor Blocks', 'dementor-blocks' ),
			__( 'Dementor Blocks', 'dementor-blocks' ),
			'manage_options',
			'dementor-blocks',
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		echo '<div class="wrap dementor-blocks-admin"><div id="dementor-blocks-root"></div></div>';
	}

	public function enqueue( string $hook ): void {
		if ( $hook !== $this->hook ) {
			return;
		}

		$asset_file = DEMENTOR_BLOCKS_PATH . 'build/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [ 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' ],
				'version'      => DEMENTOR_BLOCKS_VERSION,
			];

		wp_enqueue_script(
			'dementor-blocks-admin',
			DEMENTOR_BLOCKS_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'dementor-blocks-admin',
			DEMENTOR_BLOCKS_URL . 'assets/admin.css',
			[ 'wp-components' ],
			DEMENTOR_BLOCKS_VERSION
		);

		if ( file_exists( DEMENTOR_BLOCKS_PATH . 'build/index.css' ) ) {
			wp_enqueue_style(
				'dementor-blocks-admin-app',
				DEMENTOR_BLOCKS_URL . 'build/index.css',
				[ 'dementor-blocks-admin', 'wp-components' ],
				$asset['version']
			);
		}

		wp_localize_script(
			'dementor-blocks-admin',
			'DementorBlocksBootstrap',
			[
				'restRoot'  => esc_url_raw( rest_url() ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'namespace' => 'dementor-blocks/v1',
			]
		);
	}
}
