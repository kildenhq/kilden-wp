<?php
/**
 * Minimal WooCommerce signatures for static analysis only — the real
 * woocommerce-stubs package is 10 MB for the five symbols this plugin
 * touches. Never loaded at runtime.
 */

/**
 * @param int|mixed $order_id
 * @return object|false
 */
function wc_get_order($order_id)
{
    return false;
}

function wc_enqueue_js(string $code): void
{
}

function get_woocommerce_currency(): string
{
    return 'USD';
}

/**
 * @param string|false $endpoint
 */
function is_wc_endpoint_url($endpoint = false): bool
{
    return false;
}

/**
 * @return object{session: object|null, cart: object|null}|null
 */
function WC()
{
    return null;
}

/** @param int|string $product_id */
function wc_get_product($product_id): ?object
{
    return null;
}
