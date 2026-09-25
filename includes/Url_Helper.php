<?php

namespace RankMathSitemapsPolylang;

use RankMath\Sitemap\Router as Rank_Math_Router;

/**
 * Builds public sitemap URLs and language path prefixes.
 *
 * Reads query vars for the current sitemap request.
 */
final class Url_Helper {

	/**
	 * Whether a urlset probe is running during index generation.
	 */
	private bool $urlset_probe = false;

	/**
	 * All configured Polylang languages (including inactive Pro languages).
	 *
	 * @return \PLL_Language[]
	 */
	public function get_languages(): array {
		if ( ! function_exists( 'PLL' ) ) {
			return [];
		}

		return array_values( PLL()->model->get_languages_list() );
	}

	/**
	 * Active Polylang languages (excludes inactive Pro languages).
	 *
	 * Uses empty( $language->active ) to match Polylang Pro's own checks.
	 *
	 * @return \PLL_Language[]
	 */
	public function get_active_languages(): array {
		$languages = [];

		foreach ( $this->get_languages() as $language ) {
			if ( ! empty( $language->active ) ) {
				$languages[] = $language;
			}
		}

		return $languages;
	}

	/**
	 * All language slugs (including inactive Pro languages).
	 *
	 * @return string[]
	 */
	public function get_language_slugs(): array {
		return wp_list_pluck( $this->get_languages(), 'slug' );
	}

	/**
	 * Active language slugs.
	 *
	 * @return string[]
	 */
	public function get_active_language_slugs(): array {
		return wp_list_pluck( $this->get_active_languages(), 'slug' );
	}

	/**
	 * Returns the number of active Polylang languages.
	 */
	public function get_active_language_count(): int {
		return count( $this->get_active_languages() );
	}

	/**
	 * Returns whether at least two active languages are configured.
	 */
	public function is_multilingual(): bool {
		return $this->get_active_language_count() >= 2;
	}

	/**
	 * Returns whether the slug belongs to an active language.
	 *
	 * @param string $slug Language slug.
	 */
	public function is_active_language( string $slug ): bool {
		return in_array( $slug, $this->get_active_language_slugs(), true );
	}

	/**
	 * Returns the rewrite path prefix for a language under home_url().
	 *
	 * Examples (default language `fr`, hide_default on, site at `/`):
	 * - `get_language_rewrite_prefix( 'fr' )` → `''`
	 * - `get_language_rewrite_prefix( 'en' )` → `'en/'`
	 *
	 * Subdirectory install (`home_url` = `https://example.com/site/`):
	 * - `get_language_rewrite_prefix( 'en' )` → `'en/'` (relative to home, not `/site/en/`)
	 *
	 * @param string $lang Language slug.
	 * @return string Relative prefix including trailing slash, or empty for default/hidden.
	 */
	public function get_language_rewrite_prefix( string $lang ): string {
		$default = pll_default_language();

		if ( $lang === $default && ! empty( PLL()->options['hide_default'] ) ) {
			return '';
		}

		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$lang_path = wp_parse_url( pll_home_url( $lang ), PHP_URL_PATH );

		$home_path = untrailingslashit( (string) $home_path );
		$lang_path = untrailingslashit( (string) $lang_path );

		if ( '' === $home_path ) {
			$home_path = '/';
		}

		if ( '' === $lang_path ) {
			$lang_path = '/';
		}

		if ( $lang_path === $home_path ) {
			return '';
		}

		if ( '/' !== $home_path && 0 === strpos( $lang_path, $home_path . '/' ) ) {
			return substr( $lang_path, strlen( $home_path ) + 1 ) . '/';
		}

		if ( '/' === $home_path && 0 === strpos( $lang_path, '/' ) ) {
			$suffix = ltrim( $lang_path, '/' );

			return '' !== $suffix ? $suffix . '/' : '';
		}

		return '';
	}

	/**
	 * Builds the public sitemap URL for a type, page, and language.
	 *
	 * Examples (sitemap base `sitemap_index.xml` sibling path, typically `/`):
	 * - `get_sitemap_loc( 'post', 1, 'fr' )` → `https://example.com/post-sitemap.xml` (default/hidden)
	 * - `get_sitemap_loc( 'post', 1, 'en' )` → `https://example.com/en/post-sitemap.xml`
	 * - `get_sitemap_loc( 'post', 2, 'en' )` → `https://example.com/en/post-sitemap2.xml`
	 * - `get_sitemap_loc( 'category', 1, 'en' )` → `https://example.com/en/category-sitemap.xml`
	 *
	 * @param string $type Sitemap type slug.
	 * @param int    $page Urlset page number.
	 * @param string $lang Language slug.
	 * @return string Absolute sitemap URL.
	 */
	public function get_sitemap_loc( string $type, int $page, string $lang ): string {
		$page_suffix = $page > 1 ? (string) $page : '';
		$filename    = $type . '-sitemap' . $page_suffix . '.xml';
		$prefix      = $this->get_language_rewrite_prefix( $lang );

		if ( '' === $prefix ) {
			return Rank_Math_Router::get_base_url( $filename );
		}

		$base = Rank_Math_Router::get_sitemap_base();

		return home_url( $base . $prefix . $filename );
	}

	/**
	 * Parses sitemap type and page from a URL or path.
	 *
	 * Examples:
	 * - `/post-sitemap.xml` → `['type' => 'post', 'page' => 1]`
	 * - `/en/post-sitemap2.xml` → `['type' => 'post', 'page' => 2]`
	 * - `/site/en/page-sitemap.xml` → `['type' => 'page', 'page' => 1]`
	 * - `https://example.com/post-sitemap.xml?foo=1` → `['type' => 'post', 'page' => 1]`
	 * - `/feed.xml` → `null`
	 *
	 * @param string $url Full URL or path.
	 * @return array{type: string, page: int}|null Parsed values or null when not a urlset filename.
	 */
	public function parse_sitemap_filename( string $url ): ?array {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $path ) || ! preg_match( '#([^/]+?)-sitemap([0-9]+)?\.xml$#', $path, $matches ) ) {
			return null;
		}

		return [
			'type' => $matches[1],
			'page' => ! empty( $matches[2] ) ? max( 1, (int) $matches[2] ) : 1,
		];
	}

	/**
	 * Returns the target language for the current sitemap request.
	 *
	 * @return string Active language slug or the default language when unset.
	 */
	public function get_target_language(): string {
		$lang = $this->get_request_language_slug();

		if ( '' !== $lang && $this->is_active_language( $lang ) ) {
			return $lang;
		}

		return (string) pll_default_language();
	}

	/**
	 * Language slug for the current sitemap request (query var or URL prefix).
	 *
	 * Does not require the language to be active — callers must validate.
	 */
	public function get_request_language_slug(): string {
		$lang = get_query_var( Router::QUERY_VAR );

		if ( is_string( $lang ) && '' !== $lang ) {
			return $lang;
		}

		return $this->detect_sitemap_language_from_request();
	}

	/**
	 * Detects a language prefix on the current sitemap request URI.
	 *
	 * Used when rewrite rules for inactive languages were flushed away and
	 * Polylang / Rank Math resolve the URL without rm_sitemap_lang.
	 *
	 * Uses the language taxonomy directly so inactive Pro languages remain
	 * detectable for anonymous visitors (Polylang's hide_inactive proxy
	 * removes them from get_languages_list()).
	 *
	 * Examples (`REQUEST_URI`):
	 * - `/en/post-sitemap.xml` → `'en'`
	 * - `/en/post-sitemap2.xml` → `'en'`
	 * - `/post-sitemap.xml` → `''` (no language segment)
	 * - `/blog/post-sitemap.xml` → `''` when `blog` is not a language term
	 */
	public function detect_sitemap_language_from_request(): string {
		if ( empty( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$path = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );

		if ( ! is_string( $path ) || ! preg_match( '#/([a-z0-9_-]+)/([^/]+?)-sitemap([0-9]+)?\.xml$#i', $path, $matches ) ) {
			return '';
		}

		$candidate = strtolower( $matches[1] );

		if ( ! taxonomy_exists( 'language' ) ) {
			return '';
		}

		$term = get_term_by( 'slug', $candidate, 'language' );

		if ( ! is_object( $term ) || empty( $term->slug ) ) {
			return '';
		}

		return $candidate;
	}

	/**
	 * Returns whether the current request is a Rank Math sitemap (not XSL).
	 */
	public function is_sitemap_request(): bool {
		$type = get_query_var( 'sitemap' );

		return ! empty( $type );
	}

	/**
	 * Returns whether the current request is the sitemap index (sitemap query var "1").
	 */
	public function is_index_request(): bool {
		if ( $this->urlset_probe ) {
			return false;
		}

		return '1' === (string) get_query_var( 'sitemap' );
	}

	/**
	 * Marks the start of a urlset probe (not a front-end index request).
	 */
	public function begin_urlset_probe(): void {
		$this->urlset_probe = true;
	}

	/**
	 * Marks the end of a urlset probe.
	 */
	public function end_urlset_probe(): void {
		$this->urlset_probe = false;
	}

	/**
	 * Returns the current urlset page number from the query.
	 *
	 * @return int Page number, at least 1.
	 */
	public function get_sitemap_page(): int {
		$page = get_query_var( 'sitemap_n' );

		if ( empty( $page ) || ! is_scalar( $page ) ) {
			return 1;
		}

		return max( 1, (int) $page );
	}

	/**
	 * Returns the current urlset type slug from the query.
	 */
	public function get_sitemap_type(): string {
		$type = get_query_var( 'sitemap' );

		return is_string( $type ) ? $type : '';
	}
}
