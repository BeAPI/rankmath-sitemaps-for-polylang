<?php

namespace RankMathSitemapsPolylang;

use PLL_Language;
use RankMath\Sitemap\Router as Rank_Math_Router;
use WP_Post;
use WP_Query;

/**
 * Registers per-language rewrite rules, query var, and sitemap request language context.
 *
 * Does not flush rewrite rules.
 */
final class Router {

	public const QUERY_VAR = 'rm_sitemap_lang';

	private Url_Helper $urls;

	public function __construct( Url_Helper $urls ) {
		$this->urls = $urls;
	}

	/**
	 * Registers rewrite rules for language-prefixed sitemap URLs.
	 *
	 * Includes inactive Polylang Pro languages so those URLs still set
	 * rm_sitemap_lang and can return 404 instead of falling through to the
	 * default-language sitemap (Rank Math exits at parse_query before Polylang's 404).
	 *
	 * Example rule (sitemap base `/`, language `en`):
	 * - Pattern: `en/([^/]+?)-sitemap([0-9]+)?\.xml$`
	 * - Matches: `/en/post-sitemap.xml`, `/en/post-sitemap2.xml`
	 * - Query: `sitemap=$matches[1]&sitemap_n=$matches[2]&rm_sitemap_lang=en`
	 */
	public function register_rewrites(): void {
		if ( ! function_exists( 'PLL' ) ) {
			return;
		}

		global $wp;

		$wp->add_query_var( self::QUERY_VAR );

		$base = Rank_Math_Router::get_sitemap_base();

		foreach ( $this->urls->get_language_slugs() as $slug ) {
			$prefix = $this->urls->get_language_rewrite_prefix( $slug );

			if ( '' === $prefix ) {
				continue;
			}

			add_rewrite_rule(
				$base . $prefix . '([^/]+?)-sitemap([0-9]+)?\.xml$',
				'index.php?sitemap=$matches[1]&sitemap_n=$matches[2]&' . self::QUERY_VAR . '=' . $slug,
				'top'
			);
		}
	}

	/**
	 * Registers parse_query and sitemap URL filters for the current request.
	 */
	public function register_request_hooks(): void {
		add_action( 'parse_query', [ $this, 'reject_invalid_sitemap_language' ], 0 );
		add_action( 'parse_query', [ $this, 'setup_sitemap_language' ], 1 );
		add_filter( 'rank_math/sitemap/xml_post_url', [ $this, 'fix_front_page_sitemap_url' ], 10, 2 );
	}

	/**
	 * Removes language-prefixed sitemap rules and persists permalinks on deactivation.
	 */
	public function flush_rewrites_on_deactivation(): void {
		remove_action( 'init', [ $this, 'register_rewrites' ], 0 );
		add_filter( 'rewrite_rules_array', [ $this, 'strip_language_sitemap_rules' ], 999 );
		flush_rewrite_rules( false );
		remove_filter( 'rewrite_rules_array', [ $this, 'strip_language_sitemap_rules' ], 999 );
	}

	/**
	 * Drops rewrite rules that route to rm_sitemap_lang.
	 *
	 * @param array<string, string> $rules Rewrite rules.
	 * @return array<string, string>
	 */
	public function strip_language_sitemap_rules( array $rules ): array {
		foreach ( $rules as $pattern => $query ) {
			if ( false !== strpos( $query, self::QUERY_VAR . '=' ) ) {
				unset( $rules[ $pattern ] );
			}
		}

		return $rules;
	}

	/**
	 * Block Rank Math and cache layers before an inactive language slug is normalized away.
	 *
	 * Runs at parse_query priority 0, before Rank Math's request_sitemap (priority 1)
	 * which builds XML and exits.
	 *
	 * @param WP_Query $query Main query.
	 */
	public function reject_invalid_sitemap_language( WP_Query $query ): void {
		if ( ! $query->is_main_query() || ! $this->urls->is_sitemap_request() || $this->urls->is_index_request() ) {
			return;
		}

		$lang = $this->urls->get_request_language_slug();

		if ( '' === $lang ) {
			return;
		}

		if ( $this->urls->is_active_language( $lang ) ) {
			return;
		}

		$query->set_404();
		status_header( 404 );
		set_query_var( 'sitemap', '' );
		set_query_var( 'sitemap_n', '' );
		set_query_var( self::QUERY_VAR, '' );
	}

	/**
	 * Set default language query var and switch PLL curlang before Rank Math renders.
	 *
	 * @param WP_Query $query Main query.
	 */
	public function setup_sitemap_language( WP_Query $query ): void {
		if ( ! $query->is_main_query() || ! $this->urls->is_sitemap_request() ) {
			return;
		}

		$lang = $this->urls->get_request_language_slug();

		if ( '' === $lang ) {
			$lang = (string) pll_default_language();
			set_query_var( self::QUERY_VAR, $lang );
		} else {
			set_query_var( self::QUERY_VAR, $lang );
		}

		if ( ! $this->urls->is_active_language( $lang ) ) {
			$query->set_404();
			status_header( 404 );
			set_query_var( 'sitemap', '' );
			set_query_var( 'sitemap_n', '' );
			set_query_var( self::QUERY_VAR, '' );

			return;
		}

		$language = PLL()->model->get_language( $lang );

		if ( ! $language instanceof PLL_Language ) {
			$query->set_404();
			status_header( 404 );
			set_query_var( 'sitemap', '' );
			set_query_var( 'sitemap_n', '' );
			set_query_var( self::QUERY_VAR, '' );

			return;
		}

		PLL()->curlang = $language;
	}

	/**
	 * Point the front page sitemap entry at the language home URL.
	 *
	 * Rank Math would otherwise emit the page permalink. For the front page
	 * translation of the current sitemap language, use the language home instead.
	 *
	 * Examples (sitemap language `en`, front page translation is post 42):
	 * - Input:  `https://example.com/en/home/`
	 * - Output: `https://example.com/en/`
	 *
	 * Non-front-page posts are left unchanged:
	 * - Input/output: `https://example.com/en/about/`
	 *
	 * @param string  $url  Sitemap URL.
	 * @param WP_Post $post Post object.
	 */
	public function fix_front_page_sitemap_url( string $url, $post ): string {
		$page_on_front_id = (int) get_option( 'page_on_front' );

		if ( ! $page_on_front_id || ! $post instanceof WP_Post ) {
			return $url;
		}

		$lang = pll_get_post_language( $post->ID );

		if ( ! $lang ) {
			return $url;
		}

		if ( (int) pll_get_post( $page_on_front_id, $lang ) === (int) $post->ID ) {
			return pll_home_url( $this->urls->get_target_language() );
		}

		return $url;
	}
}
