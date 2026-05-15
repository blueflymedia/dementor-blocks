<?php
/**
 * Converts Elementor nodes into WordPress block markup.
 *
 * @package DementorBlocks
 */

declare(strict_types=1);

namespace DementorBlocks\Conversion;

use WP_HTML_Processor;
use WP_HTML_Tag_Processor;
use WP_Post;

final class BlockConverter {
	/**
	 * Elementor widget types we know how to map to native blocks. Anything not in
	 * this list falls through to `unsupported_widget()` / `core/html`. This is the
	 * single source of truth; `Auditor` reads it via {@see self::supported_widgets()}.
	 *
	 * @var array<int,string>
	 */
	private const SUPPORTED_WIDGETS = [
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

	/** @var array<string,true> */
	private array $emitted_css_classes = [];

	/**
	 * Returns the canonical widget list, filtered so extensions can register
	 * additional widget types via {@see 'dementor_blocks/convert_widget'}.
	 *
	 * @return array<int,string>
	 */
	public static function supported_widgets(): array {
		/**
		 * Filter the list of Elementor widget types treated as natively supported.
		 *
		 * Extensions that register a `dementor_blocks/convert_widget` handler for a
		 * new widget type should append it here so the Auditor counts it as
		 * supported (rather than flagging the page as "Review Needed").
		 *
		 * @param array<int,string> $widgets
		 */
		return (array) apply_filters( 'dementor_blocks/supported_widgets', self::SUPPORTED_WIDGETS );
	}

	public function __construct( private readonly ElementorParser $parser ) {}

	/**
	 * @return array{content:string,warnings:array<int,string>,generated_css:string}
	 */
	public function convert_post( int|WP_Post $post, string $style_mode = 'inline' ): array {
		$post_id = $post instanceof WP_Post ? (int) $post->ID : $post;
		$parsed  = $this->parser->parse_post( $post_id );

		if ( ! $parsed['ok'] ) {
			return [
				'content'       => '',
				'warnings'      => [ (string) $parsed['error'] ],
				'generated_css' => '',
			];
		}

		$this->emitted_css_classes = [];

		$ctx = [
			'style_mode' => $style_mode,
			'warnings'   => [],
			'css'        => '',
		];

		$blocks = $this->convert_nodes( $parsed['data'], $ctx );

		return [
			'content'       => trim( serialize_blocks( $blocks ) ),
			'warnings'      => array_values( array_unique( $ctx['warnings'] ) ),
			'generated_css' => trim( $ctx['css'] ),
		];
	}

	/**
	 * Walks a list of sibling nodes and returns block arrays.
	 *
	 * Adjacent `column` siblings are bundled into a single `core/columns` wrapper.
	 * This is correct for legacy sections and rescues orphan columns at any depth
	 * so they never emit a bare `core/column` (invalid block markup).
	 *
	 * @param array<int,array<string,mixed>>                                  $nodes
	 * @param array{style_mode:string,warnings:array<int,string>,css:string} $ctx
	 * @return array<int,array<string,mixed>>
	 */
	private function convert_nodes( array $nodes, array &$ctx ): array {
		$out           = [];
		$column_buffer = [];

		$flush = function () use ( &$out, &$column_buffer ): void {
			if ( $column_buffer === [] ) {
				return;
			}
			$out[]         = $this->wrap_block(
				'core/columns',
				[],
				'<div class="wp-block-columns">',
				'</div>',
				$column_buffer
			);
			$column_buffer = [];
		};

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$type = isset( $node['elType'] ) && is_string( $node['elType'] ) ? $node['elType'] : '';

			if ( $type === 'column' ) {
				$column_buffer[] = $this->convert_column( $node, $ctx );
				continue;
			}

			$flush();

			$block = $this->convert_node( $node, $ctx );
			if ( $block !== null ) {
				$out[] = $block;
			}
		}

		$flush();

		return $out;
	}

	/**
	 * @param array<string,mixed>                                             $node
	 * @param array{style_mode:string,warnings:array<int,string>,css:string} $ctx
	 * @return array<string,mixed>|null
	 */
	private function convert_node( array $node, array &$ctx ): ?array {
		$type     = isset( $node['elType'] ) && is_string( $node['elType'] ) ? $node['elType'] : '';
		$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
		$children = isset( $node['elements'] ) && is_array( $node['elements'] ) ? $node['elements'] : [];

		if ( $type === 'section' || $type === 'container' ) {
			$inner = $this->convert_nodes( $children, $ctx );

			return $this->wrap_block(
				'core/group',
				$this->block_attrs( $settings, $ctx ),
				'<div class="wp-block-group">',
				'</div>',
				$inner
			);
		}

		if ( $type === 'widget' ) {
			$widget = isset( $node['widgetType'] ) && is_string( $node['widgetType'] ) ? $node['widgetType'] : 'unknown';
			return $this->convert_widget( $widget, $settings, $children, $ctx );
		}

		return null;
	}

	/**
	 * @param array<string,mixed>                                             $node
	 * @param array{style_mode:string,warnings:array<int,string>,css:string} $ctx
	 * @return array<string,mixed>
	 */
	private function convert_column( array $node, array &$ctx ): array {
		$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
		$children = isset( $node['elements'] ) && is_array( $node['elements'] ) ? $node['elements'] : [];
		$inner    = $this->convert_nodes( $children, $ctx );

		return $this->wrap_block(
			'core/column',
			$this->block_attrs( $settings, $ctx ),
			'<div class="wp-block-column">',
			'</div>',
			$inner
		);
	}

	/**
	 * @param array<string,mixed>                                             $settings
	 * @param array<int,array<string,mixed>>                                  $children
	 * @param array{style_mode:string,warnings:array<int,string>,css:string} $ctx
	 * @return array<string,mixed>|null
	 */
	private function convert_widget( string $widget, array $settings, array $children, array &$ctx ): ?array {
		/**
		 * Allow extensions to convert a widget themselves, bypassing the built-in
		 * mapping. Return a block array (shape used by serialize_blocks) to short
		 * circuit, or `null` to fall through to the default handlers.
		 *
		 * @param array<string,mixed>|null                                       $block
		 * @param string                                                         $widget
		 * @param array<string,mixed>                                            $settings
		 * @param array<int,array<string,mixed>>                                 $children
		 * @param array{style_mode:string,warnings:array<int,string>,css:string} $ctx
		 */
		$override = apply_filters( 'dementor_blocks/convert_widget', null, $widget, $settings, $children, $ctx );
		if ( is_array( $override ) ) {
			return $override;
		}

		return match ( $widget ) {
			'heading'     => $this->convert_heading( $settings, $ctx ),
			'text-editor' => $this->convert_text( $settings ),
			'image'       => $this->convert_image( $settings, $ctx ),
			'button'      => $this->convert_button( $settings, $ctx ),
			'spacer'      => $this->convert_spacer( $settings ),
			'divider'     => $this->leaf_block( 'core/separator', $this->block_attrs( $settings, $ctx ), '<hr class="wp-block-separator has-alpha-channel-opacity"/>' ),
			'icon-list'   => $this->convert_icon_list( $settings ),
			'video'       => $this->convert_embed( $settings, $ctx ),
			'shortcode'   => $this->convert_shortcode( $settings ),
			'html'        => $this->leaf_block( 'core/html', [], wp_kses_post( (string) ( $settings['html'] ?? '' ) ) ),
			default       => $this->unsupported_widget( $widget, $settings, $children, $ctx ),
		};
	}

	/**
	 * @param array<string,mixed>                                             $settings
	 * @param array{style_mode:string,warnings:array<int,string>,css:string} $ctx
	 * @return array<string,mixed>
	 */
	private function convert_heading( array $settings, array &$ctx ): array {
		$title = wp_kses_post( (string) ( $settings['title'] ?? '' ) );
		$tag   = isset( $settings['header_size'] ) ? (string) $settings['header_size'] : 'h2';
		$level = (int) preg_replace( '/[^0-9]/', '', $tag );
		$level = $level >= 1 && $level <= 6 ? $level : 2;
		$attrs = array_merge( [ 'level' => $level ], $this->block_attrs( $settings, $ctx ) );

		return $this->leaf_block( 'core/heading', $attrs, sprintf( '<h%d>%s</h%d>', $level, $title, $level ) );
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>|null
	 */
	private function convert_text( array $settings ): ?array {
		$html = wp_kses_post( (string) ( $settings['editor'] ?? '' ) );

		if ( trim( $html ) === '' ) {
			return null;
		}

		if ( $this->contains_block_level_tag( $html ) ) {
			return $this->leaf_block( 'core/html', [], $html );
		}

		$inner = $this->starts_with_paragraph( $html )
			? trim( $html )
			: '<p>' . trim( $html ) . '</p>';

		return $this->leaf_block( 'core/paragraph', [], $inner );
	}

	private function contains_block_level_tag( string $html ): bool {
		static $block_level = [ 'DIV', 'SECTION', 'TABLE', 'UL', 'OL', 'BLOCKQUOTE', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'FIGURE', 'PRE', 'HR' ];

		$processor = WP_HTML_Processor::create_fragment( $html );
		if ( $processor === null ) {
			return false;
		}

		while ( $processor->next_tag() ) {
			if ( in_array( (string) $processor->get_tag(), $block_level, true ) ) {
				return true;
			}
		}

		return false;
	}

	private function starts_with_paragraph( string $html ): bool {
		$processor = new WP_HTML_Tag_Processor( $html );
		return $processor->next_tag() && $processor->get_tag() === 'P';
	}

	/**
	 * @param array<string,mixed>                                             $settings
	 * @param array{style_mode:string,warnings:array<int,string>,css:string} $ctx
	 * @return array<string,mixed>|null
	 */
	private function convert_image( array $settings, array &$ctx ): ?array {
		$image = isset( $settings['image'] ) && is_array( $settings['image'] ) ? $settings['image'] : [];
		$url   = isset( $image['url'] ) ? esc_url_raw( (string) $image['url'] ) : '';
		$id    = isset( $image['id'] ) ? absint( $image['id'] ) : 0;
		$alt   = isset( $settings['image_alt'] ) ? (string) $settings['image_alt'] : '';

		if ( $url === '' && $id > 0 ) {
			$url = (string) wp_get_attachment_url( $id );
		}

		if ( $url === '' ) {
			$ctx['warnings'][] = __( 'Image widget is missing a usable media URL.', 'dementor-blocks' );
			return null;
		}

		$attrs = $this->block_attrs( $settings, $ctx );
		if ( $id > 0 ) {
			$attrs['id'] = $id;
		}
		$attrs['url'] = $url;
		if ( $alt !== '' ) {
			$attrs['alt'] = sanitize_text_field( $alt );
		}

		$size_slug = isset( $settings['image_size'] ) && is_string( $settings['image_size'] ) ? sanitize_html_class( $settings['image_size'] ) : '';
		if ( $size_slug !== '' ) {
			$attrs['sizeSlug'] = $size_slug;
		}

		$figure_class = 'wp-block-image';
		if ( $size_slug !== '' ) {
			$figure_class .= ' size-' . $size_slug;
		}

		$img = new WP_HTML_Tag_Processor( '<img/>' );
		$img->next_tag();
		$img->set_attribute( 'src', $url );
		$img->set_attribute( 'alt', $alt );
		if ( $id > 0 ) {
			$img->set_attribute( 'class', 'wp-image-' . $id );
		}

		$html = '<figure class="' . esc_attr( $figure_class ) . '">' . $img->get_updated_html() . '</figure>';

		return $this->leaf_block( 'core/image', $attrs, $html );
	}

	/**
	 * @param array<string,mixed>                                             $settings
	 * @param array{style_mode:string,warnings:array<int,string>,css:string} $ctx
	 * @return array<string,mixed>
	 */
	private function convert_button( array $settings, array &$ctx ): array {
		$text  = wp_strip_all_tags( (string) ( $settings['text'] ?? __( 'Button', 'dementor-blocks' ) ) );
		$link  = isset( $settings['link'] ) && is_array( $settings['link'] ) ? $settings['link'] : [];
		$url   = isset( $link['url'] ) ? esc_url_raw( (string) $link['url'] ) : '';
		$attrs = $this->block_attrs( $settings, $ctx );

		if ( $url !== '' ) {
			$attrs['url'] = $url;
		}

		$is_external = ! empty( $link['is_external'] );
		$is_nofollow = ! empty( $link['nofollow'] );
		$rel_parts   = [];
		if ( $is_external ) {
			$attrs['linkTarget'] = '_blank';
			$rel_parts[]         = 'noopener';
			$rel_parts[]         = 'noreferrer';
		}
		if ( $is_nofollow ) {
			$rel_parts[] = 'nofollow';
		}
		if ( $rel_parts !== [] ) {
			$attrs['rel'] = implode( ' ', array_unique( $rel_parts ) );
		}

		$anchor = new WP_HTML_Tag_Processor( '<a></a>' );
		$anchor->next_tag();
		$anchor->set_attribute( 'class', 'wp-block-button__link wp-element-button' );
		if ( $url !== '' ) {
			$anchor->set_attribute( 'href', $url );
		}
		if ( isset( $attrs['linkTarget'] ) ) {
			$anchor->set_attribute( 'target', $attrs['linkTarget'] );
		}
		if ( isset( $attrs['rel'] ) ) {
			$anchor->set_attribute( 'rel', $attrs['rel'] );
		}

		$anchor_html = str_replace( '></a>', '>' . esc_html( $text ) . '</a>', $anchor->get_updated_html() );

		$button = $this->leaf_block(
			'core/button',
			$attrs,
			'<div class="wp-block-button">' . $anchor_html . '</div>'
		);

		return $this->wrap_block(
			'core/buttons',
			[],
			'<div class="wp-block-buttons">',
			'</div>',
			[ $button ]
		);
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	private function convert_spacer( array $settings ): array {
		$size = isset( $settings['space']['size'] ) ? absint( $settings['space']['size'] ) : absint( $settings['space'] ?? 40 );
		$html = sprintf(
			'<div style="height:%dpx" aria-hidden="true" class="wp-block-spacer"></div>',
			$size
		);

		return $this->leaf_block( 'core/spacer', [ 'height' => $size . 'px' ], $html );
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	private function convert_icon_list( array $settings ): array {
		$items = isset( $settings['icon_list'] ) && is_array( $settings['icon_list'] ) ? $settings['icon_list'] : [];
		$html  = '<ul class="wp-block-list">';

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$text  = wp_kses_post( (string) ( $item['text'] ?? '' ) );
			$html .= '<li>' . $text . '</li>';
		}

		$html .= '</ul>';

		return $this->leaf_block( 'core/list', [], $html );
	}

	/**
	 * @param array<string,mixed>                                             $settings
	 * @param array{style_mode:string,warnings:array<int,string>,css:string} $ctx
	 * @return array<string,mixed>|null
	 */
	private function convert_embed( array $settings, array &$ctx ): ?array {
		$candidates = [
			'youtube' => (string) ( $settings['youtube_url'] ?? '' ),
			'vimeo'   => (string) ( $settings['vimeo_url'] ?? '' ),
			''        => (string) ( $settings['hosted_url']['url'] ?? '' ),
		];

		$provider = '';
		$url      = '';
		foreach ( $candidates as $slug => $value ) {
			if ( $value !== '' ) {
				$provider = $slug;
				$url      = esc_url_raw( $value );
				break;
			}
		}

		if ( $url === '' ) {
			$ctx['warnings'][] = __( 'Video widget is missing a supported embed URL.', 'dementor-blocks' );
			return null;
		}

		$attrs = [ 'url' => $url ];
		if ( $provider !== '' ) {
			$attrs['providerNameSlug'] = $provider;
			$attrs['type']             = 'video';
		}

		$html = '<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">' . esc_url( $url ) . '</div></figure>';

		return $this->leaf_block( 'core/embed', $attrs, $html );
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>|null
	 */
	private function convert_shortcode( array $settings ): ?array {
		$shortcode = trim( (string) ( $settings['shortcode'] ?? '' ) );

		if ( $shortcode === '' ) {
			return null;
		}

		return $this->leaf_block( 'core/shortcode', [], wp_kses_post( $shortcode ) );
	}

	/**
	 * Fallback for widgets we don't yet support. Preserves nested children so
	 * inner content isn't lost when only the wrapper widget is unknown.
	 *
	 * @param array<string,mixed>                                             $settings
	 * @param array<int,array<string,mixed>>                                  $children
	 * @param array{style_mode:string,warnings:array<int,string>,css:string} $ctx
	 * @return array<string,mixed>
	 */
	private function unsupported_widget( string $widget, array $settings, array $children, array &$ctx ): array {
		$ctx['warnings'][] = sprintf(
			/* translators: %s: Elementor widget name. */
			__( 'Unsupported Elementor widget "%s" was converted to a Custom HTML fallback.', 'dementor-blocks' ),
			$widget
		);

		$html = sprintf(
			'<div class="dementor-blocks-fallback" data-elementor-widget="%s">%s</div>',
			esc_attr( $widget ),
			esc_html( wp_json_encode( $settings ) ?: '' )
		);

		$fallback = $this->leaf_block( 'core/html', [], $html );

		$inner = $children === [] ? [] : $this->convert_nodes( $children, $ctx );

		if ( $inner === [] ) {
			return $fallback;
		}

		return $this->wrap_block(
			'core/group',
			[ 'className' => 'dementor-blocks-fallback-group' ],
			'<div class="wp-block-group dementor-blocks-fallback-group">',
			'</div>',
			array_merge( [ $fallback ], $inner )
		);
	}

	/**
	 * @param array<string,mixed>                                             $settings
	 * @param array{style_mode:string,warnings:array<int,string>,css:string} $ctx
	 * @return array<string,mixed>
	 */
	private function block_attrs( array $settings, array &$ctx ): array {
		$attrs = $this->compute_block_attrs( $settings, $ctx );

		/**
		 * Filter the block attributes derived from an Elementor settings array.
		 *
		 * Useful for mapping additional Elementor controls (e.g. custom typography,
		 * border radius) into block attrs without forking the converter.
		 *
		 * @param array<string,mixed>                                            $attrs
		 * @param array<string,mixed>                                            $settings
		 * @param array{style_mode:string,warnings:array<int,string>,css:string} $ctx
		 */
		return (array) apply_filters( 'dementor_blocks/block_attrs', $attrs, $settings, $ctx );
	}

	/**
	 * @param array<string,mixed>                                             $settings
	 * @param array{style_mode:string,warnings:array<int,string>,css:string} $ctx
	 * @return array<string,mixed>
	 */
	private function compute_block_attrs( array $settings, array &$ctx ): array {
		$attrs = [];
		$style = [];

		$align = $settings['align'] ?? $settings['_align'] ?? '';
		if ( is_string( $align ) && in_array( $align, [ 'left', 'center', 'right', 'wide', 'full' ], true ) ) {
			$attrs['align'] = $align;
		}

		if ( $ctx['style_mode'] === 'none' ) {
			return $attrs;
		}

		foreach ( [ 'background_color' => 'background', 'text_color' => 'text' ] as $source => $target ) {
			if ( ! empty( $settings[ $source ] ) && is_string( $settings[ $source ] ) ) {
				$style['color'][ $target ] = sanitize_hex_color( $settings[ $source ] ) ?: sanitize_text_field( $settings[ $source ] );
			}
		}

		foreach ( [ '_padding' => 'padding', '_margin' => 'margin' ] as $source => $target ) {
			if ( isset( $settings[ $source ] ) && is_array( $settings[ $source ] ) ) {
				$spacing = $this->dimension_box( $settings[ $source ] );
				if ( $spacing !== [] ) {
					$style['spacing'][ $target ] = $spacing;
				}
			}
		}

		if ( $style === [] ) {
			return $attrs;
		}

		if ( $ctx['style_mode'] === 'inline' ) {
			$attrs['style'] = $style;
			return $attrs;
		}

		if ( $ctx['style_mode'] === 'css' ) {
			$class              = 'dementor-blocks-style-' . substr( md5( (string) wp_json_encode( $style ) ), 0, 10 );
			$attrs['className'] = trim( (string) ( $attrs['className'] ?? '' ) . ' ' . $class );

			if ( ! isset( $this->emitted_css_classes[ $class ] ) ) {
				$ctx['css']                          .= $this->style_to_css( $class, $style );
				$this->emitted_css_classes[ $class ]  = true;
			}
		}

		return $attrs;
	}

	/**
	 * @param array<string,mixed> $box
	 * @return array<string,string>
	 */
	private function dimension_box( array $box ): array {
		$output = [];
		$unit   = isset( $box['unit'] ) && is_string( $box['unit'] ) && in_array( $box['unit'], [ 'px', 'em', 'rem', '%', 'vw', 'vh' ], true )
			? $box['unit']
			: 'px';

		foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
			if ( isset( $box[ $side ] ) && is_numeric( $box[ $side ] ) ) {
				$output[ $side ] = $box[ $side ] . $unit;
			}
		}

		return $output;
	}

	/**
	 * @param array<string,mixed> $style
	 */
	private function style_to_css( string $class, array $style ): string {
		$candidates = [];

		if ( isset( $style['color']['background'] ) ) {
			$candidates[] = 'background-color:' . $style['color']['background'];
		}
		if ( isset( $style['color']['text'] ) ) {
			$candidates[] = 'color:' . $style['color']['text'];
		}

		foreach ( [ 'padding', 'margin' ] as $property ) {
			if ( isset( $style['spacing'][ $property ] ) && is_array( $style['spacing'][ $property ] ) ) {
				foreach ( $style['spacing'][ $property ] as $side => $value ) {
					$candidates[] = $property . '-' . $side . ':' . $value;
				}
			}
		}

		// Run the assembled `prop:value` list through WordPress' allowlist-based CSS
		// filter. safecss_filter_attr expects a `style=""`-style string and drops any
		// declaration whose property isn't on the safe list or whose value contains
		// unsafe punctuation (`}`, `*/`, `</style>`, etc.), so a malicious Elementor
		// color value can't escape its scoped class.
		$safe = trim( safecss_filter_attr( implode( ';', $candidates ) ) );

		if ( $safe === '' ) {
			return '';
		}

		// safecss_filter_attr strips trailing semicolons; normalize back to a
		// single-line ruleset.
		$rules = rtrim( $safe, '; ' );

		return '.' . $class . '{' . $rules . '}' . "\n";
	}

	/**
	 * Build a block array with inner blocks wrapped by HTML.
	 *
	 * innerContent is `[open, null, null, …, close]` — one null per inner block so
	 * `serialize_block()` can splice each child in order between the wrapper tags.
	 *
	 * @param array<string,mixed>            $attrs
	 * @param array<int,array<string,mixed>> $inner_blocks
	 * @return array<string,mixed>
	 */
	private function wrap_block( string $name, array $attrs, string $open, string $close, array $inner_blocks ): array {
		$inner_blocks = array_values( $inner_blocks );

		if ( $inner_blocks === [] ) {
			$html = $open . $close;
			return [
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => $html,
				'innerContent' => [ $html ],
			];
		}

		$inner_content = [ $open ];
		foreach ( $inner_blocks as $_ ) {
			$inner_content[] = null;
		}
		$inner_content[] = $close;

		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $open . $close,
			'innerContent' => $inner_content,
		];
	}

	/**
	 * @param array<string,mixed> $attrs
	 * @return array<string,mixed>
	 */
	private function leaf_block( string $name, array $attrs, string $html ): array {
		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
		];
	}
}
