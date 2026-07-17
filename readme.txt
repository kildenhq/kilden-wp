=== Kilden ===
Contributors: freshworkstudio
Tags: analytics, woocommerce, ecommerce, statistics, customer data
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.1
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

= External services =

This plugin sends data to Kilden, the analytics service it connects your site
to. It is not optional: sending your site's data to your Kilden project is what
the plugin is for. Nothing is sent until you enter your project keys in
Settings → Kilden.

It relies on Kilden in two ways:

1. **The visitor script**, loaded in your visitors' browsers from
`https://cdn.kilden.io/kilden.iife.js`. Once loaded it sends page views,
clicks and the WooCommerce browsing events listed above to
`https://ingest.kilden.io`, together with the information the Kilden script
collects about the visit: page URL and referrer, browser and device
characteristics, and an anonymous visitor id it stores in the browser. For a
logged-in visitor it also sends that user's WordPress user id, email address
and display name, so the visit can be attributed to them. If a consent plugin
supporting the WP Consent API is installed, none of this happens until the
statistics category is granted.

2. **Order tracking**, sent from your server to `https://ingest.kilden.io`
when WooCommerce records an order as paid or refunded. Each message carries
the order id, revenue, currency, coupon, the products bought (id, name, price,
quantity), and the id of the customer or anonymous visitor the order belongs
to.

Service: Kilden — [kilden.io](https://kilden.io) ·
[Terms](https://kilden.io/terms) · [Privacy policy](https://kilden.io/privacy)

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

= 0.1.1 =
* Declared the external service in this readme: what is sent to Kilden, when, and from where.
* Tested up to WordPress 7.0.

= 0.1.0 =
* First release verified end to end against a real WordPress and WooCommerce store: browsing, checkout (block and classic, as guest and as a signed-in customer), revenue, refunds, identity and consent.
* Fixed: server-side order and refund tracking never reached Kilden.
* Fixed: on the block checkout — the default one — a guest's order was recorded as a visitor of its own instead of the person who placed it.
* Fixed: logged-in visitors were never identified, so their browser events were never verified.
* Fixed: consent gating switched tracking off entirely wherever a WP Consent API plugin was installed.
* Security: a buyer could name someone else as the person an order belonged to.
* Security: another site could read a logged-in visitor's identity token.

= 0.1.0-alpha.1 =
* Initial release: visitor snippet with consent gating, cache-safe identity endpoint, WooCommerce server-side order and refund tracking with the distinct_id bridge.
