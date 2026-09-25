<?php
/**
 * Sitemap_Cache transient key and version tests.
 *
 * @package RankMathSitemapsPolylang
 */

declare(strict_types=1);

namespace RankMathSitemapsPolylang\Tests;

use PHPUnit\Framework\TestCase;
use RankMathSitemapsPolylang\Sitemap_Cache;
use RankMathSitemapsPolylang\Url_Helper;

/**
 * @covers \RankMathSitemapsPolylang\Sitemap_Cache
 */
final class Sitemap_Cache_Test extends TestCase {

	private Sitemap_Cache $cache;

	protected function setUp(): void {
		parent::setUp();
		rmsp_test_reset_state();
		$this->cache = new Sitemap_Cache( new Url_Helper() );
	}

	public function test_transient_key_default_shape(): void {
		$this->assertSame(
			'rmsp_sitemap_g1_l1_t1_en_post_1',
			$this->cache->transient_key( Sitemap_Cache::NAMESPACE_SITEMAP, 'post', 'en', 1 )
		);
	}

	public function test_bump_all_increments_global_segment(): void {
		$before = $this->cache->transient_key( Sitemap_Cache::NAMESPACE_SITEMAP, 'post', 'en', 1 );

		$this->cache->bump_all_versions( 'rmsp_sitemap_versions' );

		$after = $this->cache->transient_key( Sitemap_Cache::NAMESPACE_SITEMAP, 'post', 'en', 1 );

		$this->assertSame( 'rmsp_sitemap_g1_l1_t1_en_post_1', $before );
		$this->assertSame( 'rmsp_sitemap_g2_l1_t1_en_post_1', $after );
	}

	public function test_bump_language_increments_language_segment(): void {
		$this->cache->bump_language_versions( 'rmsp_sitemap_versions', 'en' );

		$this->assertSame(
			'rmsp_sitemap_g1_l2_t1_en_post_1',
			$this->cache->transient_key( Sitemap_Cache::NAMESPACE_SITEMAP, 'post', 'en', 1 )
		);
		$this->assertSame(
			'rmsp_sitemap_g1_l1_t1_fr_post_1',
			$this->cache->transient_key( Sitemap_Cache::NAMESPACE_SITEMAP, 'post', 'fr', 1 )
		);
	}

	public function test_bump_type_language_increments_type_lang_segment(): void {
		$this->cache->bump_type_language_versions( 'rmsp_sitemap_versions', 'post', 'en' );

		$this->assertSame(
			'rmsp_sitemap_g1_l1_t2_en_post_1',
			$this->cache->transient_key( Sitemap_Cache::NAMESPACE_SITEMAP, 'post', 'en', 1 )
		);
		$this->assertSame(
			'rmsp_sitemap_g1_l1_t1_en_page_1',
			$this->cache->transient_key( Sitemap_Cache::NAMESPACE_SITEMAP, 'page', 'en', 1 )
		);
	}

	public function test_bump_language_empty_slug_is_noop(): void {
		$before = $this->cache->transient_key( Sitemap_Cache::NAMESPACE_SITEMAP, 'post', 'en', 1 );

		$this->cache->bump_language_versions( 'rmsp_sitemap_versions', '' );

		$this->assertSame(
			$before,
			$this->cache->transient_key( Sitemap_Cache::NAMESPACE_SITEMAP, 'post', 'en', 1 )
		);
	}

	public function test_avail_namespace_uses_separate_prefix(): void {
		$this->assertSame(
			'rmsp_avail_g1_l1_t1_en_post_1',
			$this->cache->transient_key( Sitemap_Cache::NAMESPACE_AVAIL, 'post', 'en', 1 )
		);
	}
}
