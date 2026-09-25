<?php

namespace RankMathSitemapsPolylang;

defined( 'ABSPATH' ) || exit;

/**
 * Registers hooks after Polylang is ready.
 *
 * Does not generate sitemap XML.
 */
final class Plugin {

	private static ?self $instance = null;

	private bool $booted = false;

	/**
	 * Returns the plugin singleton.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers WordPress and Polylang hooks for sitemap routing and caching.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'init', [ Router::class, 'register_rewrites' ], 0 );
		add_action( 'pll_add_language', [ self::class, 'register_rewrites_after_language_change' ] );
		add_action( 'pll_delete_language', [ self::class, 'register_rewrites_after_language_change' ] );
		add_action( 'pll_update_language', [ self::class, 'register_rewrites_after_language_change' ] );
		add_action( 'pll_add_language', [ self::class, 'clear_sitemap_caches' ] );
		add_action( 'pll_update_language', [ self::class, 'handle_language_update' ], 10, 2 );
		add_action( 'edited_term', [ Sitemap_Cache::class, 'maybe_clear_on_language_term_edit' ], 10, 3 );
		add_action( 'delete_term', [ Sitemap_Cache::class, 'maybe_clear_on_language_delete' ], 10, 3 );

		$use_cache = Url_Helper::is_multilingual() && apply_filters( 'rank_math/sitemap/enable_caching', true ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		if ( $use_cache ) {
			Sitemap_Cache::register_invalidation_hooks();
		}

		if ( ! PLL() instanceof \PLL_Frontend ) {
			return;
		}

		add_action( 'pll_init', [ Rank_Math_Overrides::class, 'neutralize' ], 20 );

		$context = new Language_Context();
		$context->register_hooks();

		if ( $use_cache ) {
			$cache = new Sitemap_Cache();
			$cache->register_serving_hooks();
		}

		$index = new Index_Expander();
		$index->register_hooks();
	}

	/**
	 * Re-registers rewrite rules and persists them after a Polylang language change.
	 */
	public static function register_rewrites_after_language_change(): void {
		Router::register_rewrites();
		flush_rewrite_rules( false );
	}

	/**
	 * Clears plugin sitemap caches and Rank Math sitemap cache when available.
	 */
	public static function clear_sitemap_caches(): void {
		Sitemap_Cache::clear_all();

		if ( class_exists( '\RankMath\Sitemap\Cache_Watcher' ) ) {
			\RankMath\Sitemap\Cache_Watcher::clear();
		}
	}

	/**
	 * Invalidates sitemap cache for the updated language.
	 *
	 * @param array         $args     Language update arguments.
	 * @param \PLL_Language $old_lang Previous language object.
	 */
	public static function handle_language_update( array $args, \PLL_Language $old_lang ): void {
		$new_slug = isset( $args['slug'] ) ? (string) $args['slug'] : $old_lang->slug;

		Sitemap_Cache::clear_language( $new_slug );

		if ( $new_slug !== $old_lang->slug ) {
			Sitemap_Cache::clear_language( $old_lang->slug );
		}

		if ( class_exists( '\RankMath\Sitemap\Cache_Watcher' ) ) {
			\RankMath\Sitemap\Cache_Watcher::clear();
		}
	}
}
