<?php
/**
 * Url_Helper index / urlset probe tests.
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
final class Url_Helper_Index_Test extends TestCase {

	private Url_Helper $urls;

	protected function setUp(): void {
		parent::setUp();
		rmsp_test_reset_state();
		$this->urls = new Url_Helper();
	}

	public function test_is_index_request_when_sitemap_is_one(): void {
		$GLOBALS['rmsp_test_query_vars']['sitemap'] = '1';

		$this->assertTrue( $this->urls->is_index_request() );
	}

	public function test_is_index_request_false_during_urlset_probe(): void {
		$GLOBALS['rmsp_test_query_vars']['sitemap'] = '1';

		$this->urls->begin_urlset_probe();

		$this->assertFalse( $this->urls->is_index_request() );

		$this->urls->end_urlset_probe();

		$this->assertTrue( $this->urls->is_index_request() );
	}

	public function test_is_index_request_false_for_urlset_type(): void {
		$GLOBALS['rmsp_test_query_vars']['sitemap'] = 'post';

		$this->assertFalse( $this->urls->is_index_request() );
	}

	public function test_detect_sitemap_language_from_request_with_prefix(): void {
		$GLOBALS['rmsp_test_language_terms']['en'] = true;
		$_SERVER['REQUEST_URI']                    = '/en/post-sitemap.xml';

		$this->assertSame( 'en', $this->urls->detect_sitemap_language_from_request() );
	}

	public function test_detect_sitemap_language_from_request_unknown_prefix(): void {
		$_SERVER['REQUEST_URI'] = '/blog/post-sitemap.xml';

		$this->assertSame( '', $this->urls->detect_sitemap_language_from_request() );
	}

	public function test_get_request_language_slug_prefers_query_var(): void {
		$GLOBALS['rmsp_test_query_vars']['rm_sitemap_lang'] = 'fr';
		$GLOBALS['rmsp_test_language_terms']['en']          = true;
		$_SERVER['REQUEST_URI']                             = '/en/post-sitemap.xml';

		$this->assertSame( 'fr', $this->urls->get_request_language_slug() );
	}

	public function test_get_request_language_slug_falls_back_to_uri(): void {
		$GLOBALS['rmsp_test_language_terms']['en'] = true;
		$_SERVER['REQUEST_URI']                    = '/en/post-sitemap2.xml';

		$this->assertSame( 'en', $this->urls->get_request_language_slug() );
	}
}
