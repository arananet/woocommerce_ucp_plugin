<?php
/**
 * Uninstall handler.
 * Runs when the plugin is deleted from WordPress admin.
 * Cleans up all plugin data including database tables and options.
 *
 * @package WooCommerce_UCP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Remove custom tables.
$tables = array(
    $wpdb->prefix . 'ucp_checkout_sessions',
    $wpdb->prefix . 'ucp_api_keys',
    $wpdb->prefix . 'ucp_oauth_tokens',
    $wpdb->prefix . 'ucp_oauth_codes',
    $wpdb->prefix . 'ucp_oauth_clients',
);

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Remove plugin options.
delete_option( 'wc_ucp_settings' );
delete_option( 'wc_ucp_db_version' );
delete_option( 'wc_ucp_enabled' );
delete_option( 'wc_ucp_public_catalog' );
delete_option( 'wc_ucp_rate_limit_authenticated' );
delete_option( 'wc_ucp_rate_limit_unauthenticated' );
delete_option( 'wc_ucp_session_expiry' );
delete_option( 'wc_ucp_cors_origins' );
delete_option( 'wc_ucp_cap_checkout' );
delete_option( 'wc_ucp_cap_fulfillment' );
delete_option( 'wc_ucp_cap_discount' );
delete_option( 'wc_ucp_cap_mcp' );

// Clean up transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ucp_%' OR option_name LIKE '_transient_timeout_ucp_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Remove UCP meta from orders.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_ucp_session', '_ucp_version')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Flush rewrite rules.
flush_rewrite_rules();
