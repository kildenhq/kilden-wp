# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0-alpha.2] - 2026-07-17

### Fixed

- Server-side order tracking, which had never worked in a real install: the
  vendored core kept one unprefixed reference to `Kilden\Client`, so every send
  died with a class-not-found that the SDK's never-throw guard swallowed.
  `order_completed` and `order_refunded` now reach Kilden.
- The blocks checkout — the default one — no longer loses the visitor's id.
  Its bridge wrote to a WooCommerce session that is never booted on a custom
  REST route, and reported success anyway, so every guest order was recorded
  as a person of its own instead of the visitor who placed it.
- Orders are no longer attributable by the browser. The bridged id came from a
  checkout form field and outranked even the logged-in customer, so a buyer
  could name someone else and have their revenue land on that person as
  verified fact. Only an anonymous id is accepted now, and logged-in customers
  are resolved server-side.
- The last-resort id for guests whose bridge never ran is now a well-formed
  anonymous id. `anon_wp_order_<id>` was read by the platform as an
  *identified* person, one per order, that could never merge.

### Changed

- Vendored core updated to kilden/kilden-php 0.1.0-alpha.5, which brings the
  U+2028/U+2029 canonical-JSON fix into the identity signer.
- `build-vendor` verifies its own output, and the release fails when the tag
  disagrees with any of the three places the plugin declares its version.

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

[Unreleased]: https://github.com/kildenhq/kilden-wp/compare/v0.1.0-alpha.2...HEAD
[0.1.0-alpha.2]: https://github.com/kildenhq/kilden-wp/compare/v0.1.0-alpha.1...v0.1.0-alpha.2
[0.1.0-alpha.1]: https://github.com/kildenhq/kilden-wp/releases/tag/v0.1.0-alpha.1
