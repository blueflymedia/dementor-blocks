<?php
/**
 * Enqueues generated per-page conversion CSS.
 *
 * @package DementorBlocks
 */

declare(strict_types=1);

namespace DementorBlocks\Admin;

use DementorBlocks\MetaKeys;

final class GeneratedCss {
	public function init(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ], 20 );
	}

	public function enqueue(): void {
		if ( ! is_singular( 'page' ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		$css     = get_post_meta( $post_id, MetaKeys::GENERATED_CSS, true );

		if ( ! is_string( $css ) || trim( $css ) === '' ) {
			return;
		}

		wp_register_style( 'dementor-blocks-generated', false, [], DEMENTOR_BLOCKS_VERSION );
		wp_enqueue_style( 'dementor-blocks-generated' );
		wp_add_inline_style( 'dementor-blocks-generated', $css );
	}
}
