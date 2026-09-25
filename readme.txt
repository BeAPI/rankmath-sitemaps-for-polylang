=== Rank Math Sitemaps for Polylang ===
Contributors: beapi
Tags: rank-math, polylang, sitemap, seo, multilingual
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 7.4
Requires Plugins: seo-by-rank-math
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Serves one Rank Math XML sitemap per Polylang language in directory mode (hide_default).

== Description ==

This plugin keeps Rank Math as the XML sitemap generator while splitting output by Polylang language:

* Default language keeps existing sitemap URLs (`/post-sitemap.xml` or `/es/post-sitemap.xml`).
* Secondary languages get prefixed URLs (`/en/post-sitemap.xml` or `/es/en/post-sitemap.xml`).
* The sitemap index lists one entry per language and sitemap type.
* Inactive Polylang Pro languages are excluded.

Requirements:

* Rank Math SEO
* Polylang or Polylang Pro in directory mode (`force_lang` 0 or 1) with `hide_default` enabled.

Multi-domain Polylang setups (`force_lang` > 1) are left to Rank Math native behaviour.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate through the Plugins screen.
3. Ensure Rank Math and Polylang are active.

== Changelog ==

= 1.0.0 =
* Initial release.
