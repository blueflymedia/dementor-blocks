<?php
/**
 * REST API controller for audit and conversion actions.
 *
 * @package DementorBlocks
 */

declare(strict_types=1);

namespace DementorBlocks\Rest;

use DementorBlocks\Conversion\Auditor;
use DementorBlocks\Conversion\ConversionService;
use DementorBlocks\Conversion\ElementorParser;
use DementorBlocks\MetaKeys;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

final class Controller {
	private const NAMESPACE = 'dementor-blocks/v1';

	private const BATCH_LIMIT      = 50;
	private const PAGES_MAX_PER    = 200;
	private const PAGES_DEFAULT_PER = 50;

	private ElementorParser $parser;

	public function __construct(
		private readonly Auditor $auditor,
		private readonly ConversionService $conversion_service
	) {
		$this->parser = new ElementorParser();
	}

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$post_ids_args = [
			'post_ids' => [
				'required'          => true,
				'type'              => 'array',
				'items'             => [ 'type' => 'integer' ],
				'sanitize_callback' => static fn ( $value ): array => array_values( array_filter( array_map( 'absint', (array) $value ) ) ),
				'validate_callback' => [ $this, 'validate_post_ids' ],
			],
		];

		$convert_options_args = [
			'destination' => [
				'type'              => 'string',
				'enum'              => [ 'duplicate', 'replace' ],
				'default'           => 'duplicate',
				'sanitize_callback' => 'sanitize_key',
			],
			'style_mode'  => [
				'type'              => 'string',
				'enum'              => [ 'none', 'inline', 'css' ],
				'default'           => 'inline',
				'sanitize_callback' => 'sanitize_key',
			],
		];

		register_rest_route(
			self::NAMESPACE,
			'/pages',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'pages' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'page'     => [
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					],
					'per_page' => [
						'type'              => 'integer',
						'default'           => self::PAGES_DEFAULT_PER,
						'minimum'           => 1,
						'maximum'           => self::PAGES_MAX_PER,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/audit',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'audit' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'post_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/audit-batch',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'audit_batch' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => $post_ids_args,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/convert',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'convert' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => array_merge(
					[
						'post_id' => [
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
					],
					$convert_options_args
				),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/convert-batch',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'convert_batch' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => array_merge( $post_ids_args, $convert_options_args ),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/result/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'result' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * Validate that `post_ids` is a non-empty array within the batch cap.
	 *
	 * @param mixed $value
	 */
	public function validate_post_ids( $value ): bool|\WP_Error {
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'dementor_blocks_invalid_post_ids', __( 'post_ids must be an array.', 'dementor-blocks' ), [ 'status' => 400 ] );
		}

		if ( $value === [] ) {
			return new \WP_Error( 'dementor_blocks_empty_post_ids', __( 'post_ids cannot be empty.', 'dementor-blocks' ), [ 'status' => 400 ] );
		}

		if ( count( $value ) > self::BATCH_LIMIT ) {
			return new \WP_Error(
				'dementor_blocks_batch_too_large',
				sprintf(
					/* translators: %d: Maximum number of post IDs allowed in a single batch. */
					__( 'No more than %d post IDs may be submitted at once.', 'dementor-blocks' ),
					self::BATCH_LIMIT
				),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public function pages( WP_REST_Request $request ): WP_REST_Response {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( $per_page, self::PAGES_MAX_PER ) : self::PAGES_DEFAULT_PER;

		$query = new \WP_Query(
			[
				'post_type'      => 'page',
				'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'meta_query'     => [
					[
						'key'     => MetaKeys::ELEMENTOR_DATA,
						'compare' => 'EXISTS',
					],
				],
				'orderby'        => 'modified',
				'order'          => 'DESC',
			]
		);

		$pages = array_map(
			fn ( WP_Post $post ): array => $this->page_summary( $post ),
			$query->posts
		);

		$response = rest_ensure_response(
			[
				'pages'       => $pages,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $page,
				'per_page'    => $per_page,
			]
		);

		$response->header( 'X-WP-Total', (string) (int) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (string) (int) $query->max_num_pages );

		return $response;
	}

	public function audit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post || $post->post_type !== 'page' ) {
			return new WP_Error( 'dementor_blocks_missing_page', __( 'Page not found.', 'dementor-blocks' ), [ 'status' => 404 ] );
		}

		return rest_ensure_response( [ 'result' => $this->auditor->audit_and_save( $post_id ) ] );
	}

	public function audit_batch( WP_REST_Request $request ): WP_REST_Response {
		$ids     = $this->ids_from_request( $request );
		$results = [];

		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post || $post->post_type !== 'page' ) {
				$results[] = [
					'post_id' => $post_id,
					'status'  => 'failed',
					'errors'  => [ __( 'Page not found.', 'dementor-blocks' ) ],
				];
				continue;
			}

			$results[] = $this->auditor->audit_and_save( $post_id );
		}

		return rest_ensure_response( [ 'results' => $results ] );
	}

	public function convert( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id     = (int) $request->get_param( 'post_id' );
		$destination = (string) $request->get_param( 'destination' );
		$style_mode  = (string) $request->get_param( 'style_mode' );
		$result      = $this->conversion_service->convert( $post_id, $destination, $style_mode );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( [ 'result' => $result ] );
	}

	public function convert_batch( WP_REST_Request $request ): WP_REST_Response {
		$ids         = $this->ids_from_request( $request );
		$destination = (string) $request->get_param( 'destination' );
		$style_mode  = (string) $request->get_param( 'style_mode' );
		$results     = [];

		foreach ( $ids as $post_id ) {
			$result = $this->conversion_service->convert( $post_id, $destination, $style_mode );

			if ( is_wp_error( $result ) ) {
				$results[] = [
					'post_id' => $post_id,
					'status'  => 'failed',
					'errors'  => [ $result->get_error_message() ],
					'data'    => $result->get_error_data(),
				];
				continue;
			}

			$results[] = $result;
		}

		return rest_ensure_response( [ 'results' => $results ] );
	}

	public function result( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'dementor_blocks_missing_page', __( 'Page not found.', 'dementor-blocks' ), [ 'status' => 404 ] );
		}

		return rest_ensure_response(
			[
				'audit'      => get_post_meta( $post_id, MetaKeys::AUDIT_RESULT, true ) ?: null,
				'conversion' => get_post_meta( $post_id, MetaKeys::CONVERSION_RESULT, true ) ?: null,
			]
		);
	}

	/**
	 * The route schema's `sanitize_callback` has already coerced `post_ids` into a
	 * clean array of positive integers, so this is a typed accessor.
	 *
	 * @return array<int,int>
	 */
	private function ids_from_request( WP_REST_Request $request ): array {
		$ids = $request->get_param( 'post_ids' );

		return is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : [];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function page_summary( WP_Post $post ): array {
		return [
			'id'             => (int) $post->ID,
			'title'          => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' ),
			'status'         => $post->post_status,
			'modified'       => get_post_modified_time( 'c', true, $post ),
			'edit_url'       => get_edit_post_link( $post->ID, 'raw' ),
			'view_url'       => get_permalink( $post ),
			'has_elementor'  => $this->parser->has_elementor_data( (int) $post->ID ),
			'audit'          => get_post_meta( (int) $post->ID, MetaKeys::AUDIT_RESULT, true ) ?: null,
			'conversion'     => get_post_meta( (int) $post->ID, MetaKeys::CONVERSION_RESULT, true ) ?: null,
		];
	}
}
