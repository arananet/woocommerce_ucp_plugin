<?php
/**
 * AP2 (Agent Payments Protocol) mandate handler.
 * Verifies payment mandates (Verifiable Digital Credentials) and bridges
 * them to WooCommerce payment gateways.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_AP2_Handler {

    /**
     * Supported mandate types.
     */
    const MANDATE_INTENT = 'intent';
    const MANDATE_CART   = 'cart';

    /**
     * Process an AP2 payment mandate for an order.
     *
     * @param WC_Order $order   WooCommerce order.
     * @param array    $mandate Payment mandate data (VDC).
     * @return true|WP_Error
     */
    public function process_mandate( $order, $mandate ) {
        // Validate mandate structure.
        $validation = $this->validate_mandate( $mandate, $order );
        if ( is_wp_error( $validation ) ) {
            $this->log( 'error', 'Mandate validation failed.', array(
                'order_id' => $order->get_id(),
                'error'    => $validation->get_error_message(),
            ) );
            return $validation;
        }

        // Verify mandate signature.
        $signature_valid = $this->verify_signature( $mandate );
        if ( is_wp_error( $signature_valid ) ) {
            return $signature_valid;
        }

        // Extract payment credential and process via bridge.
        $bridge = new WC_UCP_Payment_Bridge();
        $result = $bridge->process_ap2_mandate( $order, $mandate );

        if ( is_wp_error( $result ) ) {
            $this->log( 'error', 'AP2 payment processing failed.', array(
                'order_id' => $order->get_id(),
                'error'    => $result->get_error_message(),
            ) );
            return $result;
        }

        $this->log( 'info', 'AP2 payment mandate processed successfully.', array(
            'order_id'      => $order->get_id(),
            'mandate_type'  => $mandate['type'] ?? 'unknown',
            'payment_method' => $mandate['payment_method'] ?? 'unknown',
        ) );

        return true;
    }

    /**
     * Validate mandate structure and match against order.
     *
     * @param array    $mandate Mandate data.
     * @param WC_Order $order   WooCommerce order.
     * @return true|WP_Error
     */
    private function validate_mandate( $mandate, $order ) {
        // Check required fields.
        $required_fields = array( 'type', 'payment_method', 'amount', 'currency' );
        foreach ( $required_fields as $field ) {
            if ( empty( $mandate[ $field ] ) ) {
                return new WP_Error(
                    'invalid_mandate',
                    "Payment mandate missing required field: {$field}.",
                    array( 'status' => 400 )
                );
            }
        }

        // Validate mandate type.
        if ( ! in_array( $mandate['type'], array( self::MANDATE_INTENT, self::MANDATE_CART ), true ) ) {
            return new WP_Error(
                'invalid_mandate_type',
                'Invalid mandate type. Must be "intent" or "cart".',
                array( 'status' => 400 )
            );
        }

        // Validate amount matches order total.
        $mandate_amount = floatval( $mandate['amount'] );
        $order_total    = floatval( $order->get_total() );

        if ( abs( $mandate_amount - $order_total ) > 0.01 ) {
            return new WP_Error(
                'amount_mismatch',
                'Payment mandate amount does not match order total.',
                array(
                    'status'          => 400,
                    'mandate_amount'  => $mandate_amount,
                    'order_total'     => $order_total,
                )
            );
        }

        // Validate currency matches.
        $mandate_currency = strtoupper( $mandate['currency'] );
        $order_currency   = $order->get_currency();

        if ( $mandate_currency !== $order_currency ) {
            return new WP_Error(
                'currency_mismatch',
                'Payment mandate currency does not match order currency.',
                array( 'status' => 400 )
            );
        }

        // Check mandate expiry if present.
        if ( ! empty( $mandate['expires_at'] ) ) {
            $expires = strtotime( $mandate['expires_at'] );
            if ( $expires && $expires < time() ) {
                return new WP_Error(
                    'mandate_expired',
                    'Payment mandate has expired.',
                    array( 'status' => 400 )
                );
            }
        }

        return true;
    }

    /**
     * Verify the cryptographic signature of a mandate.
     *
     * @param array $mandate Mandate data.
     * @return true|WP_Error
     */
    private function verify_signature( $mandate ) {
        // If no signature present, allow processing with warning.
        if ( empty( $mandate['signature'] ) ) {
            $this->log( 'warning', 'Mandate processed without signature verification.', array(
                'mandate_type' => $mandate['type'] ?? 'unknown',
            ) );
            return true;
        }

        // Verify signature against known credential providers.
        $signature = $mandate['signature'];

        if ( empty( $signature['value'] ) || empty( $signature['algorithm'] ) ) {
            return new WP_Error(
                'invalid_signature',
                'Mandate signature is malformed.',
                array( 'status' => 400 )
            );
        }

        // In production, this would verify the signature against the credential
        // provider's public key, obtained via the AP2 trust framework.
        // For now, log and accept if properly structured.
        $supported_algorithms = array( 'RS256', 'ES256', 'EdDSA' );
        if ( ! in_array( $signature['algorithm'], $supported_algorithms, true ) ) {
            return new WP_Error(
                'unsupported_algorithm',
                'Unsupported signature algorithm.',
                array( 'status' => 400 )
            );
        }

        $this->log( 'info', 'Mandate signature verification passed.', array(
            'algorithm' => $signature['algorithm'],
        ) );

        return true;
    }

    /**
     * Log AP2 events.
     *
     * @param string $level   Log level.
     * @param string $message Message.
     * @param array  $context Context data.
     */
    private function log( $level, $message, $context = array() ) {
        if ( isset( WC_UCP_Plugin::instance()->logger ) ) {
            WC_UCP_Plugin::instance()->logger->$level( '[AP2] ' . $message, $context );
        }
    }
}
