<?php

namespace RankMathSitemapsPolylang;

defined( 'ABSPATH' ) || exit;

/**
 * Probes whether a urlset would contain links for a given language.
 *
 * Used while building the sitemap index.
 */
final class Sitemap_Availability {

	private const TRANSIENT_PREFIX = 'rmsp_avail_';

	private const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Whether a sitemap page has at least one URL for the given language.
	 */
	public static function has_urlset_for_language( string $type, int $page, string $lang ): bool {
		if ( ! Url_Helper::is_active_language( $lang ) ) {
			return false;
		}

		$cached = get_transient( self::get_transient_name( $type, $page, $lang ) );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$language = PLL()->model->get_language( $lang );

		if ( ! $language instanceof \PLL_Language ) {
			return false;
		}

		$previous_curlang   = PLL()->curlang;
		$previous_lang_qv   = get_query_var( Router::QUERY_VAR );
		$previous_sitemap   = get_query_var( 'sitemap' );
		$previous_sitemap_n = get_query_var( 'sitemap_n' );

		Url_Helper::begin_availability_check();

		PLL()->curlang = $language;
		set_query_var( Router::QUERY_VAR, $lang );
		set_query_var( 'sitemap', $type );
		set_query_var( 'sitemap_n', $page > 1 ? (string) $page : '' );

		$has_content = self::probe_urlset( $type, $page );

		Url_Helper::end_availability_check();

		PLL()->curlang = $previous_curlang;
		set_query_var( Router::QUERY_VAR, $previous_lang_qv );
		set_query_var( 'sitemap', $previous_sitemap );
		set_query_var( 'sitemap_n', $previous_sitemap_n );

		set_transient( self::get_transient_name( $type, $page, $lang ), $has_content ? 1 : 0, self::CACHE_TTL );

		return $has_content;
	}

	public static function clear_cache(): void {
		Transient_Versions::bump_all( Transient_Versions::AVAIL_OPTION );
	}

	public static function clear_language_cache( string $lang ): void {
		Transient_Versions::bump_language( Transient_Versions::AVAIL_OPTION, $lang );
	}

	public static function clear_type_language_cache( string $type, string $lang ): void {
		Transient_Versions::bump_type_language( Transient_Versions::AVAIL_OPTION, $type, $lang );
	}

	/**
	 * Lightweight existence check without building full urlsets or Rank Math redirects.
	 */
	private static function probe_urlset( string $type, int $page ): bool {
		$max_entries = absint( \RankMath\Helper::get_settings( 'sitemap.items_per_page', 100 ) );
		$generator   = new \RankMath\Sitemap\Generator();

		foreach ( $generator->providers as $provider ) {
			if ( ! $provider->handles_type( $type ) ) {
				continue;
			}

			if ( $provider instanceof \RankMath\Sitemap\Providers\Post_Type ) {
				return self::probe_post_type_urlset( $provider, $type, $page, $max_entries );
			}

			if ( $provider instanceof \RankMath\Sitemap\Providers\Taxonomy ) {
				return self::probe_taxonomy_urlset( $type, $page, $max_entries );
			}

			if ( $provider instanceof \RankMath\Sitemap\Providers\Author ) {
				return self::probe_author_urlset( $provider, $page, $max_entries );
			}

			break;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Rank Math hook name.
		$content = apply_filters( "rank_math/sitemap/{$type}_content", '' );

		return is_string( $content ) && '' !== $content;
	}

	/**
	 * @param \RankMath\Sitemap\Providers\Post_Type $provider Rank Math post type provider.
	 */
	private static function probe_post_type_urlset(
		\RankMath\Sitemap\Providers\Post_Type $provider,
		string $type,
		int $page,
		int $max_entries
	): bool {
		$get_count = \Closure::bind(
			function ( string $post_type ) {
				return $this->get_post_type_count( $post_type );
			},
			$provider,
			\RankMath\Sitemap\Providers\Post_Type::class
		);

		if ( ! $get_count instanceof \Closure ) {
			return false;
		}

		$count  = (int) $get_count( $type );
		$offset = ( $page - 1 ) * $max_entries;

		if ( $count > $offset ) {
			return true;
		}

		if ( 1 !== $page ) {
			return false;
		}

		$get_first_links = \Closure::bind(
			function ( string $post_type ) {
				return $this->get_first_links( $post_type );
			},
			$provider,
			\RankMath\Sitemap\Providers\Post_Type::class
		);

		if ( ! $get_first_links instanceof \Closure ) {
			return false;
		}

		$first_links = $get_first_links( $type );

		return is_array( $first_links ) && ! empty( $first_links );
	}

	private static function probe_taxonomy_urlset( string $type, int $page, int $max_entries ): bool {
		$taxonomy = get_taxonomy( $type );

		if ( ! $taxonomy ) {
			return false;
		}

		$offset     = $page > 1 ? ( ( $page - 1 ) * $max_entries ) : 0;
		$hide_empty = ! \RankMath\Helper::get_settings( 'sitemap.tax_' . $type . '_include_empty' );

		$args = [
			'taxonomy'               => $type,
			'fields'                 => 'ids',
			'orderby'                => 'term_order',
			'hide_empty'             => $hide_empty,
			'offset'                 => $offset,
			'number'                 => 1,
			'exclude'                => wp_parse_id_list( \RankMath\Helper::get_settings( 'sitemap.exclude_terms' ) ),
			'hierarchical'           => false,
			'update_term_meta_cache' => false,
			'meta_query'             => [
				'relation' => 'OR',
				[
					'key'     => 'rank_math_robots',
					'value'   => 'noindex',
					'compare' => 'NOT LIKE',
				],
				[
					'key'     => 'rank_math_robots',
					'compare' => 'NOT EXISTS',
				],
			],
		];

		/**
		 * Rank Math and this plugin adjust term queries via get_terms_args during urlset generation.
		 *
		 * @param array       $args       Term query arguments.
		 * @param string|null $taxonomies Taxonomy name when a single taxonomy is queried.
		 */
		$args = apply_filters( 'get_terms_args', $args, $type );

		$terms = get_terms( $args );

		return ! is_wp_error( $terms ) && ! empty( $terms );
	}

	/**
	 * @param \RankMath\Sitemap\Providers\Author $provider Rank Math author provider.
	 */
	private static function probe_author_urlset(
		\RankMath\Sitemap\Providers\Author $provider,
		int $page,
		int $max_entries
	): bool {
		$get_users = \Closure::bind(
			function ( array $args ) {
				return $this->get_users( $args );
			},
			$provider,
			\RankMath\Sitemap\Providers\Author::class
		);

		if ( ! $get_users instanceof \Closure ) {
			return false;
		}

		$users = $get_users(
			[
				'offset' => max( 0, ( $page - 1 ) * $max_entries ),
				'number' => 1,
				'fields' => 'ID',
			]
		);

		return is_array( $users ) && ! empty( $users );
	}

	/**
	 * Builds the transient name for an availability probe result.
	 *
	 * @param string $type Sitemap type slug.
	 * @param int    $page Urlset page number.
	 * @param string $lang Language slug.
	 * @return string
	 */
	private static function get_transient_name( string $type, int $page, string $lang ): string {
		$lang = sanitize_key( $lang );
		$type = sanitize_key( $type );

		return self::TRANSIENT_PREFIX
			. get_current_blog_id() . '_'
			. Transient_Versions::get_suffix( Transient_Versions::AVAIL_OPTION, $type, $lang ) . '_'
			. $lang . '_'
			. $type . '_'
			. max( 1, $page );
	}
}
