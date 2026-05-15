<?php
/**
 * Reads Elementor document JSON from post meta.
 *
 * @package DementorBlocks
 */

declare(strict_types=1);

namespace DementorBlocks\Conversion;

use DementorBlocks\MetaKeys;
use WP_Post;

final class ElementorParser {
	/**
	 * @return array{ok:bool,data:array<int,array<string,mixed>>,error:string|null,raw:string}
	 */
	public function parse_post( int|WP_Post $post ): array {
		$post_id = $post instanceof WP_Post ? (int) $post->ID : $post;
		$raw     = get_post_meta( $post_id, MetaKeys::ELEMENTOR_DATA, true );

		if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
			return [
				'ok'    => false,
				'data'  => [],
				'error' => __( 'No Elementor data found.', 'dementor-blocks' ),
				'raw'   => '',
			];
		}

		$decoded = json_decode( wp_unslash( $raw ), true );

		if ( ! is_array( $decoded ) ) {
			return [
				'ok'    => false,
				'data'  => [],
				'error' => __( 'Elementor data is not valid JSON.', 'dementor-blocks' ),
				'raw'   => $raw,
			];
		}

		return [
			'ok'    => true,
			'data'  => $decoded,
			'error' => null,
			'raw'   => $raw,
		];
	}

	public function has_elementor_data( int $post_id ): bool {
		$raw = get_post_meta( $post_id, MetaKeys::ELEMENTOR_DATA, true );

		return is_string( $raw ) && trim( $raw ) !== '';
	}

	/**
	 * @param array<int,array<string,mixed>> $nodes
	 * @return array<int,array<string,mixed>>
	 */
	public function flatten( array $nodes ): array {
		$flat = [];

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$flat[] = $node;

			if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$flat = array_merge( $flat, $this->flatten( $node['elements'] ) );
			}
		}

		return $flat;
	}
}
