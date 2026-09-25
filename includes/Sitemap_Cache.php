<?php

namespace RankMathSitemapsPolylang;

use RankMath\Helper as Rank_Math_Helper;
use RankMath\Sitemap\Cache_Watcher;
use RankMath\Sitemap\Router as Rank_Math_Router;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Stores and invalidates per-language urlset XML and index availability flags.
 *
 * Uses the uploads directory with transients as a fallback.
 */
final class Sitemap_Cache {

	public const NAMESPACE_AVAIL = 'avail';

	public const NAMESPACE_SITEMAP = 'sitemap';

	private const AVAIL_OPTION = 'rmsp_avail_versions';

	private const SITEMAP_OPTION = 'rmsp_sitemap_versions';

	private const TRANSIENT_PREFIX_AVAIL = 'rmsp_avail_';

	private const TRANSIENT_PREFIX_SITEMAP = 'rmsp_sitemap_';

	private const AVAIL_TTL = \HOUR_IN_SECONDS;

	private const SITEMAP_TTL = \DAY_IN_SECONDS * 100;

	private const DEBUG_HEADER = 'X-RMSP-Cache';

	private Url_Helper $urls;

	private bool $capturing = false;

	public function __construct( Url_Helper $urls ) {
		$this->urls = $urls;
	}

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
	 */
	public function register_invalidation_hooks(): void {
		add_action( 'save_post', [ $this, 'invalidate_post' ], 10, 1 );
		add_action( 'deleted_post', [ $this, 'invalidate_post' ], 10, 1 );
		add_action( 'trashed_post', [ $this, 'invalidate_post' ], 10, 1 );
		add_action( 'untrashed_post', [ $this, 'invalidate_post' ], 10, 1 );

		add_action( 'edited_terms', [ $this, 'invalidate_term' ], 10, 2 );
		add_action( 'clean_term_cache', [ $this, 'invalidate_term' ], 10, 2 );
		add_action( 'clean_object_term_cache', [ $this, 'invalidate_post_term_relationships' ], 10, 2 );
		add_action( 'deleted_term_relationships', [ $this, 'invalidate_term_relationships' ], 10, 3 );

		add_action( 'delete_user', [ $this, 'invalidate_author' ] );
		add_action( 'user_register', [ $this, 'invalidate_author' ] );
		add_action( 'profile_update', [ $this, 'invalidate_author' ] );

		add_action( 'rank_math/sitemap/invalidate_object_type', [ $this, 'invalidate_object_type' ], 10, 2 );

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
			add_action( 'update_option_' . $option, [ $this, 'clear_all' ] );
		}
	}

	/**
	 * Builds a full transient key for availability or urlset cache.
	 *
	 * Example (sitemap namespace, type `post`, lang `en`, page 1, versions at 1):
	 * - `rmsp_sitemap_g1_l1_t1_en_post_1`
	 *
	 * Availability namespace uses the `rmsp_avail_` prefix instead.
	 *
	 * @param string $cache_namespace self::NAMESPACE_AVAIL or self::NAMESPACE_SITEMAP.
	 * @param string $type            Sitemap type slug.
	 * @param string $lang            Language slug.
	 * @param int    $page            Urlset page number.
	 */
	public function transient_key( string $cache_namespace, string $type, string $lang, int $page ): string {
		$lang = sanitize_key( $lang );
		$type = sanitize_key( $type );
		$page = max( 1, $page );

		if ( self::NAMESPACE_AVAIL === $cache_namespace ) {
			$prefix = self::TRANSIENT_PREFIX_AVAIL;
			$option = self::AVAIL_OPTION;
		} else {
			$prefix = self::TRANSIENT_PREFIX_SITEMAP;
			$option = self::SITEMAP_OPTION;
		}

		return $prefix
			. $this->build_version_token( $option, $type, $lang ) . '_'
			. $lang . '_'
			. $type . '_'
			. $page;
	}

	/**
	 * Reads a cached availability probe result.
	 */
	public function get_availability( string $type, int $page, string $lang ): ?bool {
		$cached = get_transient( $this->transient_key( self::NAMESPACE_AVAIL, $type, $lang, $page ) );

		if ( false === $cached ) {
			return null;
		}

		return (bool) $cached;
	}

	/**
	 * Persists an availability probe result.
	 */
	public function set_availability( string $type, int $page, string $lang, bool $has_content ): void {
		set_transient(
			$this->transient_key( self::NAMESPACE_AVAIL, $type, $lang, $page ),
			$has_content ? 1 : 0,
			self::AVAIL_TTL
		);
	}

	/**
	 * Remove version options on uninstall.
	 */
	public function delete_version_options(): void {
		delete_option( self::AVAIL_OPTION );
		delete_option( self::SITEMAP_OPTION );
	}

	/**
	 * Purge all cached urlsets and availability probes.
	 */
	public function clear_all(): void {
		$this->bump_all_versions( self::SITEMAP_OPTION );
		$this->bump_all_versions( self::AVAIL_OPTION );
		$this->clear_files();
	}

	/**
	 * Purge every cached urlset for one language.
	 */
	public function clear_language( string $lang ): void {
		$lang = sanitize_key( $lang );

		if ( '' === $lang ) {
			return;
		}

		$dir = $this->get_cache_directory() . $lang . '/';

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

		$this->bump_language_versions( self::SITEMAP_OPTION, $lang );
		$this->bump_language_versions( self::AVAIL_OPTION, $lang );
	}

	/**
	 * Purge every cached page for a sitemap type (all languages).
	 */
	public function clear_type( string $type ): void {
		foreach ( $this->urls->get_active_language_slugs() as $lang ) {
			$this->clear_type_language( $type, $lang );
		}
	}

	/**
	 * Purge every cached page for a sitemap type in one language.
	 */
	public function clear_type_language( string $type, string $lang ): void {
		$type = sanitize_key( $type );
		$lang = sanitize_key( $lang );

		if ( '' === $type || '' === $lang ) {
			return;
		}

		$pattern = $this->get_cache_directory() . $lang . '/' . $type . '-*.xml';
		$files   = glob( $pattern );

		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}

		$this->bump_type_language_versions( self::SITEMAP_OPTION, $type, $lang );
		$this->bump_type_language_versions( self::AVAIL_OPTION, $type, $lang );
	}

	/**
	 * Purge cache for corresponding urlset when a post is updated.
	 *
	 * Also purges the author sitemap if the post is authored by a user.
	 *
	 * @param int $post_id Post ID.
	 */
	public function invalidate_post( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'auto-draft' === $post->post_status ) {
			return;
		}

		$lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post_id ) : '';

		if ( is_string( $lang ) && '' !== $lang ) {
			$this->clear_type_language( $post->post_type, $lang );
			$this->clear_type_language( $this->get_author_sitemap_type(), $lang );
			return;
		}

		$this->clear_type( $post->post_type );
		$this->clear_type( $this->get_author_sitemap_type() );
	}

	/**
	 * Purge cache for corresponding urlset when a term is updated.
	 *
	 * @param int|int[] $term_ids Term ID or list of term IDs.
	 * @param string    $taxonomy Taxonomy slug.
	 */
	public function invalidate_term( $term_ids, string $taxonomy ): void {
		if ( false === Rank_Math_Helper::get_settings( 'sitemap.tax_' . $taxonomy . '_sitemap' ) ) {
			return;
		}

		foreach ( array_filter( array_map( 'intval', (array) $term_ids ) ) as $term_id ) {
			$lang = function_exists( 'pll_get_term_language' ) ? pll_get_term_language( $term_id ) : '';

			if ( is_string( $lang ) && '' !== $lang ) {
				$this->clear_type_language( $taxonomy, $lang );
				continue;
			}

			$this->clear_type( $taxonomy );
		}
	}

	/**
	 * Purge cache for corresponding urlset when a post term relationship is updated.
	 *
	 * @param int|int[] $object_ids  Post IDs.
	 * @param string    $object_type Post type slug.
	 */
	public function invalidate_post_term_relationships( $object_ids, string $object_type ): void {
		foreach ( array_filter( array_map( 'intval', (array) $object_ids ) ) as $post_id ) {
			$lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post_id ) : '';

			if ( ! is_string( $lang ) || '' === $lang ) {
				continue;
			}

			foreach ( get_object_taxonomies( $object_type ) as $taxonomy ) {
				if ( false === Rank_Math_Helper::get_settings( 'sitemap.tax_' . $taxonomy . '_sitemap' ) ) {
					continue;
				}

				$this->clear_type_language( $taxonomy, $lang );
			}
		}
	}

	/**
	 * Purge cache for corresponding urlset when a term relationship is updated.
	 * @param int       $object_id Post ID.
	 * @param int|int[] $tt_ids    Term taxonomy IDs.
	 * @param string    $taxonomy  Taxonomy slug.
	 */
	public function invalidate_term_relationships( int $object_id, $tt_ids, string $taxonomy ): void {
		if ( false === Rank_Math_Helper::get_settings( 'sitemap.tax_' . $taxonomy . '_sitemap' ) ) {
			return;
		}

		$lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $object_id ) : '';

		if ( is_string( $lang ) && '' !== $lang ) {
			$this->clear_type_language( $taxonomy, $lang );
			return;
		}

		$this->clear_type( $taxonomy );
	}

	/**
	 * Purge cache for corresponding urlset when a user is updated.
	 *
	 * @param int $user_id User ID.
	 */
	public function invalidate_author( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );

		if ( $user && ! is_null( $user->roles ) && ! in_array( 'subscriber', $user->roles, true ) ) {
			$this->clear_type( $this->get_author_sitemap_type() );
		}
	}

	/**
	 * Purge cache for corresponding urlset when an object is updated.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 */
	public function invalidate_object_type( string $object_type, int $object_id ): void {
		if ( 'post' === $object_type ) {
			$this->invalidate_post( $object_id );
			return;
		}

		if ( 'user' === $object_type ) {
			$this->invalidate_author( $object_id );
			return;
		}

		if ( 'term' === $object_type ) {
			$term = get_term( $object_id );

			if ( $term instanceof WP_Term ) {
				$this->invalidate_term( $object_id, $term->taxonomy );
			}
		}
	}

	/**
	 * Clears all caches when a Polylang language term is deleted.
	 *
	 * @param int    $term_id  Deleted term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function clear_on_language_delete( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( 'language' !== $taxonomy ) {
			return;
		}

		$this->clear_all();

		if ( class_exists( Cache_Watcher::class ) ) {
			Cache_Watcher::clear();
		}
	}

	/**
	 * Serve a cached urlset and exit before Rank Math generates XML.
	 *
	 * @param WP_Query $query Main query.
	 */
	public function maybe_serve_cached_urlset( WP_Query $query ): void {
		if ( ! $this->should_handle_urlset( $query ) ) {
			return;
		}

		$cached = $this->get_urlset(
			$this->urls->get_sitemap_type(),
			$this->urls->get_sitemap_page(),
			$this->urls->get_target_language()
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
	 * @param WP_Query $query Main query.
	 */
	public function maybe_start_capture( WP_Query $query ): void {
		if ( ! $this->should_handle_urlset( $query ) || $this->capturing ) {
			return;
		}

		$this->capturing = true;
		$this->send_debug_cache_header( 'MISS' );

		ob_start(
			function ( string $buffer ): string {
				if ( '' !== $buffer && false !== strpos( $buffer, '<urlset' ) ) {
					$this->set_urlset(
						$this->urls->get_sitemap_type(),
						$this->urls->get_sitemap_page(),
						$this->urls->get_target_language(),
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
		if ( ! $this->urls->is_sitemap_request() || $this->urls->is_index_request() ) {
			return $enabled;
		}

		return false;
	}

	/**
	 * Sends an X-RMSP-Cache debug response header when WP_DEBUG is on.
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
	 * Whether this request is a multilingual per-language urlset we should cache.
	 *
	 * Skips the sitemap index, inactive-language requests, and non-sitemap queries.
	 *
	 * @param WP_Query $query Main query.
	 */
	private function should_handle_urlset( WP_Query $query ): bool {
		if (
			! $query->is_main_query()
			|| ! $this->urls->is_multilingual()
			|| ! $this->urls->is_sitemap_request()
			|| $this->urls->is_index_request()
		) {
			return false;
		}

		$lang = $this->urls->get_request_language_slug();

		if ( '' !== $lang && ! $this->urls->is_active_language( $lang ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Reads a cached urlset from disk, falling back to a transient.
	 *
	 * @param string $type Sitemap type slug.
	 * @param int    $page Urlset page number.
	 * @param string $lang Language slug.
	 * @return string|false Cached XML or false on miss.
	 */
	private function get_urlset( string $type, int $page, string $lang ) {
		$file = $this->get_cache_filepath( $type, $page, $lang );

		if ( file_exists( $file ) && is_readable( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$contents = file_get_contents( $file );

			return false !== $contents ? $contents : false;
		}

		$transient = get_transient( $this->transient_key( self::NAMESPACE_SITEMAP, $type, $lang, $page ) );

		return is_string( $transient ) ? $transient : false;
	}

	/**
	 * Persists urlset XML to a language file, or a transient if the file write fails.
	 *
	 * @param string $type Sitemap type slug.
	 * @param int    $page Urlset page number.
	 * @param string $lang Language slug.
	 * @param string $xml  Full urlset XML body.
	 */
	private function set_urlset( string $type, int $page, string $lang, string $xml ): void {
		$file = $this->get_cache_filepath( $type, $page, $lang );
		$dir  = dirname( $file );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		if ( wp_mkdir_p( $dir ) && is_writable( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false !== file_put_contents( $file, $xml ) ) {
				return;
			}
		}

		set_transient(
			$this->transient_key( self::NAMESPACE_SITEMAP, $type, $lang, $page ),
			$xml,
			self::SITEMAP_TTL
		);
	}

	/**
	 * Absolute filesystem path for a cached urlset file.
	 *
	 * Example (`basedir` = `/var/www/uploads`, type `post`, lang `en`, page 1):
	 * - `/var/www/uploads/rank-math-sitemaps-polylang/en/post-1.xml`
	 *
	 * Page 2 of category in French:
	 * - `…/rank-math-sitemaps-polylang/fr/category-2.xml`
	 *
	 * @param string $type Sitemap type slug.
	 * @param int    $page Urlset page number.
	 * @param string $lang Language slug.
	 */
	private function get_cache_filepath( string $type, int $page, string $lang ): string {
		return $this->get_cache_directory()
			. sanitize_key( $lang ) . '/'
			. sanitize_key( $type ) . '-'
			. max( 1, $page ) . '.xml';
	}

	/**
	 * Rank Math author sitemap type slug (usually `author`).
	 */
	private function get_author_sitemap_type(): string {
		return Rank_Math_Router::get_sitemap_slug( 'author' );
	}

	/**
	 * Root directory for on-disk urlset cache files.
	 *
	 * Example: `/var/www/wp-content/uploads/rank-math-sitemaps-polylang/`
	 */
	private function get_cache_directory(): string {
		$upload = wp_upload_dir();

		return trailingslashit( $upload['basedir'] ) . 'rank-math-sitemaps-polylang/';
	}

	/**
	 * Recursively deletes all files and folders under the cache directory.
	 */
	private function clear_files(): void {
		$dir = $this->get_cache_directory();

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
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

	/**
	 * Reads the version-bump state for availability or sitemap cache options.
	 *
	 * @param string $option self::AVAIL_OPTION or self::SITEMAP_OPTION.
	 * @return array{all?: int, lang?: array<string, int>, type_lang?: array<string, int>}
	 */
	private function get_versions( string $option ): array {
		$versions = get_option( $option, [] );

		return is_array( $versions ) ? $versions : [];
	}

	/**
	 * Persists version-bump state for availability or sitemap cache options.
	 *
	 * @param string                                                                     $option   self::AVAIL_OPTION or self::SITEMAP_OPTION.
	 * @param array{all?: int, lang?: array<string, int>, type_lang?: array<string, int>} $versions Version state.
	 */
	private function save_versions( string $option, array $versions ): void {
		update_option( $option, $versions, false );
	}

	/**
	 * Builds the version segment embedded in transient keys.
	 *
	 * Combines global, per-language, and per-type+language counters so a bump
	 * invalidates the right subset of keys without deleting each transient.
	 *
	 * Example (all=1, lang en=2, type_lang post|en=3):
	 * - `g1_l2_t3`
	 *
	 * @param string $option self::AVAIL_OPTION or self::SITEMAP_OPTION.
	 * @param string $type   Sitemap type slug.
	 * @param string $lang   Language slug.
	 */
	private function build_version_token( string $option, string $type, string $lang ): string {
		$versions      = $this->get_versions( $option );
		$lang          = sanitize_key( $lang );
		$type          = sanitize_key( $type );
		$type_lang_key = $type . '|' . $lang;

		$all       = (int) ( $versions['all'] ?? 1 );
		$lang_ver  = (int) ( $versions['lang'][ $lang ] ?? 1 );
		$type_lang = (int) ( $versions['type_lang'][ $type_lang_key ] ?? 1 );

		return 'g' . $all . '_l' . $lang_ver . '_t' . $type_lang;
	}

	/**
	 * Increments the global version counter for availability or sitemap cache options.
	 *
	 * @param string $option self::AVAIL_OPTION or self::SITEMAP_OPTION.
	 */
	public function bump_all_versions( string $option ): void {
		$versions        = $this->get_versions( $option );
		$versions['all'] = (int) ( $versions['all'] ?? 1 ) + 1;
		$this->save_versions( $option, $versions );
	}

	/**
	 * Increments the per-language version counter for availability or sitemap cache options.
	 *
	 * @param string $option self::AVAIL_OPTION or self::SITEMAP_OPTION.
	 * @param string $lang   Language slug.
	 */
	public function bump_language_versions( string $option, string $lang ): void {
		$lang = sanitize_key( $lang );

		if ( '' === $lang ) {
			return;
		}

		$versions                  = $this->get_versions( $option );
		$versions['lang'][ $lang ] = (int) ( $versions['lang'][ $lang ] ?? 1 ) + 1;
		$this->save_versions( $option, $versions );
	}

	/**
	 * Increments the per-type+language version counter for availability or sitemap cache options.
	 *
	 * @param string $option self::AVAIL_OPTION or self::SITEMAP_OPTION.
	 * @param string $type   Sitemap type slug.
	 * @param string $lang   Language slug.
	 */
	public function bump_type_language_versions( string $option, string $type, string $lang ): void {
		$type = sanitize_key( $type );
		$lang = sanitize_key( $lang );

		if ( '' === $type || '' === $lang ) {
			return;
		}

		$key = $type . '|' . $lang;

		$versions                      = $this->get_versions( $option );
		$versions['type_lang'][ $key ] = (int) ( $versions['type_lang'][ $key ] ?? 1 ) + 1;
		$this->save_versions( $option, $versions );
	}
}
