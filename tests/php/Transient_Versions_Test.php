<?php
/**
 * Transient_Versions tests.
 *
 * @package RankMathSitemapsPolylang
 */

declare(strict_types=1);

namespace RankMathSitemapsPolylang\Tests;

use PHPUnit\Framework\TestCase;
use RankMathSitemapsPolylang\Transient_Versions;

/**
 * @covers \RankMathSitemapsPolylang\Transient_Versions
 */
final class Transient_Versions_Test extends TestCase {

	private const OPTION = Transient_Versions::SITEMAP_OPTION;

	protected function setUp(): void {
		parent::setUp();
		rmsp_test_reset_state();
	}

	public function test_get_suffix_default_shape(): void {
		$this->assertSame( 'g1_l1_t1', Transient_Versions::get_suffix( self::OPTION, 'post', 'en' ) );
	}

	public function test_bump_all_increments_global_segment(): void {
		$before = Transient_Versions::get_suffix( self::OPTION, 'post', 'en' );

		Transient_Versions::bump_all( self::OPTION );

		$after = Transient_Versions::get_suffix( self::OPTION, 'post', 'en' );

		$this->assertSame( 'g1_l1_t1', $before );
		$this->assertSame( 'g2_l1_t1', $after );
	}

	public function test_bump_language_increments_language_segment(): void {
		Transient_Versions::bump_language( self::OPTION, 'en' );

		$this->assertSame( 'g1_l2_t1', Transient_Versions::get_suffix( self::OPTION, 'post', 'en' ) );
		$this->assertSame( 'g1_l1_t1', Transient_Versions::get_suffix( self::OPTION, 'post', 'fr' ) );
	}

	public function test_bump_type_language_increments_type_lang_segment(): void {
		Transient_Versions::bump_type_language( self::OPTION, 'post', 'en' );

		$this->assertSame( 'g1_l1_t2', Transient_Versions::get_suffix( self::OPTION, 'post', 'en' ) );
		$this->assertSame( 'g1_l1_t1', Transient_Versions::get_suffix( self::OPTION, 'page', 'en' ) );
	}

	public function test_bump_language_empty_slug_is_noop(): void {
		$before = Transient_Versions::get_suffix( self::OPTION, 'post', 'en' );

		Transient_Versions::bump_language( self::OPTION, '' );

		$this->assertSame( $before, Transient_Versions::get_suffix( self::OPTION, 'post', 'en' ) );
	}
}
