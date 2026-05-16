<?php
/**
 * Scores Elementor pages for block conversion readiness.
 *
 * @package DementorBlocks
 */

declare(strict_types=1);

namespace DementorBlocks\Conversion;

use DementorBlocks\MetaKeys;
use WP_Post;

final class Auditor {
	public function __construct( private readonly ElementorParser $parser ) {}

	/**
	 * @deprecated Use {@see BlockConverter::supported_widgets()} instead. Retained
	 *             so existing extensions that referenced the constant keep working.
	 * @var array<int,string>
	 */
	#[\Deprecated( 'Use BlockConverter::supported_widgets() — single source of truth lives on the converter.', '0.2.0' )]
	public const SUPPORTED_WIDGETS = [
		'heading',
		'text-editor',
		'image',
		'button',
		'spacer',
		'divider',
		'icon-list',
		'video',
		'shortcode',
		'html',
	];

	/**
	 * Audit the post without persisting. Callers (REST handlers) are responsible
	 * for storing the result via {@see self::save_result()} so unrelated code paths
	 * (e.g. conversion) can audit in-memory without write-amplifying post meta.
	 *
	 * @return array<string,mixed>
	 */
	public function audit_post( int|WP_Post $post ): array {
		$post_id = $post instanceof WP_Post ? (int) $post->ID : $post;
		$parsed  = $this->parser->parse_post( $post_id );

		if ( ! $parsed['ok'] ) {
			$result              = $this->base_result( $post_id );
			$result['score']     = 0;
			$result['readiness'] = 'Manual Rebuild';
			$result['errors'][]  = $parsed['error'];
			$result['status']    = 'failed';

			return $result;
		}

		$result    = $this->base_result( $post_id );
		$supported = BlockConverter::supported_widgets();
		$has_css   = false;

		// Single recursive walk: count layout nodes, classify widgets, capture max
		// layout-only depth, and short-circuit the CSS-dependency heuristic — all
		// without allocating a flat array copy.
		$this->walk( $parsed['data'], 0, $result, $supported, $has_css );

		$result['unsupported_widgets'] = array_values( array_unique( $result['unsupported_widgets'] ) );
		$result['has_global_css']      = $has_css || $this->has_post_elementor_css( $post_id );

		$this->apply_warnings_and_score( $result );

		return $result;
	}

	/**
	 * @param array<int,array<string,mixed>> $nodes
	 * @param array<string,mixed>            $result
	 * @param array<int,string>              $supported
	 */
	private function walk( array $nodes, int $depth, array &$result, array $supported, bool &$has_css ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$type     = isset( $node['elType'] ) && is_string( $node['elType'] ) ? $node['elType'] : '';
			$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
			$children = isset( $node['elements'] ) && is_array( $node['elements'] ) ? $node['elements'] : [];

			$is_layout = $type === 'section' || $type === 'container' || $type === 'column';

			if ( $is_layout ) {
				++$result['layout_nodes'];
				$child_depth = $depth + 1;
				if ( $child_depth > $result['layout_depth'] ) {
					$result['layout_depth'] = $child_depth;
				}
			} elseif ( $type === 'widget' ) {
				$widget = isset( $node['widgetType'] ) && is_string( $node['widgetType'] ) ? $node['widgetType'] : 'unknown';
				++$result['widget_counts']['total'];
				$result['widgets'][ $widget ] = ( $result['widgets'][ $widget ] ?? 0 ) + 1;

				if ( in_array( $widget, $supported, true ) ) {
					++$result['widget_counts']['supported'];
				} else {
					++$result['widget_counts']['unsupported'];
					$result['unsupported_widgets'][] = $widget;
				}
			}

			if ( ! $has_css && $this->settings_signal_global_css( $settings ) ) {
				$has_css = true;
			}

			if ( $children !== [] ) {
				// Only recurse depth for layout nodes — widget children (repeater items,
				// icon-list rows) shouldn't count toward layout nesting.
				$this->walk( $children, $is_layout ? $depth + 1 : $depth, $result, $supported, $has_css );
			}
		}
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function settings_signal_global_css( array $settings ): bool {
		return array_any(
			[ 'css_classes', 'custom_css', '_css_classes' ],
			static fn ( string $key ): bool => ! empty( $settings[ $key ] )
		);
	}

	private function has_post_elementor_css( int $post_id ): bool {
		$elementor_css = get_post_meta( $post_id, MetaKeys::ELEMENTOR_CSS, true );

		return is_array( $elementor_css ) || ( is_string( $elementor_css ) && trim( $elementor_css ) !== '' );
	}

	/**
	 * Persist a previously-computed audit result.
	 *
	 * @param array<string,mixed> $result
	 */
	public function save_result( int $post_id, array $result ): void {
		update_post_meta( $post_id, MetaKeys::AUDIT_RESULT, $result );
	}

	/**
	 * Audit and persist in one call. Convenience for REST handlers.
	 *
	 * @return array<string,mixed>
	 */
	public function audit_and_save( int|WP_Post $post ): array {
		$post_id = $post instanceof WP_Post ? (int) $post->ID : $post;
		$result  = $this->audit_post( $post_id );
		$this->save_result( $post_id, $result );

		return $result;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function base_result( int $post_id ): array {
		return [
			'post_id'             => $post_id,
			'status'              => 'audited',
			'score'               => 100,
			'readiness'           => 'Ready',
			'widget_counts'       => [
				'total'       => 0,
				'supported'   => 0,
				'unsupported' => 0,
			],
			'widgets'             => [],
			'unsupported_widgets' => [],
			'layout_nodes'        => 0,
			'layout_depth'        => 0,
			'has_global_css'      => false,
			'warnings'            => [],
			'errors'              => [],
			'audited_at'          => (string) wp_date( 'c' ),
		];
	}

	/**
	 * @param array<string,mixed> $result
	 */
	private function apply_warnings_and_score( array &$result ): void {
		$score = 100;

		if ( $result['widget_counts']['total'] === 0 ) {
			$result['warnings'][] = __( 'No Elementor widgets were found.', 'dementor-blocks' );
			$score -= 25;
		}

		if ( $result['widget_counts']['unsupported'] > 0 ) {
			$result['warnings'][] = sprintf(
				/* translators: %d: Unsupported widget count. */
				__( '%d unsupported Elementor widget(s) will use HTML fallback blocks.', 'dementor-blocks' ),
				$result['widget_counts']['unsupported']
			);
			$score -= min( 50, $result['widget_counts']['unsupported'] * 12 );
		}

		if ( $result['layout_depth'] > 5 ) {
			$result['warnings'][] = __( 'Deeply nested Elementor layout may need manual review.', 'dementor-blocks' );
			$score -= min( 20, ( $result['layout_depth'] - 5 ) * 5 );
		}

		if ( $result['has_global_css'] ) {
			$result['warnings'][] = __( 'Elementor global or custom CSS dependencies detected.', 'dementor-blocks' );
			$score -= 20;
		}

		$result['score'] = max( 0, $score );

		if ( $result['score'] < 50 || $result['widget_counts']['unsupported'] >= 4 ) {
			$result['readiness'] = 'Manual Rebuild';
		} elseif ( $result['score'] < 90 || $result['has_global_css'] || $result['widget_counts']['unsupported'] > 0 ) {
			$result['readiness'] = 'Review Needed';
		} else {
			$result['readiness'] = 'Ready';
		}
	}

}
