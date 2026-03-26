<?php
/**
 * Plugin activation handler. Creates database tables and rewrite rules.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Activator {

    /**
     * Run on plugin activation.
     */
    public static function activate() {
        self::create_tables();
        self::add_rewrite_rules();
        flush_rewrite_rules();

        update_option( 'wc_ucp_db_version', WC_UCP_VERSION );
    }

    /**
     * Create custom database tables.
     */
    private static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = array();

        // Checkout sessions table.
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ucp_checkout_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_key VARCHAR(64) NOT NULL,
            wc_order_id BIGINT UNSIGNED DEFAULT NULL,
            customer_id BIGINT UNSIGNED DEFAULT NULL,
            api_key_id BIGINT UNSIGNED DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'incomplete',
            currency VARCHAR(3) NOT NULL DEFAULT 'USD',
            session_data LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY session_key (session_key),
            KEY wc_order_id (wc_order_id),
            KEY customer_id (customer_id),
            KEY status (status),
            KEY expires_at (expires_at)
        ) {$charset_collate};";

        // API keys table.
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ucp_api_keys (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            label VARCHAR(200) NOT NULL,
            key_hash VARCHAR(64) NOT NULL,
            key_prefix VARCHAR(7) NOT NULL,
            permissions VARCHAR(20) NOT NULL DEFAULT 'read',
            customer_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME DEFAULT NULL,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY key_hash (key_hash),
            KEY customer_id (customer_id)
        ) {$charset_collate};";

        // OAuth tokens table.
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ucp_oauth_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id VARCHAR(64) NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            access_token_hash VARCHAR(64) NOT NULL,
            refresh_token_hash VARCHAR(64) NOT NULL,
            scope VARCHAR(255) NOT NULL DEFAULT 'checkout',
            expires_at DATETIME NOT NULL,
            refresh_expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY access_token_hash (access_token_hash),
            KEY refresh_token_hash (refresh_token_hash),
            KEY client_id (client_id),
            KEY customer_id (customer_id)
        ) {$charset_collate};";

        // OAuth authorization codes table.
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ucp_oauth_codes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code_hash VARCHAR(64) NOT NULL,
            client_id VARCHAR(64) NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            redirect_uri VARCHAR(500) NOT NULL,
            scope VARCHAR(255) NOT NULL DEFAULT 'checkout',
            code_challenge VARCHAR(128) DEFAULT NULL,
            code_challenge_method VARCHAR(10) DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY code_hash (code_hash)
        ) {$charset_collate};";

        // OAuth clients table.
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ucp_oauth_clients (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id VARCHAR(64) NOT NULL,
            client_secret_hash VARCHAR(64) NOT NULL,
            name VARCHAR(200) NOT NULL,
            redirect_uris TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY client_id (client_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach ( $sql as $query ) {
            dbDelta( $query );
        }
    }

    /**
     * Add rewrite rules for /.well-known/ucp.
     */
    private static function add_rewrite_rules() {
        add_rewrite_rule(
            '^\.well-known/ucp/?$',
            'index.php?wc_ucp_discovery=1',
            'top'
        );
    }
}
