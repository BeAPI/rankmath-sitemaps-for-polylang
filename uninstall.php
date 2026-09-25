<?php
/**
 * Uninstall Rank Math Sitemaps for Polylang.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$rmsp_dir      = plugin_dir_path( __FILE__ );
$rmsp_autoload = $rmsp_dir . 'vendor/autoload.php';

if ( is_readable( $rmsp_autoload ) ) {
	require_once $rmsp_autoload;
} else {
	require_once $rmsp_dir . 'includes/Transient_Versions.php';
	require_once $rmsp_dir . 'includes/Sitemap_Availability.php';
	require_once $rmsp_dir . 'includes/Sitemap_Cache.php';
}

\RankMathSitemapsPolylang\Sitemap_Cache::clear_all();
\RankMathSitemapsPolylang\Transient_Versions::delete_options();
