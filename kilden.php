<?php
/**
 * Plugin Name: Kilden
 * Plugin URI: https://github.com/kildenhq/kilden-wp
 * Description: Kilden analytics for WordPress and WooCommerce — page analytics, verified identity and server-side revenue tracking that survives page caching.
 * Version: 0.1.0-alpha.3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Kilden
 * Author URI: https://kilden.io
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kilden
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KILDEN_WP_VERSION', '0.1.0-alpha.3');
define('KILDEN_WP_FILE', __FILE__);
define('KILDEN_WP_DIR', plugin_dir_path(__FILE__));

require KILDEN_WP_DIR . 'includes/vendor-kilden/autoload.php';
require KILDEN_WP_DIR . 'includes/class-kilden-settings.php';
require KILDEN_WP_DIR . 'includes/class-kilden-wp-transport.php';
require KILDEN_WP_DIR . 'includes/class-kilden-client-factory.php';
require KILDEN_WP_DIR . 'includes/class-kilden-snippet.php';
require KILDEN_WP_DIR . 'includes/class-kilden-identity.php';
require KILDEN_WP_DIR . 'includes/class-kilden-woocommerce.php';
require KILDEN_WP_DIR . 'includes/class-kilden-plugin.php';

add_action('plugins_loaded', array('Kilden_Plugin', 'boot'));
