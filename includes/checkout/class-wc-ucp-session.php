<?php
/**
 * UCP Checkout session state machine management.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Session {

    const STATUS_INCOMPLETE          = 'incomplete';
    const STATUS_REQUIRES_ESCALATION = 'requires_escalation';
    const STATUS_READY_FOR_COMPLETE  = 'ready_for_complete';
    const STATUS_COMPLETED           = 'completed';
    const STATUS_CANCELED            = 'canceled';

    const SESSION_EXPIRY_MINUTES = 30;

    /**
     * Create a new checkout session in the database.
     *
     * @param int    $wc_order_id WooCommerce order ID.
     * @param int    $customer_id Customer ID.
     * @param string $currency    Currency code.
     * @param int    $api_key_id  API key ID used for auth (optional).
     * @return string Session key (chk_*).
     */
    public static function create( $wc_order_id, $customer_id, $currency = 'USD', $api_key_id = 0 ) {
        global $wpdb;

        $session_key = 'chk_' . wp_generate_password( 24, false );
        $expires_at  = gmdate( 'Y-m-d H:i:s', time() + ( self::SESSION_EXPIRY_MINUTES * 60 ) );

        $wpdb->insert(
            $wpdb->prefix . 'ucp_checkout_sessions',
            array(
                'session_key' => $session_key,
                'wc_order_id' => $wc_order_id,
                'customer_id' => $customer_id,
                'api_key_id'  => $api_key_id,
                'status'      => self::STATUS_INCOMPLETE,
                'currency'    => $currency,
                'session_data' => wp_json_encode( array() ),
                'expires_at'  => $expires_at,
            ),
            array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
        );

        return $session_key;
    }

    /**
     * Get a checkout session by its key.
     *
     * @param string $session_key Session key.
     * @return object|null Session row or null.
     */
    public static function get( $session_key ) {
        global $wpdb;

        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ucp_checkout_sessions WHERE session_key = %s",
                $session_key
            )
        );

        if ( ! $session ) {
            return null;
        }

        // Check expiry.
        if ( strtotime( $session->expires_at ) < time() && ! in_array( $session->status, array( self::STATUS_COMPLETED, self::STATUS_CANCELED ), true ) ) {
            self::update_status( $session_key, self::STATUS_CANCELED );
            $session->status = self::STATUS_CANCELED;
        }

        return $session;
    }

    /**
     * Update session status.
     *
     * @param string $session_key Session key.
     * @param string $new_status  New status.
     * @return bool
     */
    public static function update_status( $session_key, $new_status ) {
        global $wpdb;

        if ( ! in_array( $new_status, WC_UCP_Validator::VALID_STATUSES, true ) ) {
            return false;
        }

        return (bool) $wpdb->update(
            $wpdb->prefix . 'ucp_checkout_sessions',
            array( 'status' => $new_status ),
            array( 'session_key' => $session_key ),
            array( '%s' ),
            array( '%s' )
        );
    }

    /**
     * Update session data JSON.
     *
     * @param string $session_key  Session key.
     * @param array  $session_data Data to store.
     * @return bool
     */
    public static function update_data( $session_key, $session_data ) {
        global $wpdb;

        return (bool) $wpdb->update(
            $wpdb->prefix . 'ucp_checkout_sessions',
            array( 'session_data' => wp_json_encode( $session_data ) ),
            array( 'session_key' => $session_key ),
            array( '%s' ),
            array( '%s' )
        );
    }

    /**
     * Extend session expiry.
     *
     * @param string $session_key Session key.
     */
    public static function extend_expiry( $session_key ) {
        global $wpdb;

        $expires_at = gmdate( 'Y-m-d H:i:s', time() + ( self::SESSION_EXPIRY_MINUTES * 60 ) );

        $wpdb->update(
            $wpdb->prefix . 'ucp_checkout_sessions',
            array( 'expires_at' => $expires_at ),
            array( 'session_key' => $session_key ),
            array( '%s' ),
            array( '%s' )
        );
    }

    /**
     * Verify session ownership by customer.
     *
     * @param object $session    Session row.
     * @param int    $customer_id Customer ID to check.
     * @return bool
     */
    public static function verify_ownership( $session, $customer_id ) {
        return absint( $session->customer_id ) === absint( $customer_id );
    }

    /**
     * Determine checkout status based on WC order completeness.
     *
     * @param WC_Order $order WooCommerce order.
     * @return array Status and messages.
     */
    public static function evaluate_order_completeness( $order ) {
        $messages = array();
        $status   = self::STATUS_INCOMPLETE;

        // Check line items.
        if ( count( $order->get_items() ) === 0 ) {
            $messages[] = array(
                'type'     => 'validation',
                'code'     => 'missing_field',
                'path'     => 'line_items',
                'content'  => 'At least one line item is required.',
                'severity' => 'recoverable',
            );
        }

        // Check buyer email.
        if ( empty( $order->get_billing_email() ) ) {
            $messages[] = array(
                'type'     => 'validation',
                'code'     => 'missing_field',
                'path'     => 'buyer.email',
                'content'  => 'Buyer email address is required.',
                'severity' => 'recoverable',
            );
        }

        // Check buyer name.
        if ( empty( $order->get_billing_first_name() ) ) {
            $messages[] = array(
                'type'     => 'validation',
                'code'     => 'missing_field',
                'path'     => 'buyer.first_name',
                'content'  => 'Buyer first name is required.',
                'severity' => 'recoverable',
            );
        }

        if ( empty( $order->get_billing_last_name() ) ) {
            $messages[] = array(
                'type'     => 'validation',
                'code'     => 'missing_field',
                'path'     => 'buyer.last_name',
                'content'  => 'Buyer last name is required.',
                'severity' => 'recoverable',
            );
        }

        // Check if any item needs shipping.
        $needs_shipping = false;
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product && $product->needs_shipping() ) {
                $needs_shipping = true;
                break;
            }
        }

        if ( $needs_shipping ) {
            // Check shipping address.
            if ( empty( $order->get_shipping_address_1() ) || empty( $order->get_shipping_city() ) || empty( $order->get_shipping_country() ) ) {
                $messages[] = array(
                    'type'     => 'validation',
                    'code'     => 'missing_field',
                    'path'     => 'buyer.shipping_address',
                    'content'  => 'Shipping address is required for physical products.',
                    'severity' => 'recoverable',
                );
            }

            // Check fulfillment selection.
            $shipping_methods = $order->get_shipping_methods();
            if ( empty( $shipping_methods ) && ! empty( $order->get_shipping_address_1() ) ) {
                $messages[] = array(
                    'type'     => 'validation',
                    'code'     => 'missing_field',
                    'path'     => 'fulfillment',
                    'content'  => 'A fulfillment method must be selected.',
                    'severity' => 'recoverable',
                );
            }
        }

        if ( empty( $messages ) ) {
            $status = self::STATUS_READY_FOR_COMPLETE;
        }

        return array(
            'status'   => $status,
            'messages' => $messages,
        );
    }

    /**
     * Clean up expired sessions.
     */
    public static function cleanup_expired() {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}ucp_checkout_sessions SET status = %s WHERE expires_at < %s AND status NOT IN (%s, %s)",
                self::STATUS_CANCELED,
                gmdate( 'Y-m-d H:i:s' ),
                self::STATUS_COMPLETED,
                self::STATUS_CANCELED
            )
        );
    }
}
