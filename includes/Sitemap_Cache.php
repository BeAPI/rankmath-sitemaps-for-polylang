<?php

namespace RankMathSitemapsPolylang;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and invalidates per-language urlset XML.
 *
 * Uses the uploads directory with transients as a fallback.
 */
final class Sitemap_Cache {

	private const TRANSIENT_PREFIX = 'rmsp_sitemap_';

	private const CACHE_TTL = DAY_IN_SECONDS * 100;

	private const DEBUG_HEADER = 'X-RMSP-Cache';

	private bool $capturing = false;

	/**
	 * Register cache serving hooks (frontend sitemap requests only).
	 */
	public function register_serving_hooks(): void {
		add_action( 'parse_query', [ $this, 'maybe_serve_cached_urlset' ], 0 );
		add_action( 'parse_query', [ $this, 'maybe_start_capture' ], 0 );
		add_filter( 'rank_math/sitemap/enable_caching', [ $this, 'disable_rank_math_urlset_cache' ] );
	}

	/**
	 * Register invalidation hooks (admin + frontend).
	 *
	 * Rank Math Cache_Watcher skips invalidation when its own cache is disabled
	 * (see beapi-rankmath.php), so we mirror its triggers here.
	 */
	public static function register_invalidation_hooks(): void {
		add_action( 'save_post', [ self::class, 'invalidate_post' ], 10, 1 );
		add_action( 'deleted_post', [ self::class, 'invalidate_post' ], 10, 1 );
		add_action( 'trashed_post', [ self::class, 'invalidate_post' ], 10, 1 );
		add_action( 'untrashed_post', [ self::class, 'invalidate_post' ], 10, 1 );

		add_action( 'edited_terms', [ self::class, 'invalidate_term' ], 10, 2 );
		add_action( 'clean_term_cache', [ self::class, 'invalidate_term' ], 10, 2 );
		add_action( 'clean_object_term_cache', [ self::class, 'invalidate_post_term_relationships' ], 10, 2 );
		add_action( 'deleted_term_relationships', [ self::class, 'invalidate_term_relationships' ], 10, 3 );

		add_action( 'delete_user', [ self::class, 'invalidate_author' ] );
		add_action( 'user_register', [ self::class, 'invalidate_author' ] );
		add_action( 'profile_update', [ self::class, 'invalidate_author' ] );

		add_action( 'rank_math/sitemap/invalidate_object_type', [ self::class, 'invalidate_object_type' ], 10, 2 );

		foreach (
			[
				'home',
				'permalink_structure',
				'rank_math_modules',
				'rank-math-options-titles',
				'rank-math-options-general',
				'rank-math-options-sitemap',
				'date_format',
			] as $option
		) {
			add_action( 'update_option_' . $option, [ self::class, 'clear_all' ] );
		}
	}

	/**
	 * @param int $post_id Post ID.
	 */
	public static function invalidate_post( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'auto-draft' === $post->post_status ) {
			return;
		}

		$lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post_id ) : '';

		if ( is_string( $lang ) && '' !== $lang ) {
			self::clear_type_language( $post->post_type, $lang );
			self::clear_type_language( self::get_author_sitemap_type(), $lang );
			return;
		}

		self::clear_type( $post->post_type );
		self::clear_type( self::get_author_sitemap_type() );
	}

	/**
	 * @param int|int[] $term_ids Term ID or list of term IDs.
	 * @param string    $taxonomy Taxonomy slug.
	 */
	public static function invalidate_term( $term_ids, string $taxonomy ): void {
		if ( false === \RankMath\Helper::get_settings( 'sitemap.tax_' . $taxonomy . '_sitemap' ) ) {
			return;
		}

		foreach ( array_filter( array_map( 'intval', (array) $term_ids ) ) as $term_id ) {
			$lang = function_exists( 'pll_get_term_language' ) ? pll_get_term_language( $term_id ) : '';

			if ( is_string( $lang ) && '' !== $lang ) {
				self::clear_type_language( $taxonomy, $lang );
				continue;
			}

			self::clear_type( $taxonomy );
		}
	}

	/**
	 * @param int|int[] $object_ids  Post IDs.
	 * @param string    $object_type Post type slug.
	 */
	public static function invalidate_post_term_relationships( $object_ids, string $object_type ): void {
		foreach ( array_filter( array_map( 'intval', (array) $object_ids ) ) as $post_id ) {
			$lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post_id ) : '';

			if ( ! is_string( $lang ) || '' === $lang ) {
				continue;
			}

			foreach ( get_object_taxonomies( $object_type ) as $taxonomy ) {
				if ( false === \RankMath\Helper::get_settings( 'sitemap.tax_' . $taxonomy . '_sitemap' ) ) {
					continue;
				}

				self::clear_type_language( $taxonomy, $lang );
			}
		}
	}

	/**
	 * @param int        $object_id Post ID.
	 * @param int|int[]  $tt_ids    Term taxonomy IDs.
	 * @param string     $taxonomy  Taxonomy slug.
	 */
	public static function invalidate_term_relationships( int $object_id, $tt_ids, string $taxonomy ): void {
		if ( false === \RankMath\Helper::get_settings( 'sitemap.tax_' . $taxonomy . '_sitemap' ) ) {
			return;
		}

		$lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $object_id ) : '';

		if ( is_string( $lang ) && '' !== $lang ) {
			self::clear_type_language( $taxonomy, $lang );
			return;
		}

		self::clear_type( $taxonomy );
	}

	/**
	 * @param int $user_id User ID.
	 */
	public static function invalidate_author( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );

		if ( $user && ! is_null( $user->roles ) && ! in_array( 'subscriber', $user->roles, true ) ) {
			self::clear_type( self::get_author_sitemap_type() );
		}
	}

	/**
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 */
	public static function invalidate_object_type( string $object_type, int $object_id ): void {
		if ( 'post' === $object_type ) {
			self::invalidate_post( $object_id );
			return;
		}

		if ( 'user' === $object_type ) {
			self::invalidate_author( $object_id );
			return;
		}

		if ( 'term' === $object_type ) {
			$term = get_term( $object_id );

			if ( $term instanceof \WP_Term ) {
				self::invalidate_term( $object_id, $term->taxonomy );
			}
		}
	}

	/**
	 * Serve a cached urlset and exit before Rank Math generates XML.
	 *
	 * @param \WP_Query $query Main query.
	 */
	public function maybe_serve_cached_urlset( \WP_Query $query ): void {
		if ( ! $this->should_handle_urlset( $query ) ) {
			return;
		}

		$cached = $this->get(
			Url_Helper::get_sitemap_type(),
			Url_Helper::get_sitemap_page(),
			Url_Helper::get_target_language()
		);

		if ( ! is_string( $cached ) || '' === $cached ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		$this->send_debug_cache_header( 'HIT' );
		echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		exit;
	}

	/**
	 * Start output buffering on cache miss and persist XML when Rank Math flushes it.
	 *
	 * @param \WP_Query $query Main query.
	 */
	public function maybe_start_capture( \WP_Query $query ): void {
		if ( ! $this->should_handle_urlset( $query ) || $this->capturing ) {
			return;
		}

		$this->capturing = true;
		$this->send_debug_cache_header( 'MISS' );

		ob_start(
			function ( string $buffer ): string {
				if ( '' !== $buffer && false !== strpos( $buffer, '<urlset' ) ) {
					$this->set(
						Url_Helper::get_sitemap_type(),
						Url_Helper::get_sitemap_page(),
						Url_Helper::get_target_language(),
						$buffer
					);
				}

				return $buffer;
			}
		);
	}

	/**
	 * Disable Rank Math file/db cache for multilingual urlsets (key has no language).
	 *
	 * @param bool $enabled Whether Rank Math caching is enabled.
	 */
	public function disable_rank_math_urlset_cache( bool $enabled ): bool {
		if ( ! Url_Helper::is_sitemap_request() || Url_Helper::is_index_request() ) {
			return $enabled;
		}

		return false;
	}

	public static function clear_all(): void {
		self::clear_transients();
		self::clear_files();
		Sitemap_Availability::clear_cache();
	}

	/**
	 * Purge every cached urlset for one language.
	 */
	public static function clear_language( string $lang ): void {
		$lang = sanitize_key( $lang );

		if ( '' === $lang ) {
			return;
		}

		$dir = self::get_cache_directory() . $lang . '/';

		if ( is_dir( $dir ) ) {
			$files = glob( $dir . '*.xml' );

			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						wp_delete_file( $file );
					}
				}
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			@rmdir( $dir );
		}

		self::clear_transients_for_language( $lang );
		Sitemap_Availability::clear_language_cache( $lang );
	}

	/**
	 * Purge every cached page for a sitemap type (all languages).
	 */
	public static function clear_type( string $type ): void {
		foreach ( Url_Helper::get_active_language_slugs() as $lang ) {
			self::clear_type_language( $type, $lang );
		}
	}

	/**
	 * Purge every cached page for a sitemap type in one language.
	 */
	public static function clear_type_language( string $type, string $lang ): void {
		$type = sanitize_key( $type );
		$lang = sanitize_key( $lang );

		if ( '' === $type || '' === $lang ) {
			return;
		}

		$pattern = self::get_cache_directory() . $lang . '/' . $type . '-*.xml';
		$files   = glob( $pattern );

		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}

		self::clear_transients_for_type_language( $type, $lang );
		Sitemap_Availability::clear_type_language_cache( $type, $lang );
	}

	/**
	 * Purge urlset cache when a Polylang language term is edited (deactivation, slug change, etc.).
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function maybe_clear_on_language_term_edit( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( 'language' !== $taxonomy || ! function_exists( 'PLL' ) ) {
			return;
		}

		$lang = PLL()->model->get_language( $term_id );

		if ( ! $lang instanceof \PLL_Language ) {
			return;
		}

		self::clear_language( $lang->slug );
		Router::register_rewrites();
		flush_rewrite_rules( false );
	}

	/**
	 * @param int    $term_id  Deleted term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function maybe_clear_on_language_delete( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( 'language' === $taxonomy ) {
			self::clear_all();

			if ( class_exists( '\RankMath\Sitemap\Cache_Watcher' ) ) {
				\RankMath\Sitemap\Cache_Watcher::clear();
			}
		}
	}

	/**
	 * Expose cache status in DevTools when WP_DEBUG is enabled.
	 *
	 * @param 'HIT'|'MISS' $status Cache lookup result.
	 */
	private function send_debug_cache_header( string $status ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		header( self::DEBUG_HEADER . ': ' . $status );
	}

	/**
	 * Determines whether the cache layer should handle the current urlset request.
	 *
	 * @param \WP_Query $query Main query.
	 * @return bool
	 */
	private function should_handle_urlset( \WP_Query $query ): bool {
		if (
			! $query->is_main_query()
			|| ! Url_Helper::is_multilingual()
			|| ! Url_Helper::is_sitemap_request()
			|| Url_Helper::is_index_request()
		) {
			return false;
		}

		$lang = get_query_var( Router::QUERY_VAR );

		if ( is_string( $lang ) && '' !== $lang && ! Url_Helper::is_active_language( $lang ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Reads cached urlset XML from disk or a transient.
	 *
	 * @param string $type Sitemap type slug.
	 * @param int    $page Urlset page number.
	 * @param string $lang Language slug.
	 * @return string|false Cached XML or false on miss.
	 */
	private function get( string $type, int $page, string $lang ) {
		$file = self::get_cache_filepath( $type, $page, $lang );

		if ( file_exists( $file ) && is_readable( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$contents = file_get_contents( $file );

			return false !== $contents ? $contents : false;
		}

		$transient = get_transient( self::get_transient_name( $type, $page, $lang ) );

		return is_string( $transient ) ? $transient : false;
	}

	/**
	 * Persists urlset XML to disk, or to a transient when the directory is not writable.
	 *
	 * @param string $type Sitemap type slug.
	 * @param int    $page Urlset page number.
	 * @param string $lang Language slug.
	 * @param string $xml  Generated urlset XML.
	 */
	private function set( string $type, int $page, string $lang, string $xml ): void {
		$file = self::get_cache_filepath( $type, $page, $lang );
		$dir  = dirname( $file );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		if ( wp_mkdir_p( $dir ) && is_writable( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false !== file_put_contents( $file, $xml ) ) {
				return;
			}
		}

		set_transient( self::get_transient_name( $type, $page, $lang ), $xml, self::CACHE_TTL );
	}

	/**
	 * Returns the absolute path to a cached urlset file.
	 *
	 * @param string $type Sitemap type slug.
	 * @param int    $page Urlset page number.
	 * @param string $lang Language slug.
	 * @return string
	 */
	private static function get_cache_filepath( string $type, int $page, string $lang ): string {
		return self::get_cache_directory()
			. sanitize_key( $lang ) . '/'
			. sanitize_key( $type ) . '-'
			. max( 1, $page ) . '.xml';
	}

	/**
	 * Builds the transient name for a cached urlset.
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
			. Transient_Versions::get_suffix( Transient_Versions::SITEMAP_OPTION, $type, $lang ) . '_'
			. $lang . '_'
			. $type . '_'
			. max( 1, $page );
	}

	/**
	 * Returns the Rank Math author sitemap type slug.
	 *
	 * @return string
	 */
	private static function get_author_sitemap_type(): string {
		return \RankMath\Sitemap\Router::get_sitemap_slug( 'author' );
	}

	/**
	 * Returns the base directory for cached urlset files under uploads.
	 *
	 * @return string
	 */
	private static function get_cache_directory(): string {
		$upload = wp_upload_dir();

		return trailingslashit( $upload['basedir'] ) . 'rank-math-sitemaps-polylang/';
	}

	/**
	 * Invalidates all urlset transients for one language via version bump.
	 *
	 * @param string $lang Language slug.
	 */
	private static function clear_transients_for_language( string $lang ): void {
		Transient_Versions::bump_language( Transient_Versions::SITEMAP_OPTION, $lang );
	}

	/**
	 * Invalidates urlset transients for one sitemap type and language.
	 *
	 * @param string $type Sitemap type slug.
	 * @param string $lang Language slug.
	 */
	private static function clear_transients_for_type_language( string $type, string $lang ): void {
		Transient_Versions::bump_type_language( Transient_Versions::SITEMAP_OPTION, $type, $lang );
	}

	/**
	 * Invalidates all urlset transients for the current site.
	 */
	private static function clear_transients(): void {
		Transient_Versions::bump_all( Transient_Versions::SITEMAP_OPTION );
	}

	/**
	 * Deletes all cached urlset files under the plugin cache directory.
	 */
	private static function clear_files(): void {
		$dir = self::get_cache_directory();

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				rmdir( $file->getPathname() );
				continue;
			}

			wp_delete_file( $file->getPathname() );
		}
	}
}
