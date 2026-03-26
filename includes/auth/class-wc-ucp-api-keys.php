<?php
/**
 * UCP API Key management.
 * Handles generation, validation, and revocation of API keys.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_API_Keys {

    /**
     * Generate a new API key.
     *
     * @param string $label       Human-readable label.
     * @param string $permissions Permission level: read, write, read_write.
     * @param int    $customer_id Associated customer ID (optional).
     * @return array Key data including the raw key (only shown once).
     */
    public static function generate( $label, $permissions = 'read_write', $customer_id = 0 ) {
        global $wpdb;

        $raw_key    = 'ucp_' . wp_generate_password( 40, false );
        $key_hash   = hash( 'sha256', $raw_key );
        $key_prefix = substr( $raw_key, 0, 7 );

        $wpdb->insert(
            $wpdb->prefix . 'ucp_api_keys',
            array(
                'label'       => sanitize_text_field( $label ),
                'key_hash'    => $key_hash,
                'key_prefix'  => $key_prefix,
                'permissions' => sanitize_text_field( $permissions ),
                'customer_id' => absint( $customer_id ),
            ),
            array( '%s', '%s', '%s', '%s', '%d' )
        );

        return array(
            'id'          => $wpdb->insert_id,
            'key'         => $raw_key,
            'key_prefix'  => $key_prefix,
            'label'       => $label,
            'permissions' => $permissions,
            'customer_id' => $customer_id,
        );
    }

    /**
     * Validate an API key and return associated data.
     *
     * @param string $raw_key Raw API key.
     * @return array|WP_Error
     */
    public function validate( $raw_key ) {
        global $wpdb;

        $key_hash = hash( 'sha256', $raw_key );

        $key = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ucp_api_keys WHERE key_hash = %s AND revoked = 0",
                $key_hash
            )
        );

        if ( ! $key ) {
            return new WP_Error(
                'ucp_invalid_api_key',
                'Invalid or revoked API key.',
                array( 'status' => 401 )
            );
        }

        // Update last used timestamp.
        $wpdb->update(
            $wpdb->prefix . 'ucp_api_keys',
            array( 'last_used_at' => current_time( 'mysql', true ) ),
            array( 'id' => $key->id ),
            array( '%s' ),
            array( '%d' )
        );

        return array(
            'customer_id' => absint( $key->customer_id ),
            'permissions' => $key->permissions,
            'api_key_id'  => absint( $key->id ),
            'method'      => 'api_key',
        );
    }

    /**
     * Revoke an API key.
     *
     * @param int $key_id Key ID.
     * @return bool
     */
    public static function revoke( $key_id ) {
        global $wpdb;

        return (bool) $wpdb->update(
            $wpdb->prefix . 'ucp_api_keys',
            array( 'revoked' => 1 ),
            array( 'id' => absint( $key_id ) ),
            array( '%d' ),
            array( '%d' )
        );
    }

    /**
     * List all API keys.
     *
     * @param bool $include_revoked Whether to include revoked keys.
     * @return array
     */
    public static function list_all( $include_revoked = false ) {
        global $wpdb;

        $where = $include_revoked ? '' : 'WHERE revoked = 0';

        return $wpdb->get_results(
            "SELECT id, label, key_prefix, permissions, customer_id, created_at, last_used_at, revoked FROM {$wpdb->prefix}ucp_api_keys {$where} ORDER BY created_at DESC"
        );
    }
}
