<?php
/**
 * Url_Helper index / availability probe tests.
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

	protected function setUp(): void {
		parent::setUp();
		rmsp_test_reset_state();
	}

	public function test_is_index_request_when_sitemap_is_one(): void {
		$GLOBALS['rmsp_test_query_vars']['sitemap'] = '1';

		$this->assertTrue( Url_Helper::is_index_request() );
	}

	public function test_is_index_request_false_during_availability_probe(): void {
		$GLOBALS['rmsp_test_query_vars']['sitemap'] = '1';

		Url_Helper::begin_availability_check();

		$this->assertFalse( Url_Helper::is_index_request() );

		Url_Helper::end_availability_check();

		$this->assertTrue( Url_Helper::is_index_request() );
	}

	public function test_is_index_request_false_for_urlset_type(): void {
		$GLOBALS['rmsp_test_query_vars']['sitemap'] = 'post';

		$this->assertFalse( Url_Helper::is_index_request() );
	}
}
