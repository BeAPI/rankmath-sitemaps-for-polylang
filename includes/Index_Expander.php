<?php

namespace RankMathSitemapsPolylang;

defined( 'ABSPATH' ) || exit;

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
	private static array $buffer = [];

	public function register_hooks(): void {
		if ( ! Url_Helper::is_multilingual() ) {
			return;
		}

		add_filter( 'rank_math/sitemap/index/entry', [ $this, 'filter_index_entry' ], 10, 3 );
		add_filter( 'rank_math/sitemap/index', [ $this, 'append_buffered_entries' ], 10 );
	}

	/**
	 * Point the default entry at the default-language URL and buffer other languages.
	 *
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

		$parsed = Url_Helper::parse_sitemap_filename( (string) $entry['loc'] );

		if ( null === $parsed ) {
			return $entry;
		}

		$default     = (string) pll_default_language();
		$has_default = Sitemap_Availability::has_urlset_for_language(
			$parsed['type'],
			$parsed['page'],
			$default
		);
		$lastmod     = isset( $entry['lastmod'] ) ? (string) $entry['lastmod'] : null;

		foreach ( Url_Helper::get_active_language_slugs() as $slug ) {
			if ( $slug === $default ) {
				continue;
			}

			if ( ! Sitemap_Availability::has_urlset_for_language( $parsed['type'], $parsed['page'], $slug ) ) {
				continue;
			}

			self::$buffer[] = [
				'loc'     => Url_Helper::get_sitemap_loc( $parsed['type'], $parsed['page'], $slug ),
				'lastmod' => $lastmod,
			];
		}

		if ( ! $has_default ) {
			return false;
		}

		$entry['loc'] = Url_Helper::get_sitemap_loc( $parsed['type'], $parsed['page'], $default );

		return $entry;
	}

	/**
	 * Append buffered language variants to the sitemap index XML.
	 */
	public function append_buffered_entries( string $xml ): string {
		if ( empty( self::$buffer ) ) {
			return $xml;
		}

		foreach ( self::$buffer as $entry ) {
			$xml .= $this->format_index_entry( $entry['loc'], $entry['lastmod'] );
		}

		self::$buffer = [];

		return $xml;
	}

	/**
	 * @param string      $loc     Sitemap URL.
	 * @param string|null $lastmod Optional last modification date.
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
}
