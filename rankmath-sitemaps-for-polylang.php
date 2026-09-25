<?php
/**
 * Plugin Name:       Rank Math Sitemaps for Polylang
 * Plugin URI:        https://github.com/beapi/rankmath-sitemaps-for-polylang
 * Description:       Serves one Rank Math XML sitemap per Polylang language (directory mode, hide_default).
 * Version:           1.1.0
 * Requires at least: 6.5
 * Tested up to:      7.1.2
 * Requires PHP:      8.1
 * Requires Plugins:  seo-by-rank-math
 * Author:            Be API
 * Author URI:        https://beapi.fr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rankmath-sitemaps-for-polylang
 * Domain Path:       /languages
 */

namespace RankMathSitemapsPolylang;

use RankMath\Sitemap\Router as Rank_Math_Router;

defined( 'ABSPATH' ) || exit;

define( 'RMSP_VERSION', '1.1.0' );
define( 'RMSP_URL', plugin_dir_url( __FILE__ ) );
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

	if ( ! class_exists( Rank_Math_Router::class ) ) {
		return;
	}

	$pll = PLL();

	if ( ! is_object( $pll ) || empty( $pll->options ) || (int) $pll->options['force_lang'] > 1 ) {
		return;
	}

	Plugin::instance()->boot();
}

add_action(
	'pll_init',
	static function (): void {
		rmsp_bootstrap();
	},
	5
);

register_activation_hook(
	__FILE__,
	static function (): void {
		Plugin::instance()->on_activation();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		Plugin::instance()->on_deactivation();
	}
);
