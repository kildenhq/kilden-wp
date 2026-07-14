<?php
/**
 * Removes everything the plugin stored. Event data lives in your Kilden
 * project, not in WordPress — uninstalling the plugin never touches it.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('kilden_settings');
