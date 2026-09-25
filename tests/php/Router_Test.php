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
use RankMathSitemapsPolylang\Url_Helper;

/**
 * @covers \RankMathSitemapsPolylang\Router
 */
final class Router_Test extends TestCase {

	private Router $router;

	protected function setUp(): void {
		parent::setUp();
		$this->router = new Router( new Url_Helper() );
	}

	public function test_strip_language_sitemap_rules_removes_only_plugin_rules(): void {
		$rules = [
			'^post-sitemap.xml$' => 'index.php?sitemap=post',
			'^en/([^/]+?)-sitemap.xml$' => 'index.php?sitemap=$matches[1]&rm_sitemap_lang=en',
			'^page-sitemap.xml$' => 'index.php?sitemap=page',
		];

		$result = $this->router->strip_language_sitemap_rules( $rules );

		$this->assertSame(
			[
				'^post-sitemap.xml$' => 'index.php?sitemap=post',
				'^page-sitemap.xml$' => 'index.php?sitemap=page',
			],
			$result
		);
	}

	public function test_strip_language_sitemap_rules_empty_input(): void {
		$this->assertSame( [], $this->router->strip_language_sitemap_rules( [] ) );
	}

	public function test_strip_language_sitemap_rules_all_plugin_rules(): void {
		$rules = [
			'^en/([^/]+?)-sitemap.xml$' => 'index.php?sitemap=$matches[1]&rm_sitemap_lang=en',
			'^de/([^/]+?)-sitemap.xml$' => 'index.php?sitemap=$matches[1]&rm_sitemap_lang=de',
		];

		$this->assertSame( [], $this->router->strip_language_sitemap_rules( $rules ) );
	}
}
