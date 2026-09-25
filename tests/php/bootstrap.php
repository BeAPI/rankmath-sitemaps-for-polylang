<?php
/**
 * PHPUnit bootstrap (no WordPress runtime).
 *
 * @package RankMathSitemapsPolylang
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', true );
}

$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
if ( ! is_readable( $autoload ) ) {
	fwrite( STDERR, "Run composer install first.\n" );
	exit( 1 );
}

require_once $autoload;

/** @var array<string, mixed> */
$GLOBALS['rmsp_test_options'] = [];

/** @var array<string, mixed> */
$GLOBALS['rmsp_test_query_vars'] = [];

/**
 * Resets stubbed WordPress state between tests.
 */
function rmsp_test_reset_state(): void {
	$GLOBALS['rmsp_test_options']     = [];
	$GLOBALS['rmsp_test_query_vars'] = [];
	\RankMathSitemapsPolylang\Url_Helper::end_availability_check();
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * @param string $url       URL.
	 * @param int    $component Component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}

if ( ! function_exists( 'get_query_var' ) ) {
	/**
	 * @param string $var     Query var name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function get_query_var( $var, $default = '' ) {
		$vars = $GLOBALS['rmsp_test_query_vars'];

		return array_key_exists( $var, $vars ) ? $vars[ $var ] : $default;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $option  Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function get_option( $option, $default = false ) {
		$options = $GLOBALS['rmsp_test_options'];

		return array_key_exists( $option, $options ) ? $options[ $option ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $option   Option name.
	 * @param mixed  $value    Value.
	 * @param mixed  $autoload Whether to autoload.
	 * @return bool
	 */
	function update_option( $option, $value, $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$GLOBALS['rmsp_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * @param string $option Option name.
	 * @return bool
	 */
	function delete_option( $option ) {
		unset( $GLOBALS['rmsp_test_options'][ $option ] );

		return true;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );

		return preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '';
	}
}
