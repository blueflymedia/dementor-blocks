<?php
/**
 * REST controller tests.
 *
 * @package DementorBlocks
 */

use DementorBlocks\MetaKeys;

final class Test_Rest_Controller extends WP_UnitTestCase {
	public function test_rest_requires_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request  = new WP_REST_Request( 'GET', '/dementor-blocks/v1/pages' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_batch_audit_continues_after_missing_page(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_post_meta( $post_id, MetaKeys::ELEMENTOR_DATA, (string) file_get_contents( __DIR__ . '/fixtures/basic-page.json' ) );

		$request = new WP_REST_Request( 'POST', '/dementor-blocks/v1/audit-batch' );
		$request->set_param( 'post_ids', [ $post_id, 999999 ] );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $data['results'] );
		$this->assertSame( 'failed', $data['results'][1]['status'] );
	}

	public function test_pages_endpoint_decodes_title_entities(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post_id = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'About &#8211; Professional',
			]
		);
		update_post_meta( $post_id, MetaKeys::ELEMENTOR_DATA, (string) file_get_contents( __DIR__ . '/fixtures/basic-page.json' ) );

		$request  = new WP_REST_Request( 'GET', '/dementor-blocks/v1/pages' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'About – Professional', $data['pages'][0]['title'] );
	}
}
