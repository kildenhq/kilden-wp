# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-07-17

First release exercised end to end against a real WordPress + WooCommerce
install and Kilden's production ingest, rather than against the spec's mock:
browsing, checkout (both the blocks and the classic one, as guest and as a
signed-in customer), revenue, refunds, identity and consent.

### Fixed

- Consent gating, which had switched Kilden off entirely wherever a WP Consent
  API plugin was installed. The gate asked `wp_has_consent()` from the snippet
  — the first script on the page, while the consent API's own script is dozens
  further down — so every visitor read as unconsented and it waited for a
  change that never comes for someone who had already consented. It asks again
  once that script has run.

## [0.1.0-alpha.3] - 2026-07-17

### Fixed

- Identity verification for logged-in visitors, which had never happened: the
  identity endpoint asked WordPress for the current user, and WordPress ignores
  the login cookie on a REST request unless an `X-WP-Nonce` comes with it. It
  answered 204 to every signed-in visitor, so no token was ever minted and no
  browser event from a logged-in customer was ever verified. It validates the
  cookie itself now — a nonce cannot work here, being per-user and per-session
  while this endpoint exists precisely because the page HTML is cached.
- The identity endpoint refuses cross-origin reads. WordPress echoes any
  `Origin` back with `Access-Control-Allow-Credentials: true`, which is only
  safe while REST cookie auth needs a nonce; without this, any site a visitor
  opened could read their identity token and traits using their own cookie.

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

[Unreleased]: https://github.com/kildenhq/kilden-wp/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/kildenhq/kilden-wp/compare/v0.1.0-alpha.3...v0.1.0
[0.1.0-alpha.3]: https://github.com/kildenhq/kilden-wp/compare/v0.1.0-alpha.2...v0.1.0-alpha.3
[0.1.0-alpha.2]: https://github.com/kildenhq/kilden-wp/compare/v0.1.0-alpha.1...v0.1.0-alpha.2
[0.1.0-alpha.1]: https://github.com/kildenhq/kilden-wp/releases/tag/v0.1.0-alpha.1
