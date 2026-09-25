<?php

namespace RankMathSitemapsPolylang;

defined( 'ABSPATH' ) || exit;

/**
 * Registers per-language rewrite rules and the language query var.
 *
 * Does not flush rewrite rules.
 */
final class Router {

	public const QUERY_VAR = 'rm_sitemap_lang';

	/**
	 * Registers rewrite rules for non-default language sitemap URLs.
	 */
	public static function register_rewrites(): void {
		if ( ! Url_Helper::is_multilingual() ) {
			return;
		}

		global $wp;

		$wp->add_query_var( self::QUERY_VAR );

		$base = \RankMath\Sitemap\Router::get_sitemap_base();

		foreach ( Url_Helper::get_active_language_slugs() as $slug ) {
			$prefix = Url_Helper::get_language_rewrite_prefix( $slug );

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
	 * Removes language-prefixed sitemap rules and persists permalinks on deactivation.
	 */
	public static function flush_rewrites_on_deactivation(): void {
		remove_action( 'init', [ self::class, 'register_rewrites' ], 0 );
		add_filter( 'rewrite_rules_array', [ self::class, 'strip_language_sitemap_rules' ], 999 );
		flush_rewrite_rules( false );
		remove_filter( 'rewrite_rules_array', [ self::class, 'strip_language_sitemap_rules' ], 999 );
	}

	/**
	 * Drops rewrite rules that route to rm_sitemap_lang.
	 *
	 * @param array<string, string> $rules Rewrite rules.
	 * @return array<string, string>
	 */
	public static function strip_language_sitemap_rules( array $rules ): array {
		foreach ( $rules as $pattern => $query ) {
			if ( false !== strpos( $query, self::QUERY_VAR . '=' ) ) {
				unset( $rules[ $pattern ] );
			}
		}

		return $rules;
	}
}
