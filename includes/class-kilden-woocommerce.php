<?php
/**
 * WooCommerce integration — the reason this plugin exists (docs/27 §5).
 *
 * Revenue is tracked SERVER-SIDE with the secret key: a browser
 * `track('purchase')` can be forged by anyone with the public key, a
 * server event cannot. The hard part is the distinct_id bridge: the order
 * completes on the server, but a guest's identity (`anon_...`) lives only in
 * the browser. Three mechanisms, in order of precedence:
 *
 *   1. Logged-in customer → WP user id, resolved here rather than taken from
 *      the browser. Same value either way (the identity endpoint hands the
 *      browser the id from this same filter), minus the trust.
 *   2. The checkout bridge, for guests: JS reads kilden.getDistinctId() into
 *      a hidden field (classic) or a session-backed REST call (blocks). It
 *      may only ever carry an anonymous id — everything it says is untrusted
 *      input that would otherwise reach the platform as verified fact.
 *   3. Last resort: a deterministic per-order anonymous id, documented.
 *
 * The bridged id is persisted to order meta so events can be reprocessed and
 * debugged, but it is re-checked on every read: meta may predate the rule.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kilden_WooCommerce
{
    const META_DISTINCT_ID = '_kilden_distinct_id';
    const META_TRACKED     = '_kilden_tracked';
    const SESSION_KEY      = 'kilden_distinct_id';

    public static function register(): void
    {
        if (!Kilden_Settings::enabled('woocommerce') || !class_exists('WooCommerce')) {
            return;
        }

        // Server-side events.
        add_action('woocommerce_payment_complete', array(__CLASS__, 'track_order_completed'));
        // Fallback for gateways that never call payment_complete (BACS, COD):
        // the meta guard makes the two hooks idempotent.
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'track_order_completed'));
        add_action('woocommerce_order_refunded', array(__CLASS__, 'track_order_refunded'), 10, 2);

        // distinct_id bridge.
        add_action('woocommerce_after_order_notes', array(__CLASS__, 'render_hidden_field'));
        add_action('woocommerce_checkout_create_order', array(__CLASS__, 'persist_distinct_id_classic'), 10, 2);
        add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'persist_distinct_id_blocks'));
        add_action('rest_api_init', array(__CLASS__, 'register_bridge_route'));

        // Browser-side funnel events (public key, via the web SDK).
        if (Kilden_Settings::enabled('snippet') && Kilden_Settings::public_key() !== '') {
            add_action('wp_footer', array(__CLASS__, 'print_browser_events'));
        }
    }

    // --- server-side events ---

    /** @param int|mixed $order_id */
    public static function track_order_completed($order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        if ('yes' === (string) $order->get_meta(self::META_TRACKED)) {
            return;
        }

        $client = Kilden_Client_Factory::client();
        if ($client === null) {
            return;
        }

        $order->update_meta_data(self::META_TRACKED, 'yes');
        $order->save();

        $client->track(self::distinct_id_for($order), 'order_completed', self::order_properties($order));
    }

    /**
     * @param int|mixed $order_id
     * @param int|mixed $refund_id
     */
    public static function track_order_refunded($order_id, $refund_id): void
    {
        $order = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);
        if (!$order || !$refund) {
            return;
        }
        $client = Kilden_Client_Factory::client();
        if ($client === null) {
            return;
        }

        $client->track(self::distinct_id_for($order), 'order_refunded', array(
            'order_id'      => (string) $order->get_id(),
            'refund_amount' => (float) $refund->get_amount(),
            'currency'      => (string) $order->get_currency(),
        ));
    }

    /**
     * @param WC_Order $order
     * @return array<string, mixed>
     */
    public static function order_properties($order)
    {
        $items = array();
        foreach ($order->get_items() as $item) {
            $quantity = (int) $item->get_quantity();
            $total = (float) $item->get_total();
            $items[] = array(
                'product_id' => (string) $item->get_product_id(),
                'name'       => (string) $item->get_name(),
                'price'      => $quantity > 0 ? round($total / $quantity, 2) : $total,
                'quantity'   => $quantity,
            );
        }

        $properties = array(
            'order_id' => (string) $order->get_id(),
            'revenue'  => (float) $order->get_total(),
            'currency' => (string) $order->get_currency(),
            'items'    => $items,
        );
        $coupons = $order->get_coupon_codes();
        if ($coupons !== array()) {
            $properties['coupon'] = implode(',', $coupons);
        }

        return $properties;
    }

    /**
     * Precedence: authenticated customer → the bridged id (guests only, and
     * only in the anonymous shape) → documented last-resort anonymous id.
     *
     * The customer comes first ALWAYS. The bridged id arrives in a checkout
     * form field, and this event goes out server-side with the secret key,
     * which the platform reads as authenticated fact — so letting the browser
     * outrank the session lets a buyer name someone else and land their
     * revenue on that person. It used to.
     *
     * @param WC_Order $order
     */
    public static function distinct_id_for($order): string
    {
        // The customer WordPress authenticated outranks anything the browser
        // said. The bridged id travels in a form field, so letting it win
        // here would let a buyer post someone else's id and have their order
        // land on that person's timeline as verified fact — this send goes
        // out server-side with the secret key, which the platform trusts.
        $user_id = (int) $order->get_customer_id();
        if ($user_id > 0) {
            return (string) apply_filters('kilden_distinct_id_for_user', (string) $user_id, get_user_by('id', $user_id));
        }

        // For guests the bridge exists to stitch the order onto the anonymous
        // id the visitor browsed under, so an anonymous id is the only thing
        // it is allowed to say. Checked again here and not only on the way in:
        // meta can predate this rule, or be written by anything else.
        $bridged = (string) $order->get_meta(self::META_DISTINCT_ID);
        if (self::is_anonymous_id($bridged)) {
            return $bridged;
        }

        return self::synthetic_anonymous_id($order);
    }

    /**
     * Mirror of kilden-core's internal/verify.AnonPattern (docs/11): the exact
     * shape the platform reads as anonymous. An id that misses it — including
     * `anon_` followed by some other random string — is read as a
     * customer-declared *identified* id instead, and the resolver then refuses
     * to merge it with anything ("two non-anonymous identities"). Nothing
     * errors; the visitor's history simply never joins up.
     */
    private static function is_anonymous_id(string $id): bool
    {
        return (bool) preg_match(
            '/^anon_[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id
        );
    }

    /**
     * Documented last resort: guest checkout where the bridge never ran (JS
     * blocked, order created headlessly). We do not know who this is, so the
     * id has to say precisely that rather than invent an identified person
     * per order. Deterministic, so reprocessing an order lands on the same
     * person, and salted with the site so two stores sharing one Kilden
     * project cannot collide on order numbers.
     *
     * @param WC_Order $order
     */
    private static function synthetic_anonymous_id($order): string
    {
        $created = $order->get_date_created();
        $ms = $created ? ((int) $created->getTimestamp()) * 1000 : 0;

        // UUIDv7 (RFC 9562): 48-bit big-endian millisecond timestamp, then
        // version and variant bits, then bytes that are random in a real v7
        // and derived here so that the id is stable for this order.
        $bytes = substr(pack('J', $ms), 2, 6)
            . substr(hash('sha256', home_url() . '|kilden-wp-order|' . $order->get_id(), true), 0, 10);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return 'anon_' . implode('-', array(
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ));
    }

    // --- distinct_id bridge ---

    public static function render_hidden_field(): void
    {
        echo '<input type="hidden" name="kilden_distinct_id" id="kilden_distinct_id" value="">';
        wc_enqueue_js(self::bridge_js("document.getElementById('kilden_distinct_id').value = id;"));
    }

    /**
     * @param WC_Order $order
     * @param mixed    $data
     */
    public static function persist_distinct_id_classic($order, $data = null): void
    {
        // Nonce-free by design: this is the buyer's own analytics id for
        // their own order, sanitized, no capability change involved. It is
        // still only ever an anonymous id — see is_anonymous_id().
        $posted = isset($_POST['kilden_distinct_id']) ? sanitize_text_field(wp_unslash((string) $_POST['kilden_distinct_id'])) : '';
        if (self::is_anonymous_id($posted)) {
            $order->update_meta_data(self::META_DISTINCT_ID, $posted);
        }
    }

    /**
     * Blocks checkout: the id arrives earlier via the session bridge.
     *
     * @param WC_Order $order
     */
    public static function persist_distinct_id_blocks($order): void
    {
        if ((string) $order->get_meta(self::META_DISTINCT_ID) !== '') {
            return;
        }
        $session = function_exists('WC') && WC()->session ? (string) WC()->session->get(self::SESSION_KEY) : '';
        if (self::is_anonymous_id($session)) {
            $order->update_meta_data(self::META_DISTINCT_ID, $session);
            $order->save();
        }
    }

    /**
     * POST /wp-json/kilden/v1/distinct-id — the blocks checkout has no
     * hidden fields, so the browser parks its id in the Woo session and the
     * order hook picks it up.
     */
    public static function register_bridge_route(): void
    {
        register_rest_route('kilden/v1', '/distinct-id', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_bridge'),
            'permission_callback' => '__return_true',
            'args'                => array(
                'distinct_id' => array('type' => 'string', 'required' => true),
            ),
        ));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_bridge($request)
    {
        nocache_headers();

        $id = sanitize_text_field((string) $request->get_param('distinct_id'));
        if (!self::is_anonymous_id($id)) {
            return new WP_REST_Response(array('status' => 'ignored'), 400);
        }

        if (!function_exists('WC')) {
            return new WP_REST_Response(array('status' => 'unavailable'), 503);
        }

        // WooCommerce only boots the session for frontend and Store API
        // requests, so on a custom REST route like this one WC()->session is
        // null and there is nothing to write to. wc_load_cart() is the
        // supported way to ask for it; without this the route did nothing at
        // all and still answered 200, which is how the blocks checkout — the
        // default one — quietly lost every guest's id.
        if (!WC()->session && function_exists('wc_load_cart')) {
            wc_load_cart();
        }

        if (!WC()->session) {
            return new WP_REST_Response(array('status' => 'unavailable'), 503);
        }

        WC()->session->set(self::SESSION_KEY, $id);

        return new WP_REST_Response(array('status' => 'ok'), 200);
    }

    // --- browser-side funnel events ---

    public static function print_browser_events(): void
    {
        $js = self::browser_events_js();
        if ($js !== '') {
            echo '<script>' . $js . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }

    public static function browser_events_js(): string
    {
        $parts = array();

        if (function_exists('is_product') && is_product()) {
            $product = function_exists('wc_get_product') ? wc_get_product(get_the_ID()) : null;
            if ($product) {
                $parts[] = self::track_js('product_viewed', array(
                    'product_id' => (string) $product->get_id(),
                    'name'       => (string) $product->get_name(),
                    'price'      => (float) $product->get_price(),
                    'currency'   => (string) get_woocommerce_currency(),
                ));
            }
        }

        if (function_exists('is_checkout') && is_checkout() && function_exists('WC') && WC()->cart && !is_wc_endpoint_url('order-received')) {
            $items = array();
            foreach (WC()->cart->get_cart() as $line) {
                $items[] = array(
                    'product_id' => (string) $line['product_id'],
                    'quantity'   => (int) $line['quantity'],
                );
            }
            $parts[] = self::track_js('checkout_started', array(
                'value'    => (float) WC()->cart->total,
                'currency' => (string) get_woocommerce_currency(),
                'items'    => $items,
            ));
            // Blocks checkout has no hidden field: bridge through the session.
            $parts[] = self::bridge_js(
                "fetch(" . wp_json_encode(esc_url_raw(rest_url('kilden/v1/distinct-id'))) . ", {"
                . "method: 'POST', credentials: 'same-origin',"
                . "headers: {'Content-Type': 'application/json'},"
                . "body: JSON.stringify({distinct_id: id})});"
            );
        }

        // add-to-cart is an event, not a page: hook the Woo jQuery event
        // when present, fall back to form submits.
        if ((function_exists('is_product') && is_product()) || (function_exists('is_shop') && is_shop())) {
            $parts[] = <<<'JS'
(function () {
  if (window.jQuery) {
    jQuery(document.body).on('added_to_cart', function (e, fragments, hash, button) {
      var b = button && button[0] ? button[0] : null;
      window.kilden && kilden.track('product_added_to_cart', {
        product_id: b && b.dataset.product_id ? String(b.dataset.product_id) : undefined,
        quantity: b && b.dataset.quantity ? parseInt(b.dataset.quantity, 10) : 1
      });
    });
  }
  var form = document.querySelector('form.cart');
  if (form) {
    form.addEventListener('submit', function () {
      var qty = form.querySelector('input.qty');
      var btn = form.querySelector('[name="add-to-cart"]');
      window.kilden && kilden.track('product_added_to_cart', {
        product_id: btn && btn.value ? String(btn.value) : undefined,
        quantity: qty && qty.value ? parseInt(qty.value, 10) : 1
      });
    });
  }
})();
JS;
        }

        return implode("\n", array_filter($parts));
    }

    /**
     * @param array<string, mixed> $properties
     */
    private static function track_js(string $event, array $properties): string
    {
        return sprintf(
            'window.kilden && kilden.track(%s, %s);',
            wp_json_encode($event),
            wp_json_encode($properties)
        );
    }

    /**
     * Waits for the real SDK (the stub cannot answer getDistinctId
     * synchronously), then hands the id to $use.
     */
    private static function bridge_js(string $use): string
    {
        return <<<JS
(function () {
  var tries = 0;
  (function wait() {
    if (window.kilden && !window.kilden._q && typeof kilden.getDistinctId === 'function') {
      var id = kilden.getDistinctId();
      if (id) { {$use} }
      return;
    }
    if (tries++ < 50) setTimeout(wait, 200);
  })();
})();
JS;
    }
}
