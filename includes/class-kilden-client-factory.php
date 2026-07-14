<?php
/**
 * Builds (and memoizes) the server-side Kilden client and the identity
 * signer from the plugin settings. Both return null when the required
 * secret is not configured — callers treat null as "feature off".
 */

if (!defined('ABSPATH')) {
    exit;
}

use KildenWP\Vendor\Kilden\Client;
use KildenWP\Vendor\Kilden\IdentitySigner;

class Kilden_Client_Factory
{
    /** @var Client|null */
    private static $client = null;

    /** @var bool */
    private static $client_failed = false;

    public static function client(): ?Client
    {
        /**
         * Short-circuit for tests and exotic setups: return a pre-built
         * client (e.g. with a custom transport) and the factory steps aside.
         *
         * @param Client|null $client
         */
        $prebuilt = apply_filters('kilden_pre_client', null);
        if ($prebuilt instanceof Client) {
            return $prebuilt;
        }

        if (self::$client !== null || self::$client_failed) {
            return self::$client;
        }

        $secret = Kilden_Settings::secret_key();
        if ($secret === '' || strpos($secret, 'wk_') === 0) {
            self::$client_failed = true;

            return null;
        }

        try {
            self::$client = new Client($secret, array(
                'host'      => Kilden_Settings::host(),
                'transport' => new Kilden_WP_Transport(),
                // A store's order hooks fire a handful of times per request
                // at most: flush small and rely on the shutdown hook.
                'flush_at'  => 20,
            ));
        } catch (\Exception $e) {
            // Contract: an analytics misconfiguration must never take a
            // storefront down. Log and disable for this request.
            error_log('kilden: client unavailable: ' . $e->getMessage());
            self::$client_failed = true;
        }

        return self::$client;
    }

    public static function signer(): ?IdentitySigner
    {
        $secret = Kilden_Settings::identity_secret();
        $kid = Kilden_Settings::identity_kid();
        if ($secret === '' || $kid === '') {
            return null;
        }

        try {
            return new IdentitySigner($secret, array('kid' => $kid));
        } catch (\Exception $e) {
            error_log('kilden: identity signer unavailable: ' . $e->getMessage());

            return null;
        }
    }

    public static function reset(): void
    {
        self::$client = null;
        self::$client_failed = false;
    }
}
