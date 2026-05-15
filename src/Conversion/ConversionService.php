<?php
/**
 * Coordinates conversion persistence.
 *
 * @package DementorBlocks
 */

declare(strict_types=1);

namespace DementorBlocks\Conversion;

use DementorBlocks\MetaKeys;
use WP_Error;
use WP_Post;

final class ConversionService {
	public function __construct(
		private readonly Auditor $auditor,
		private readonly BlockConverter $converter
	) {}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public function convert( int $post_id, string $destination = 'duplicate', string $style_mode = 'inline' ): array|WP_Error {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || $post->post_type !== 'page' ) {
			return new WP_Error( 'dementor_blocks_missing_page', __( 'Page not found.', 'dementor-blocks' ), [ 'status' => 404 ] );
		}

		if ( ! in_array( $destination, [ 'duplicate', 'replace' ], true ) ) {
			return new WP_Error( 'dementor_blocks_bad_destination', __( 'Invalid conversion destination.', 'dementor-blocks' ), [ 'status' => 400 ] );
		}

		if ( ! in_array( $style_mode, [ 'none', 'inline', 'css' ], true ) ) {
			return new WP_Error( 'dementor_blocks_bad_style_mode', __( 'Invalid style mode.', 'dementor-blocks' ), [ 'status' => 400 ] );
		}

		$audit      = $this->auditor->audit_post( $post_id );
		$conversion = $this->converter->convert_post( $post_id, $style_mode );

		if ( $conversion['content'] === '' ) {
			$result = $this->result( $post_id, 0, $destination, $style_mode, 'failed', $audit, $conversion['warnings'] );
			update_post_meta( $post_id, MetaKeys::CONVERSION_RESULT, $result );

			/**
			 * Fires when a conversion finishes with no block content. Hook this to
			 * route failures into your logger of choice (Query Monitor, Sentry, etc).
			 *
			 * @param int                 $post_id  Source post being converted.
			 * @param array<int,string>   $warnings Warnings collected during conversion.
			 * @param array<string,mixed> $result   Persisted conversion result envelope.
			 */
			do_action( 'dementor_blocks/conversion_failed', $post_id, $conversion['warnings'], $result );

			return new WP_Error( 'dementor_blocks_empty_conversion', __( 'Conversion produced no block content.', 'dementor-blocks' ), [ 'status' => 422, 'result' => $result ] );
		}

		if ( $destination === 'replace' ) {
			// Belt-and-braces recovery before we overwrite the original post body:
			// (1) Force a WP revision so the prior content is restorable from the
			//     editor's Revisions panel; (2) snapshot the raw content + timestamp
			//     to a dedicated meta key for one-click "Undo Replace" later.
			wp_save_post_revision( $post_id );
			update_post_meta(
				$post_id,
				MetaKeys::PRE_REPLACE_BACKUP,
				[
					'post_content' => $post->post_content,
					'backed_up_at' => gmdate( 'c' ),
				]
			);

			$target_id = wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => $conversion['content'],
				],
				true
			);
		} else {
			$target_id = wp_insert_post(
				[
					'post_type'      => 'page',
					'post_status'    => 'draft',
					'post_title'     => sprintf(
						/* translators: %s: Source page title. */
						__( '%s - Block Draft', 'dementor-blocks' ),
						get_the_title( $post )
					),
					'post_content'   => $conversion['content'],
					'post_excerpt'   => $post->post_excerpt,
					'post_author'    => (int) get_current_user_id(),
					'post_parent'    => (int) $post->post_parent,
					'menu_order'     => (int) $post->menu_order,
					'comment_status' => $post->comment_status,
					'ping_status'    => $post->ping_status,
				],
				true
			);
		}

		if ( is_wp_error( $target_id ) ) {
			$result = $this->result( $post_id, 0, $destination, $style_mode, 'failed', $audit, [ $target_id->get_error_message() ] );
			update_post_meta( $post_id, MetaKeys::CONVERSION_RESULT, $result );

			return $target_id;
		}

		$target_id = (int) $target_id;

		if ( $destination === 'duplicate' ) {
			update_post_meta( $target_id, MetaKeys::SOURCE_POST_ID, $post_id );

			// Carry over the data that lives in post meta but isn't part of the post
			// row: featured image, page template, custom excerpt-on-blocks markers.
			$thumbnail_id = (int) get_post_thumbnail_id( $post_id );
			if ( $thumbnail_id > 0 ) {
				set_post_thumbnail( $target_id, $thumbnail_id );
			}

			$template = (string) get_post_meta( $post_id, '_wp_page_template', true );
			if ( $template !== '' && $template !== 'default' ) {
				update_post_meta( $target_id, '_wp_page_template', $template );
			}

			// Never let Elementor's editor hijack the new block draft, even if some
			// other code path tries to copy meta from the source post later.
			delete_post_meta( $target_id, '_elementor_edit_mode' );
			delete_post_meta( $target_id, '_elementor_data' );
			delete_post_meta( $target_id, '_elementor_css' );
		}

		if ( $style_mode === 'css' && $conversion['generated_css'] !== '' ) {
			update_post_meta( $target_id, MetaKeys::GENERATED_CSS, $conversion['generated_css'] );
		}

		$result = $this->result( $post_id, $target_id, $destination, $style_mode, 'converted', $audit, $conversion['warnings'] );
		update_post_meta( $post_id, MetaKeys::CONVERSION_RESULT, $result );
		update_post_meta( $target_id, MetaKeys::CONVERSION_RESULT, $result );

		return $result;
	}

	/**
	 * @param array<string,mixed> $audit
	 * @param array<int,string>   $warnings
	 * @return array<string,mixed>
	 */
	private function result( int $source_id, int $target_id, string $destination, string $style_mode, string $status, array $audit, array $warnings ): array {
		return [
			'source_post_id' => $source_id,
			'target_post_id' => $target_id,
			'destination'    => $destination,
			'style_mode'     => $style_mode,
			'status'         => $status,
			'audit_score'    => $audit['score'] ?? 0,
			'readiness'      => $audit['readiness'] ?? 'Manual Rebuild',
			'warnings'       => array_values( array_unique( array_merge( $audit['warnings'] ?? [], $warnings ) ) ),
			'errors'         => $status === 'failed' ? $warnings : [],
			'converted_at'   => gmdate( 'c' ),
		];
	}
}
