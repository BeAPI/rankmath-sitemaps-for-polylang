<?php

namespace RankMathSitemapsPolylang;

use PLL_Language;
use RankMath\ThirdParty\Polylang\Polylang as Rank_Math_Polylang;
use WP_Hook;

/**
 * Removes Rank Math mixed-language sitemap filters.
 *
 * Applies per-language SQL and get_terms arguments for urlsets.
 */
final class Rank_Math_Overrides {

	private const RM_POLYLANG_CLASS = Rank_Math_Polylang::class;

	private Url_Helper $urls;

	public function __construct( Url_Helper $urls ) {
		$this->urls = $urls;
	}

	/**
	 * Replace Rank Math Polylang sitemap integration with per-urlset language filters.
	 */
	public function register_hooks(): void {
		$this->remove_rm_polylang_filter( 'rank_math/sitemap/post_count/join', 'sitemap_join_clause' );
		$this->remove_rm_polylang_filter( 'rank_math/sitemap/get_posts/join', 'sitemap_join_clause' );
		$this->remove_rm_polylang_filter( 'rank_math/sitemap/post_count/where', 'sitemap_where_clause' );
		$this->remove_rm_polylang_filter( 'rank_math/sitemap/get_posts/where', 'sitemap_where_clause' );
		$this->remove_rm_polylang_filter( 'get_terms_args', 'update_term_query_args' );
		$this->remove_rm_polylang_filter( 'rank_math/sitemap/exclude_post_type', 'inject_language_sitemap_entries', 0 );

		add_filter( 'rank_math/sitemap/post_count/join', [ $this, 'sitemap_join_clause' ], 10, 2 );
		add_filter( 'rank_math/sitemap/get_posts/join', [ $this, 'sitemap_join_clause' ], 10, 2 );
		add_filter( 'rank_math/sitemap/post_count/where', [ $this, 'sitemap_where_clause' ], 10, 2 );
		add_filter( 'rank_math/sitemap/get_posts/where', [ $this, 'sitemap_where_clause' ], 10, 2 );
		add_filter( 'get_terms_args', [ $this, 'update_term_query_args' ] );
	}

	/**
	 * Add Polylang JOIN for per-language urlset queries only.
	 *
	 * @param string $sql       Existing JOIN clause.
	 * @param string $post_type Post type being queried.
	 */
	public function sitemap_join_clause( string $sql, string $post_type ): string {
		if ( $this->urls->is_index_request() || ! pll_is_translated_post_type( $post_type ) ) {
			return $sql;
		}

		return $sql . PLL()->model->post->join_clause( 'p' );
	}

	/**
	 * Restrict sitemap post queries to the target language.
	 *
	 * @param string $sql       Existing WHERE clause.
	 * @param string $post_type Post type being queried.
	 */
	public function sitemap_where_clause( string $sql, string $post_type ): string {
		if ( $this->urls->is_index_request() || ! pll_is_translated_post_type( $post_type ) ) {
			return $sql;
		}

		$language = PLL()->model->get_language( $this->urls->get_target_language() );

		if ( ! $language instanceof PLL_Language ) {
			return $sql;
		}

		return $sql . PLL()->model->post->where_clause( $language );
	}

	/**
	 * Restrict taxonomy sitemap queries to the target language.
	 *
	 * @param array $args get_terms() arguments.
	 */
	public function update_term_query_args( array $args ): array {
		if ( ! $this->urls->is_sitemap_request() || $this->urls->is_index_request() ) {
			return $args;
		}

		$args['lang'] = $this->urls->get_target_language();

		return $args;
	}

	/**
	 * Remove a Rank Math Polylang callback without holding its instance.
	 *
	 * Walks `$wp_filter` for callbacks whose object is Rank Math's Polylang class
	 * and whose method matches `$method`.
	 *
	 * @param string   $hook     Filter hook (e.g. `rank_math/sitemap/get_posts/join`).
	 * @param string   $method   Method name on Rank Math's Polylang class.
	 * @param int|null $priority Optional priority to match; null checks every priority.
	 */
	private function remove_rm_polylang_filter( string $hook, string $method, ?int $priority = null ): void {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return;
		}

		$hook_object = $wp_filter[ $hook ];

		if ( ! $hook_object instanceof WP_Hook ) {
			return;
		}

		foreach ( $hook_object->callbacks as $prio => $callbacks ) {
			if ( null !== $priority && (int) $prio !== $priority ) {
				continue;
			}

			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];

				if (
					is_array( $function )
					&& is_object( $function[0] )
					&& self::RM_POLYLANG_CLASS === get_class( $function[0] )
					&& $function[1] === $method
				) {
					remove_filter( $hook, $function, (int) $prio );
				}
			}
		}
	}
}
