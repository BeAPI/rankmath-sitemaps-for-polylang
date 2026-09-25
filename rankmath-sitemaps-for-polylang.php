<?php
/**
 * Plugin Name:       Rank Math Sitemaps for Polylang
 * Plugin URI:        https://github.com/beapi/rankmath-sitemaps-for-polylang
 * Description:       Serves one Rank Math XML sitemap per Polylang language (directory mode, hide_default).
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  seo-by-rank-math
 * Author:            Be API
 * Author URI:        https://beapi.fr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rankmath-sitemaps-for-polylang
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'RMSP_VERSION', '1.0.0' );
define( 'RMSP_FILE', __FILE__ );
define( 'RMSP_DIR', plugin_dir_path( __FILE__ ) );

$rmsp_autoload = RMSP_DIR . 'vendor/autoload.php';
if ( ! is_readable( $rmsp_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'Rank Math Sitemaps for Polylang requires Composer dependencies. Run composer install in the plugin directory.',
				'rankmath-sitemaps-for-polylang'
			);
			echo '</p></div>';
		}
	);
	return;
}

require_once $rmsp_autoload;

/**
 * Bootstrap the plugin after Polylang is ready.
 */
function rmsp_bootstrap(): void {
	if ( ! function_exists( 'PLL' ) ) {
		return;
	}

	if ( ! class_exists( '\RankMath\Sitemap\Router' ) ) {
		return;
	}

	$pll = PLL();

	if ( ! is_object( $pll ) || empty( $pll->options ) || (int) $pll->options['force_lang'] > 1 ) {
		return;
	}

	\RankMathSitemapsPolylang\Plugin::instance()->boot();
}
add_action( 'pll_init', 'rmsp_bootstrap', 5 );

register_activation_hook(
	__FILE__,
	static function (): void {
		// WordPress only persists rewrite rules registered during this request.
		\RankMathSitemapsPolylang\Router::register_rewrites();
		flush_rewrite_rules( false );
		\RankMathSitemapsPolylang\Plugin::clear_sitemap_caches();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		\RankMathSitemapsPolylang\Plugin::clear_sitemap_caches();
		\RankMathSitemapsPolylang\Router::flush_rewrites_on_deactivation();
	}
);
