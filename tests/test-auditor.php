<?php
/**
 * Auditor tests.
 *
 * @package DementorBlocks
 */

use DementorBlocks\Conversion\Auditor;
use DementorBlocks\Conversion\ElementorParser;
use DementorBlocks\MetaKeys;

final class Test_Auditor extends WP_UnitTestCase {
	private Auditor $auditor;

	public function set_up(): void {
		parent::set_up();
		$this->auditor = new Auditor( new ElementorParser() );
	}

	public function test_detects_supported_elementor_page_as_ready(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_post_meta( $post_id, MetaKeys::ELEMENTOR_DATA, $this->fixture( 'basic-page.json' ) );

		$result = $this->auditor->audit_post( $post_id );

		$this->assertSame( 'Ready', $result['readiness'] );
		$this->assertSame( 100, $result['score'] );
		$this->assertSame( 6, $result['widget_counts']['supported'] );
	}

	public function test_unsupported_widgets_lower_readiness(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_post_meta( $post_id, MetaKeys::ELEMENTOR_DATA, $this->fixture( 'unsupported-widget.json' ) );

		$result = $this->auditor->audit_post( $post_id );

		$this->assertSame( 'Review Needed', $result['readiness'] );
		$this->assertSame( 1, $result['widget_counts']['unsupported'] );
		$this->assertContains( 'form', $result['unsupported_widgets'] );
	}

	public function test_invalid_json_is_manual_rebuild(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_post_meta( $post_id, MetaKeys::ELEMENTOR_DATA, '{invalid' );

		$result = $this->auditor->audit_post( $post_id );

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'Manual Rebuild', $result['readiness'] );
		$this->assertSame( 0, $result['score'] );
	}

	private function fixture( string $name ): string {
		return (string) file_get_contents( __DIR__ . '/fixtures/' . $name );
	}
}
