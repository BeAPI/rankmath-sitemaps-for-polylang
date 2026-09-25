<?php

namespace RankMathSitemapsPolylang;

defined( 'ABSPATH' ) || exit;

/**
 * Bumps version counters for transient cache invalidation.
 *
 * Avoids SQL deletes that do not purge object-cached transients.
 */
final class Transient_Versions {

	public const AVAIL_OPTION = 'rmsp_avail_versions';

	public const SITEMAP_OPTION = 'rmsp_sitemap_versions';

	/**
	 * Build a version suffix for a transient key.
	 */
	public static function get_suffix( string $option, string $type, string $lang ): string {
		$versions      = self::get( $option );
		$lang          = sanitize_key( $lang );
		$type          = sanitize_key( $type );
		$type_lang_key = $type . '|' . $lang;

		$all       = (int) ( $versions['all'] ?? 1 );
		$lang_ver  = (int) ( $versions['lang'][ $lang ] ?? 1 );
		$type_lang = (int) ( $versions['type_lang'][ $type_lang_key ] ?? 1 );

		return 'g' . $all . '_l' . $lang_ver . '_t' . $type_lang;
	}

	/**
	 * Invalidate every transient for the given option namespace.
	 */
	public static function bump_all( string $option ): void {
		$versions        = self::get( $option );
		$versions['all'] = (int) ( $versions['all'] ?? 1 ) + 1;
		self::save( $option, $versions );
	}

	/**
	 * Invalidate every transient for one language.
	 */
	public static function bump_language( string $option, string $lang ): void {
		$lang = sanitize_key( $lang );

		if ( '' === $lang ) {
			return;
		}

		$versions                  = self::get( $option );
		$versions['lang'][ $lang ] = (int) ( $versions['lang'][ $lang ] ?? 1 ) + 1;
		self::save( $option, $versions );
	}

	/**
	 * Invalidate every transient for one sitemap type in one language.
	 */
	public static function bump_type_language( string $option, string $type, string $lang ): void {
		$type = sanitize_key( $type );
		$lang = sanitize_key( $lang );

		if ( '' === $type || '' === $lang ) {
			return;
		}

		$key = $type . '|' . $lang;

		$versions                      = self::get( $option );
		$versions['type_lang'][ $key ] = (int) ( $versions['type_lang'][ $key ] ?? 1 ) + 1;
		self::save( $option, $versions );
	}

	/**
	 * Remove version options on uninstall.
	 */
	public static function delete_options(): void {
		delete_option( self::AVAIL_OPTION );
		delete_option( self::SITEMAP_OPTION );
	}

	/**
	 * Loads version counters from the options table.
	 *
	 * @param string $option Option name.
	 * @return array{all?: int, lang?: array<string, int>, type_lang?: array<string, int>}
	 */
	private static function get( string $option ): array {
		$versions = get_option( $option, [] );

		return is_array( $versions ) ? $versions : [];
	}

	/**
	 * Persists version counters to the options table.
	 *
	 * @param string                                                                 $option   Option name.
	 * @param array{all?: int, lang?: array<string, int>, type_lang?: array<string, int>} $versions Version state.
	 */
	private static function save( string $option, array $versions ): void {
		update_option( $option, $versions, false );
	}
}
