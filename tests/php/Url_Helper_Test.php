<?php
/**
 * Url_Helper tests.
 *
 * @package RankMathSitemapsPolylang
 */

declare(strict_types=1);

namespace RankMathSitemapsPolylang\Tests;

use PHPUnit\Framework\TestCase;
use RankMathSitemapsPolylang\Url_Helper;

/**
 * @covers \RankMathSitemapsPolylang\Url_Helper
 */
final class Url_Helper_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		rmsp_test_reset_state();
	}

	public function test_parse_sitemap_filename_post_page_one(): void {
		$result = Url_Helper::parse_sitemap_filename( '/post-sitemap.xml' );

		$this->assertSame(
			[
				'type' => 'post',
				'page' => 1,
			],
			$result
		);
	}

	public function test_parse_sitemap_filename_prefixed_page_two(): void {
		$result = Url_Helper::parse_sitemap_filename( '/en/post-sitemap2.xml' );

		$this->assertSame(
			[
				'type' => 'post',
				'page' => 2,
			],
			$result
		);
	}

	public function test_parse_sitemap_filename_subdirectory_install(): void {
		$result = Url_Helper::parse_sitemap_filename( '/site/en/page-sitemap.xml' );

		$this->assertSame(
			[
				'type' => 'page',
				'page' => 1,
			],
			$result
		);
	}

	public function test_parse_sitemap_filename_invalid(): void {
		$this->assertNull( Url_Helper::parse_sitemap_filename( '/feed.xml' ) );
	}

	public function test_parse_sitemap_filename_full_url(): void {
		$result = Url_Helper::parse_sitemap_filename( 'https://example.com/post-sitemap.xml?foo=1' );

		$this->assertSame(
			[
				'type' => 'post',
				'page' => 1,
			],
			$result
		);
	}
}
