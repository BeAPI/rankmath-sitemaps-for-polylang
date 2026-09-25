<?php
/**
 * Router tests.
 *
 * @package RankMathSitemapsPolylang
 */

declare(strict_types=1);

namespace RankMathSitemapsPolylang\Tests;

use PHPUnit\Framework\TestCase;
use RankMathSitemapsPolylang\Router;

/**
 * @covers \RankMathSitemapsPolylang\Router
 */
final class Router_Test extends TestCase {

	public function test_strip_language_sitemap_rules_removes_only_plugin_rules(): void {
		$rules = [
			'^post-sitemap.xml$' => 'index.php?sitemap=post',
			'^en/([^/]+?)-sitemap.xml$' => 'index.php?sitemap=$matches[1]&rm_sitemap_lang=en',
			'^page-sitemap.xml$' => 'index.php?sitemap=page',
		];

		$result = Router::strip_language_sitemap_rules( $rules );

		$this->assertSame(
			[
				'^post-sitemap.xml$' => 'index.php?sitemap=post',
				'^page-sitemap.xml$' => 'index.php?sitemap=page',
			],
			$result
		);
	}

	public function test_strip_language_sitemap_rules_empty_input(): void {
		$this->assertSame( [], Router::strip_language_sitemap_rules( [] ) );
	}

	public function test_strip_language_sitemap_rules_all_plugin_rules(): void {
		$rules = [
			'^en/([^/]+?)-sitemap.xml$' => 'index.php?sitemap=$matches[1]&rm_sitemap_lang=en',
			'^de/([^/]+?)-sitemap.xml$' => 'index.php?sitemap=$matches[1]&rm_sitemap_lang=de',
		];

		$this->assertSame( [], Router::strip_language_sitemap_rules( $rules ) );
	}
}
