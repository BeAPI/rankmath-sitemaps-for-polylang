<?php

namespace RankMathSitemapsPolylang;

defined( 'ABSPATH' ) || exit;

/**
 * Sets Polylang curlang for sitemap generation.
 *
 * Corrects the front-page entry loc in urlsets.
 */
final class Language_Context {

	public function register_hooks(): void {
		add_action( 'parse_query', [ self::class, 'reject_invalid_sitemap_language' ], 0 );
		add_action( 'parse_query', [ $this, 'setup_sitemap_language' ], 1 );
		add_filter( 'rank_math/sitemap/xml_post_url', [ $this, 'fix_front_page_sitemap_url' ], 10, 2 );
	}

	/**
	 * Block Rank Math and cache layers before an inactive language slug is normalized away.
	 *
	 * @param \WP_Query $query Main query.
	 */
	public static function reject_invalid_sitemap_language( \WP_Query $query ): void {
		if ( ! $query->is_main_query() || ! Url_Helper::is_sitemap_request() || Url_Helper::is_index_request() ) {
			return;
		}

		$lang = get_query_var( Router::QUERY_VAR );

		if ( ! is_string( $lang ) || '' === $lang ) {
			return;
		}

		if ( Url_Helper::is_active_language( $lang ) ) {
			return;
		}

		$query->set_404();
		status_header( 404 );
		set_query_var( 'sitemap', '' );
		set_query_var( 'sitemap_n', '' );
		set_query_var( Router::QUERY_VAR, '' );
	}

	/**
	 * Set default language query var and switch PLL curlang before Rank Math renders.
	 *
	 * @param \WP_Query $query Main query.
	 */
	public function setup_sitemap_language( \WP_Query $query ): void {
		if ( ! $query->is_main_query() || ! Url_Helper::is_sitemap_request() ) {
			return;
		}

		$lang = get_query_var( Router::QUERY_VAR );

		if ( ! is_string( $lang ) || '' === $lang ) {
			$lang = (string) pll_default_language();
			set_query_var( Router::QUERY_VAR, $lang );
		}

		if ( ! Url_Helper::is_active_language( $lang ) ) {
			$query->set_404();
			status_header( 404 );

			return;
		}

		$language = PLL()->model->get_language( $lang );

		if ( ! $language instanceof \PLL_Language ) {
			$query->set_404();
			status_header( 404 );

			return;
		}

		PLL()->curlang = $language;
	}

	/**
	 * Point the front page sitemap entry at the language home URL.
	 *
	 * @param string   $url  Sitemap URL.
	 * @param \WP_Post $post Post object.
	 */
	public function fix_front_page_sitemap_url( string $url, $post ): string {
		$page_on_front_id = (int) get_option( 'page_on_front' );

		if ( ! $page_on_front_id || ! $post instanceof \WP_Post ) {
			return $url;
		}

		$lang = pll_get_post_language( $post->ID );

		if ( ! $lang ) {
			return $url;
		}

		if ( (int) pll_get_post( $page_on_front_id, $lang ) === (int) $post->ID ) {
			return pll_home_url( Url_Helper::get_target_language() );
		}

		return $url;
	}
}
