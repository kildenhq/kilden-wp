# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0-alpha.1] - 2026-07-14

### Added

- Visitor snippet (official Kilden loader) with WP Consent API gating.
- Cache-safe identity: `GET /wp-json/kilden/v1/identity` signs HS256 tokens
  for logged-in users; nothing visitor-specific in page HTML.
- WooCommerce server-side `order_completed` / `order_refunded` with the
  secret key, idempotent across gateway hook differences.
- The distinct_id bridge: hidden checkout field (classic), session-backed
  REST bridge (blocks checkout), persisted to order meta.
- Browser funnel events: `product_viewed`, `product_added_to_cart`,
  `checkout_started`.
- `wp_remote_post()` transport for the embedded PHP SDK; core vendored under
  a prefixed namespace.
- `KILDEN_SECRET_KEY` / `KILDEN_IDENTITY_SECRET` wp-config.php constants.

[Unreleased]: https://github.com/kildenhq/kilden-wp/compare/v0.1.0-alpha.1...HEAD
[0.1.0-alpha.1]: https://github.com/kildenhq/kilden-wp/releases/tag/v0.1.0-alpha.1
