<?php

namespace RankMathSitemapsPolylang;

defined( 'ABSPATH' ) || exit;

/**
 * Builds public sitemap URLs and language path prefixes.
 *
 * Reads query vars for the current sitemap request.
 */
final class Url_Helper {

	/**
	 * Whether a urlset availability probe is running during index generation.
	 */
	private static bool $availability_check = false;

	/**
	 * Active Polylang languages (excludes inactive Pro languages).
	 *
	 * @return \PLL_Language[]
	 */
	public static function get_active_languages(): array {
		if ( ! function_exists( 'PLL' ) ) {
			return [];
		}

		return array_values(
			wp_list_filter( PLL()->model->get_languages_list(), [ 'active' => false ], 'NOT' )
		);
	}

	/**
	 * Active language slugs.
	 *
	 * @return string[]
	 */
	public static function get_active_language_slugs(): array {
		return wp_list_pluck( self::get_active_languages(), 'slug' );
	}

	/**
	 * Returns the number of active Polylang languages.
	 *
	 * @return int
	 */
	public static function get_active_language_count(): int {
		return count( self::get_active_languages() );
	}

	/**
	 * Returns whether at least two active languages are configured.
	 *
	 * @return bool
	 */
	public static function is_multilingual(): bool {
		return self::get_active_language_count() >= 2;
	}

	/**
	 * Returns whether the slug belongs to an active language.
	 *
	 * @param string $slug Language slug.
	 * @return bool
	 */
	public static function is_active_language( string $slug ): bool {
		return in_array( $slug, self::get_active_language_slugs(), true );
	}

	/**
	 * Returns the rewrite path prefix for a language under home_url().
	 *
	 * Examples (hide_default, default language "fr"):
	 * - "fr" → "" (https://domain.tld/post-sitemap.xml)
	 * - "en" → "en/" (https://domain.tld/en/post-sitemap.xml)
	 * - "en" with WordPress in /site/ → "en/" (path segment after home: site/en/)
	 *
	 * @param string $lang Language slug.
	 * @return string Relative prefix including trailing slash, or empty for default/hidden.
	 */
	public static function get_language_rewrite_prefix( string $lang ): string {
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
	 * Examples (hide_default, default "fr"):
	 * - get_sitemap_loc( 'post', 1, 'fr' ) → https://domain.tld/post-sitemap.xml
	 * - get_sitemap_loc( 'post', 1, 'en' ) → https://domain.tld/en/post-sitemap.xml
	 * - get_sitemap_loc( 'post', 2, 'en' ) → https://domain.tld/en/post-sitemap2.xml
	 * - WordPress in /site/ → https://domain.tld/site/en/post-sitemap.xml
	 *
	 * @param string $type Sitemap type slug.
	 * @param int    $page Urlset page number.
	 * @param string $lang Language slug.
	 * @return string Absolute sitemap URL.
	 */
	public static function get_sitemap_loc( string $type, int $page, string $lang ): string {
		$page_suffix = $page > 1 ? (string) $page : '';
		$filename    = $type . '-sitemap' . $page_suffix . '.xml';
		$prefix      = self::get_language_rewrite_prefix( $lang );

		if ( '' === $prefix ) {
			return \RankMath\Sitemap\Router::get_base_url( $filename );
		}

		$base = \RankMath\Sitemap\Router::get_sitemap_base();

		return home_url( $base . $prefix . $filename );
	}

	/**
	 * Parses sitemap type and page from a URL or path.
	 *
	 * Example: https://domain.tld/en/post-sitemap2.xml → type "post", page 2.
	 *
	 * @param string $url Full URL or path.
	 * @return array{type: string, page: int}|null Parsed values or null when not a urlset filename.
	 */
	public static function parse_sitemap_filename( string $url ): ?array {
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
	public static function get_target_language(): string {
		$lang = get_query_var( Router::QUERY_VAR );

		if ( is_string( $lang ) && '' !== $lang && self::is_active_language( $lang ) ) {
			return $lang;
		}

		return (string) pll_default_language();
	}

	/**
	 * Returns whether the current request is a Rank Math sitemap (not XSL).
	 *
	 * @return bool
	 */
	public static function is_sitemap_request(): bool {
		$type = get_query_var( 'sitemap' );

		return ! empty( $type );
	}

	/**
	 * Returns whether the current request is the sitemap index (sitemap query var "1").
	 *
	 * @return bool
	 */
	public static function is_index_request(): bool {
		if ( self::$availability_check ) {
			return false;
		}

		return '1' === (string) get_query_var( 'sitemap' );
	}

	/**
	 * Marks the start of an index availability probe (not a front-end index request).
	 */
	public static function begin_availability_check(): void {
		self::$availability_check = true;
	}

	/**
	 * Marks the end of an index availability probe.
	 */
	public static function end_availability_check(): void {
		self::$availability_check = false;
	}

	/**
	 * Returns the current urlset page number from the query.
	 *
	 * @return int Page number, at least 1.
	 */
	public static function get_sitemap_page(): int {
		$page = get_query_var( 'sitemap_n' );

		if ( empty( $page ) || ! is_scalar( $page ) ) {
			return 1;
		}

		return max( 1, (int) $page );
	}

	/**
	 * Returns the current urlset type slug from the query.
	 *
	 * @return string
	 */
	public static function get_sitemap_type(): string {
		$type = get_query_var( 'sitemap' );

		return is_string( $type ) ? $type : '';
	}
}
