=== Kilden ===
Contributors: freshworkstudio
Tags: analytics, woocommerce, ecommerce, statistics, customer data
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0-alpha.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Kilden analytics for your WordPress site and WooCommerce store: reliable revenue tracking, real visitor journeys, no broken numbers.

== Description ==

Kilden is a customer data platform: analytics, funnels, session replay and customer messaging built on one event pipeline. This plugin connects your WordPress site to it in minutes.

What makes it different from pasting a script tag:

* **Revenue you can trust.** Orders and refunds are recorded from your server when WooCommerce confirms the payment — not from the buyer's browser, where ad blockers, closed tabs and forged requests silently corrupt the numbers.
* **The full journey, connected.** A visitor browses anonymously, adds to cart and pays. The plugin bridges the anonymous visitor to the completed order, so your funnel — viewed product, added to cart, started checkout, purchased — stays whole, including guest checkouts.
* **Works with page caching.** Nothing visitor-specific is ever printed into your pages, so WP Rocket, Varnish and host-level caches keep working and never leak one visitor's identity to another.
* **Verified identity.** Logged-in customers are identified with a signed token, so events attributed to a customer are cryptographically backed, not just claimed.
* **Consent-aware.** If your consent plugin supports the WP Consent API (Complianz, CookieYes and others), visitor tracking waits for the statistics category to be granted.

Events recorded with WooCommerce active: product viewed, product added to cart, checkout started (in the browser), order completed and order refunded (from your server, with order id, revenue, currency, items and coupon).

You need a Kilden account and a project. Get one at [kilden.io](https://kilden.io).

== Installation ==

1. Install and activate the plugin.
2. Go to Settings → Kilden.
3. Paste the public write key, the secret write key, and the identity secret + key id from your Kilden project settings.
4. Save. Page tracking starts immediately; order tracking starts with the next completed order.

For hardened setups you can keep secrets out of the database by defining constants in wp-config.php:

`define('KILDEN_SECRET_KEY', 'sk_...');`
`define('KILDEN_IDENTITY_SECRET', '...');`

When a constant is defined, the corresponding settings field locks itself and the database value is ignored.

== Frequently Asked Questions ==

= Does it slow my store down? =

No. The visitor script loads asynchronously from a CDN. Server-side events are queued in memory and sent after WordPress finishes your page.

= Do I need WooCommerce? =

No. Without WooCommerce you get page analytics and identity for logged-in users. With WooCommerce you also get the store events.

= Where is my data stored? =

In your Kilden project. The plugin stores only its settings in WordPress; uninstalling removes them. Order metadata gains two fields (`_kilden_distinct_id`, `_kilden_tracked`) used to keep events attributable and idempotent.

= Is it GDPR-friendly? =

Browser tracking respects the WP Consent API when a compatible consent plugin is active. Server-side order events are first-party records of your own sales and are not gated by cookies — the same as your database keeping the order itself.

= The secret key is in my database. Is that safe? =

It is the standard practice for WordPress plugins, and it means any plugin with database access could read it. If that bothers you (it is reasonable), define `KILDEN_SECRET_KEY` in wp-config.php instead — the plugin prefers it automatically.

== Screenshots ==

1. Settings → Kilden: keys, identity and feature toggles.
2. A WooCommerce purchase funnel in Kilden, including guest checkouts.
3. A completed order arriving as a server-side `order_completed` event.

== Changelog ==

= 0.1.0-alpha.1 =
* Initial release: visitor snippet with consent gating, cache-safe identity endpoint, WooCommerce server-side order and refund tracking with the distinct_id bridge.
