<?php
/**
 * Plugin Name:       Dementor Blocks
 * Description:       Audit and convert Elementor-built pages into native WordPress blocks.
 * Version:           0.1.0
 * Plugin URI:        https://sirwatson.com
 * Author:            PM Dawn
 * Author URI:        https://sirwatson.com/
 * Text Domain:       dementor-blocks
 * Domain Path:       /languages/
 * Requires at least: 7.0
 * Requires PHP:      8.4
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 *
 * @package DementorBlocks
 */

declare(strict_types=1);

namespace DementorBlocks;

use DementorBlocks\MetaKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DEMENTOR_BLOCKS_FILE', __FILE__ );
define( 'DEMENTOR_BLOCKS_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEMENTOR_BLOCKS_URL', plugin_dir_url( __FILE__ ) );
define( 'DEMENTOR_BLOCKS_VERSION', '0.1.0' );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'DementorBlocks\\';
		$length = strlen( $prefix );

		if ( strncmp( $prefix, $class, $length ) !== 0 ) {
			return;
		}

		$relative = substr( $class, $length );
		$file     = DEMENTOR_BLOCKS_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

add_action(
	'init',
	static function (): void {
		load_plugin_textdomain(
			'dementor-blocks',
			false,
			dirname( plugin_basename( DEMENTOR_BLOCKS_FILE ) ) . '/languages'
		);
	},
	5
);

final class Plugin {
	private static ?self $instance = null;

	private Conversion\ElementorParser $parser;
	private Conversion\Auditor $auditor;
	private Conversion\BlockConverter $converter;
	private Conversion\ConversionService $conversion_service;
	private Rest\Controller $rest_controller;
	private Admin\Page $admin_page;
	private Admin\GeneratedCss $generated_css;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->parser             = new Conversion\ElementorParser();
		$this->auditor            = new Conversion\Auditor( $this->parser );
		$this->converter          = new Conversion\BlockConverter( $this->parser );
		$this->conversion_service = new Conversion\ConversionService( $this->auditor, $this->converter );
		$this->rest_controller    = new Rest\Controller( $this->auditor, $this->conversion_service );
		$this->admin_page         = new Admin\Page();
		$this->generated_css      = new Admin\GeneratedCss();

		add_action( 'init', [ $this, 'boot' ] );
	}

	public function boot(): void {
		$this->register_meta();
		$this->admin_page->init();
		$this->generated_css->init();
		$this->rest_controller->init();
	}

	/**
	 * Register every plugin-owned post meta key with an explicit auth_callback so
	 * future code paths (REST block-editor sidebars, third-party meta browsers)
	 * can't accidentally expose our internal audit/conversion state. All keys are
	 * private — they live behind manage_options-gated REST routes — so we also
	 * keep them out of the core REST meta endpoint via show_in_rest = false.
	 */
	private function register_meta(): void {
		$only_admins = static fn (): bool => current_user_can( 'manage_options' );

		$keys = [
			MetaKeys::AUDIT_RESULT       => 'array',
			MetaKeys::CONVERSION_RESULT  => 'array',
			MetaKeys::SOURCE_POST_ID     => 'integer',
			MetaKeys::GENERATED_CSS      => 'string',
			MetaKeys::PRE_REPLACE_BACKUP => 'array',
		];

		foreach ( $keys as $key => $type ) {
			register_post_meta(
				'page',
				$key,
				[
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => false,
					'auth_callback'     => $only_admins,
					'sanitize_callback' => static function ( $value ) use ( $type ) {
						return match ( $type ) {
							'integer' => (int) $value,
							'string'  => is_string( $value ) ? $value : '',
							'array'   => is_array( $value ) ? $value : [],
							default   => $value,
						};
					},
				]
			);
		}
	}
}

Plugin::instance();
