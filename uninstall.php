<?php
/**
 * Uninstall script for Zoho Integration Plugin
 */

// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove plugin options
delete_option('zoho_integration_settings');
delete_option('zoho_token_data');

// Remove user meta (optional - uncomment if you want to remove newsletter subscription data)
// global $wpdb;
// $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'zoho_newsletter_subscription'");

// Clear any scheduled events
wp_clear_scheduled_hook('zoho_integration_cleanup');
