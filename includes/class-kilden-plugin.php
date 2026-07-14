<?php
/**
 * Wires the pieces to WordPress. Kept boring on purpose: every feature is
 * an independent class with a register() guard, so a half-configured
 * plugin degrades feature by feature instead of fatally.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kilden_Plugin
{
    public static function boot(): void
    {
        if (is_admin()) {
            Kilden_Settings::register();
        }

        Kilden_Snippet::register();
        Kilden_Identity::register();
        Kilden_WooCommerce::register();
    }
}
