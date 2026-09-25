<?php

namespace RankMathSitemapsPolylang;

use Closure;
use PLL_Language;
use RankMath\Helper as Rank_Math_Helper;
use RankMath\Sitemap\Generator;
use RankMath\Sitemap\Providers\Author as Author_Provider;
use RankMath\Sitemap\Providers\Post_Type as Post_Type_Provider;
use RankMath\Sitemap\Providers\Taxonomy as Taxonomy_Provider;

/**
 * Adds one sitemap index entry per active language after an availability check.
 *
 * Skips entries when the urlset would be empty for that language.
 */
final class Index_Expander {

	/**
	 * Buffered index entries for non-default languages.
	 *
	 * @var array<int, array{loc: string, lastmod: string|null}>
	 */
	private array $buffer = [];

	private Url_Helper $urls;

	private Sitemap_Cache $cache;

	public function __construct( Url_Helper $urls, Sitemap_Cache $cache ) {
		$this->urls  = $urls;
		$this->cache = $cache;
	}

	public function register_hooks(): void {
		if ( ! $this->urls->is_multilingual() ) {
			return;
		}

		add_filter( 'rank_math/sitemap/index/entry', [ $this, 'filter_index_entry' ], 10, 3 );
		add_filter( 'rank_math/sitemap/index', [ $this, 'append_buffered_entries' ], 10 );
	}

	/**
	 * Whether a sitemap page has at least one URL for the given language.
	 */
	public function has_urlset_for_language( string $type, int $page, string $lang ): bool {
		if ( ! $this->urls->is_active_language( $lang ) ) {
			return false;
		}

		$cached = $this->cache->get_availability( $type, $page, $lang );

		if ( null !== $cached ) {
			return $cached;
		}

		$language = PLL()->model->get_language( $lang );

		if ( ! $language instanceof PLL_Language ) {
			return false;
		}

		$previous_curlang   = PLL()->curlang;
		$previous_lang_qv   = get_query_var( Router::QUERY_VAR );
		$previous_sitemap   = get_query_var( 'sitemap' );
		$previous_sitemap_n = get_query_var( 'sitemap_n' );

		$this->urls->begin_urlset_probe();

		$has_content = false;

		try {
			PLL()->curlang = $language;
			set_query_var( Router::QUERY_VAR, $lang );
			set_query_var( 'sitemap', $type );
			set_query_var( 'sitemap_n', $page > 1 ? (string) $page : '' );

			$has_content = $this->probe_urlset( $type, $page );
		} finally {
			$this->urls->end_urlset_probe();

			PLL()->curlang = $previous_curlang;
			set_query_var( Router::QUERY_VAR, $previous_lang_qv );
			set_query_var( 'sitemap', $previous_sitemap );
			set_query_var( 'sitemap_n', $previous_sitemap_n );
		}

		$this->cache->set_availability( $type, $page, $lang, $has_content );

		return $has_content;
	}

	/**
	 * @param array|false $entry          Index entry.
	 * @param string      $object_type    Provider object type.
	 * @param string      $object_subtype Provider object subtype.
	 * @return array|false
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Rank Math filter signature.
	public function filter_index_entry( $entry, string $object_type, string $object_subtype ) {
		if ( ! is_array( $entry ) || empty( $entry['loc'] ) ) {
			return $entry;
		}

		$parsed = $this->urls->parse_sitemap_filename( (string) $entry['loc'] );

		if ( null === $parsed ) {
			return $entry;
		}

		$default     = (string) pll_default_language();
		$has_default = $this->has_urlset_for_language(
			$parsed['type'],
			$parsed['page'],
			$default
		);
		$lastmod     = isset( $entry['lastmod'] ) ? (string) $entry['lastmod'] : null;

		foreach ( $this->urls->get_active_language_slugs() as $slug ) {
			if ( $slug === $default ) {
				continue;
			}

			if ( ! $this->has_urlset_for_language( $parsed['type'], $parsed['page'], $slug ) ) {
				continue;
			}

			$this->buffer[] = [
				'loc'     => $this->urls->get_sitemap_loc( $parsed['type'], $parsed['page'], $slug ),
				'lastmod' => $lastmod,
			];
		}

		if ( ! $has_default ) {
			return false;
		}

		$entry['loc'] = $this->urls->get_sitemap_loc( $parsed['type'], $parsed['page'], $default );

		return $entry;
	}

	/**
	 * Append buffered language variants to the sitemap index XML.
	 */
	public function append_buffered_entries( string $xml ): string {
		if ( empty( $this->buffer ) ) {
			return $xml;
		}

		foreach ( $this->buffer as $entry ) {
			$xml .= $this->format_index_entry( $entry['loc'], $entry['lastmod'] );
		}

		$this->buffer = [];

		return $xml;
	}

	/**
	 * Formats one `<sitemap>` index entry as XML.
	 *
	 * Example `$loc`: `https://example.com/en/post-sitemap.xml`
	 *
	 * @param string      $loc     Absolute sitemap URL.
	 * @param string|null $lastmod Optional last modification date (ISO-8601-ish string from Rank Math).
	 */
	private function format_index_entry( string $loc, ?string $lastmod ): string {
		$output  = "\t<sitemap>\n";
		$output .= "\t\t<loc>" . esc_url( $loc ) . "</loc>\n";

		if ( ! empty( $lastmod ) ) {
			$output .= "\t\t<lastmod>" . esc_html( $lastmod ) . "</lastmod>\n";
		}

		$output .= "\t</sitemap>\n";

		return $output;
	}

	/**
	 * Lightweight existence check without building full urlsets or Rank Math redirects.
	 *
	 * Dispatches to the Rank Math provider that handles `$type` (post type, taxonomy,
	 * or author). Falls back to the `rank_math/sitemap/{$type}_content` filter.
	 *
	 * @param string $type Sitemap type slug (e.g. `post`, `category`, `author`).
	 * @param int    $page Urlset page number (1-based).
	 */
	private function probe_urlset( string $type, int $page ): bool {
		$max_entries = absint( Rank_Math_Helper::get_settings( 'sitemap.items_per_page', 100 ) );
		$generator   = new Generator();

		foreach ( $generator->providers as $provider ) {
			if ( ! $provider->handles_type( $type ) ) {
				continue;
			}

			if ( $provider instanceof Post_Type_Provider ) {
				return $this->probe_post_type_urlset( $provider, $type, $page, $max_entries );
			}

			if ( $provider instanceof Taxonomy_Provider ) {
				return $this->probe_taxonomy_urlset( $type, $page, $max_entries );
			}

			if ( $provider instanceof Author_Provider ) {
				return $this->probe_author_urlset( $provider, $page, $max_entries );
			}

			break;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Rank Math hook name.
		$content = apply_filters( "rank_math/sitemap/{$type}_content", '' );

		return is_string( $content ) && '' !== $content;
	}

	/**
	 * Whether a post-type urlset page would contain at least one URL.
	 *
	 * Uses Rank Math's private count / first-links helpers via Closure::bind.
	 *
	 * @param Post_Type_Provider $provider    Rank Math post-type sitemap provider.
	 * @param string             $type        Post type slug.
	 * @param int                $page        Urlset page number (1-based).
	 * @param int                $max_entries Items per sitemap page.
	 */
	private function probe_post_type_urlset(
		Post_Type_Provider $provider,
		string $type,
		int $page,
		int $max_entries
	): bool {
		$get_count = Closure::bind(
			function ( string $post_type ) {
				return $this->get_post_type_count( $post_type );
			},
			$provider,
			Post_Type_Provider::class
		);

		if ( ! $get_count instanceof Closure ) {
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

		$get_first_links = Closure::bind(
			function ( string $post_type ) {
				return $this->get_first_links( $post_type );
			},
			$provider,
			Post_Type_Provider::class
		);

		if ( ! $get_first_links instanceof Closure ) {
			return false;
		}

		$first_links = $get_first_links( $type );

		return is_array( $first_links ) && ! empty( $first_links );
	}

	/**
	 * Whether a taxonomy urlset page would contain at least one term URL.
	 *
	 * Runs a minimal `get_terms` query (one ID) with Rank Math noindex exclusions.
	 *
	 * @param string $type        Taxonomy slug.
	 * @param int    $page        Urlset page number (1-based).
	 * @param int    $max_entries Items per sitemap page.
	 */
	private function probe_taxonomy_urlset( string $type, int $page, int $max_entries ): bool {
		$taxonomy = get_taxonomy( $type );

		if ( ! $taxonomy ) {
			return false;
		}

		$offset     = $page > 1 ? ( ( $page - 1 ) * $max_entries ) : 0;
		$hide_empty = ! Rank_Math_Helper::get_settings( 'sitemap.tax_' . $type . '_include_empty' );

		$args = [
			'taxonomy'               => $type,
			'fields'                 => 'ids',
			'orderby'                => 'term_order',
			'hide_empty'             => $hide_empty,
			'offset'                 => $offset,
			'number'                 => 1,
			'exclude'                => wp_parse_id_list( Rank_Math_Helper::get_settings( 'sitemap.exclude_terms' ) ),
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
		 * @param array       $args       Term query arguments.
		 * @param string|null $taxonomies Taxonomy name when a single taxonomy is queried.
		 */
		$args = apply_filters( 'get_terms_args', $args, $type );

		$terms = get_terms( $args );

		return ! is_wp_error( $terms ) && ! empty( $terms );
	}

	/**
	 * Whether an author urlset page would contain at least one author URL.
	 *
	 * @param Author_Provider $provider    Rank Math author sitemap provider.
	 * @param int             $page        Urlset page number (1-based).
	 * @param int             $max_entries Items per sitemap page.
	 */
	private function probe_author_urlset(
		Author_Provider $provider,
		int $page,
		int $max_entries
	): bool {
		$get_users = Closure::bind(
			function ( array $args ) {
				return $this->get_users( $args );
			},
			$provider,
			Author_Provider::class
		);

		if ( ! $get_users instanceof Closure ) {
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
}
