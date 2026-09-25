# Rank Math Sitemaps for Polylang

Serves one Rank Math XML sitemap per Polylang language in directory mode with `hide_default` enabled.

## Requirements

- WordPress 6.5+
- PHP 8.1+
- [Rank Math SEO](https://wordpress.org/plugins/seo-by-rank-math/)
- Polylang or Polylang Pro in directory mode (`force_lang` 0 or 1) with `hide_default` enabled

Multi-domain Polylang setups (`force_lang` > 1) are left to Rank Math native behaviour.

## Installation

### Manual

Copy the plugin folder to `wp-content/plugins/`, run `composer install --no-dev` inside the plugin directory, then activate it from the Plugins screen.

### Composer (BeAPI Satis)

When the package is published on `composer.beapi.fr`:

```bash
composer require beapi/rankmath-sitemaps-for-polylang:1.1.0
```

Ensure Rank Math and Polylang are active before relying on sitemap URLs.

## Development

```bash
composer install
composer cs
composer test
```

See [DEPLOYMENT.md](DEPLOYMENT.md) for the release workflow (private Satis package).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
