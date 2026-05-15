<?php
/**
 * Conversion service tests.
 *
 * @package DementorBlocks
 */

use DementorBlocks\Conversion\Auditor;
use DementorBlocks\Conversion\BlockConverter;
use DementorBlocks\Conversion\ConversionService;
use DementorBlocks\Conversion\ElementorParser;
use DementorBlocks\MetaKeys;

final class Test_Conversion_Service extends WP_UnitTestCase {
	private ConversionService $service;

	public function set_up(): void {
		parent::set_up();
		$parser        = new ElementorParser();
		$this->service = new ConversionService( new Auditor( $parser ), new BlockConverter( $parser ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function test_creates_duplicate_block_draft(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Elementor Source',
			]
		);
		update_post_meta( $post_id, MetaKeys::ELEMENTOR_DATA, $this->fixture( 'basic-page.json' ) );

		$result = $this->service->convert( $post_id, 'duplicate', 'inline' );

		$this->assertIsArray( $result );
		$this->assertSame( 'converted', $result['status'] );
		$this->assertNotSame( $post_id, $result['target_post_id'] );
		$this->assertSame( $post_id, (int) get_post_meta( $result['target_post_id'], MetaKeys::SOURCE_POST_ID, true ) );
		$this->assertStringContainsString( '<!-- wp:heading', get_post_field( 'post_content', $result['target_post_id'] ) );
	}

	public function test_replace_updates_original_content(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_post_meta( $post_id, MetaKeys::ELEMENTOR_DATA, $this->fixture( 'basic-page.json' ) );

		$result = $this->service->convert( $post_id, 'replace', 'none' );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['target_post_id'] );
		$this->assertStringContainsString( '<!-- wp:buttons', get_post_field( 'post_content', $post_id ) );
	}

	public function test_generated_css_is_stored_on_target(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_post_meta( $post_id, MetaKeys::ELEMENTOR_DATA, $this->fixture( 'styled-page.json' ) );

		$result = $this->service->convert( $post_id, 'duplicate', 'css' );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( get_post_meta( $result['target_post_id'], MetaKeys::GENERATED_CSS, true ) );
	}

	private function fixture( string $name ): string {
		return (string) file_get_contents( __DIR__ . '/fixtures/' . $name );
	}
}
