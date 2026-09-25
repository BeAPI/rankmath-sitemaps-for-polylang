<?php

namespace RankMathSitemapsPolylang;

use PLL_Frontend;
use PLL_Language;
use RankMath\Sitemap\Cache_Watcher;

/**
 * Registers hooks after Polylang is ready.
 *
 * Does not generate sitemap XML.
 */
final class Plugin {

	private static ?self $instance = null;

	private bool $booted = false;

	private Url_Helper $urls;

	private Router $router;

	private Sitemap_Cache $cache;

	private Rank_Math_Overrides $overrides;

	private Index_Expander $index;

	/**
	 * Builds the service graph once for this singleton.
	 */
	private function __construct() {
		$this->urls      = new Url_Helper();
		$this->router    = new Router( $this->urls );
		$this->cache     = new Sitemap_Cache( $this->urls );
		$this->overrides = new Rank_Math_Overrides( $this->urls );
		$this->index     = new Index_Expander( $this->urls, $this->cache );
	}

	/**
	 * Returns the plugin singleton.
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

		add_action( 'init', [ $this->router, 'register_rewrites' ], 0 );
		add_action( 'pll_add_language', [ $this, 'register_rewrites_after_language_change' ] );
		add_action( 'pll_delete_language', [ $this, 'register_rewrites_after_language_change' ] );
		add_action( 'pll_update_language', [ $this, 'register_rewrites_after_language_change' ] );
		add_action( 'pll_add_language', [ $this, 'clear_sitemap_caches' ] );
		add_action( 'pll_update_language', [ $this, 'handle_language_update' ], 10, 2 );
		add_action( 'edited_term', [ $this, 'handle_language_term_edit' ], 10, 3 );
		add_action( 'delete_term', [ $this, 'handle_language_term_delete' ], 10, 3 );

		$use_cache = $this->urls->is_multilingual() && apply_filters( 'rank_math/sitemap/enable_caching', true ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		if ( $use_cache ) {
			$this->cache->register_invalidation_hooks();
		}

		if ( ! PLL() instanceof PLL_Frontend ) {
			return;
		}

		add_action( 'pll_init', [ $this->overrides, 'register_hooks' ], 20 );

		$this->router->register_request_hooks();

		if ( $use_cache ) {
			$this->cache->register_serving_hooks();
		}

		$this->index->register_hooks();
	}

	/**
	 * Activation: register rewrites and clear caches.
	 */
	public function on_activation(): void {
		$this->router->register_rewrites();
		flush_rewrite_rules( false );
		$this->clear_sitemap_caches();
	}

	/**
	 * Deactivation: clear caches and strip language sitemap rewrite rules.
	 */
	public function on_deactivation(): void {
		$this->clear_sitemap_caches();
		$this->router->flush_rewrites_on_deactivation();
	}

	/**
	 * Re-registers rewrite rules and persists them after a Polylang language change.
	 */
	public function register_rewrites_after_language_change(): void {
		$this->router->register_rewrites();
		flush_rewrite_rules( false );
	}

	/**
	 * Clears plugin sitemap caches and Rank Math sitemap cache when available.
	 */
	public function clear_sitemap_caches(): void {
		$this->cache->clear_all();

		if ( class_exists( Cache_Watcher::class ) ) {
			Cache_Watcher::clear();
		}
	}

	/**
	 * Invalidates sitemap cache for the updated language.
	 *
	 * @param array         $args     Language update arguments.
	 * @param PLL_Language  $old_lang Previous language object.
	 */
	public function handle_language_update( array $args, PLL_Language $old_lang ): void {
		$new_slug = isset( $args['slug'] ) ? (string) $args['slug'] : $old_lang->slug;

		$this->cache->clear_language( $new_slug );

		if ( $new_slug !== $old_lang->slug ) {
			$this->cache->clear_language( $old_lang->slug );
		}

		if ( class_exists( Cache_Watcher::class ) ) {
			Cache_Watcher::clear();
		}
	}

	/**
	 * Purge cache for corresponding urlset when a language term is updated.
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function handle_language_term_edit( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( 'language' !== $taxonomy || ! function_exists( 'PLL' ) ) {
			return;
		}

		$lang = PLL()->model->get_language( $term_id );

		if ( ! $lang instanceof PLL_Language ) {
			return;
		}

		// Activate/deactivate (Polylang Pro) updates the language term description.
		// Flush Rank Math's index cache so inactive languages disappear from sitemap_index.xml.
		$this->clear_sitemap_caches();
		$this->router->register_rewrites();
		flush_rewrite_rules( false );
	}

	/**
	 * Purge cache for corresponding urlset when a language term is deleted.
	 *
	 * @param int    $term_id  Deleted term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function handle_language_term_delete( int $term_id, int $tt_id, string $taxonomy ): void {
		$this->cache->clear_on_language_delete( $term_id, $tt_id, $taxonomy );
	}
}
