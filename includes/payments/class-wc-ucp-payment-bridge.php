<?php
/**
 * UCP Payment Bridge.
 * Bridges AP2 mandates and payment tokens to WooCommerce payment gateways.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Payment_Bridge {

    /**
     * Process an AP2 mandate through the appropriate WC gateway.
     *
     * @param WC_Order $order   WooCommerce order.
     * @param array    $mandate AP2 mandate data.
     * @return true|WP_Error
     */
    public function process_ap2_mandate( $order, $mandate ) {
        $payment_method = sanitize_text_field( $mandate['payment_method'] );

        // Extract payment credential from mandate.
        $credential = isset( $mandate['credential'] ) ? $mandate['credential'] : array();

        switch ( $payment_method ) {
            case 'stripe':
                return $this->process_stripe( $order, $credential );

            case 'paypal':
                return $this->process_paypal( $order, $credential );

            case 'woocommerce-payments':
                return $this->process_wc_payments( $order, $credential );

            default:
                return new WP_Error(
                    'unsupported_payment_method',
                    "Payment method '{$payment_method}' is not supported.",
                    array( 'status' => 400 )
                );
        }
    }

    /**
     * Process a payment token directly (non-AP2 flow).
     *
     * @param WC_Order $order WooCommerce order.
     * @param array    $token Payment token data.
     * @return true|WP_Error
     */
    public function process_token( $order, $token ) {
        if ( empty( $token['gateway'] ) || empty( $token['value'] ) ) {
            return new WP_Error(
                'invalid_payment_token',
                'Payment token must include gateway and value.',
                array( 'status' => 400 )
            );
        }

        $gateway    = isset( $token['gateway'] ) ? sanitize_text_field( $token['gateway'] ) : WC_UCP_Payment_Utils::detect_single_supported_gateway();
        $value      = sanitize_text_field( $token['value'] ?? '' );
        $credential = array( 'token' => $value );

        if ( isset( $token['intent_id'] ) ) {
            $credential['payment_intent_id'] = sanitize_text_field( $token['intent_id'] );
        }

        if ( isset( $token['order_id'] ) ) {
            $credential['order_id'] = sanitize_text_field( $token['order_id'] );
        }

        if ( ! $gateway ) {
            return new WP_Error(
                'invalid_payment_token',
                'Payment token is missing gateway metadata.',
                array( 'status' => 400 )
            );
        }

        switch ( $gateway ) {
            case 'stripe':
                return $this->process_stripe( $order, $credential );
            case 'paypal':
                return $this->process_paypal( $order, $credential );
            default:
                return new WP_Error(
                    'unsupported_gateway',
                    "Gateway '{$gateway}' is not supported for token payments.",
                    array( 'status' => 400 )
                );
        }
    }

    /**
     * Process payment via Stripe gateway.
     *
     * @param WC_Order $order      WooCommerce order.
     * @param array    $credential Payment credential.
     * @return true|WP_Error
     */
    private function process_stripe( $order, $credential ) {
        // Check if Stripe gateway is available.
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();

        $stripe_gateway = null;
        foreach ( array( 'stripe', 'stripe_cc' ) as $gw_id ) {
            if ( isset( $gateways[ $gw_id ] ) ) {
                $stripe_gateway = $gateways[ $gw_id ];
                break;
            }
        }

        if ( ! $stripe_gateway ) {
            return new WP_Error( 'stripe_unavailable', 'Stripe payment gateway is not available.', array( 'status' => 500 ) );
        }

        // Set payment method on order.
        $order->set_payment_method( $stripe_gateway->id );
        $order->set_payment_method_title( $stripe_gateway->get_title() );

        // Store Stripe token/payment intent for the gateway to process.
        if ( ! empty( $credential['payment_intent_id'] ) ) {
            $order->update_meta_data( '_stripe_intent_id', sanitize_text_field( $credential['payment_intent_id'] ) );
        } elseif ( ! empty( $credential['token'] ) && 0 === strpos( $credential['token'], 'pi_' ) ) {
            $order->update_meta_data( '_stripe_intent_id', sanitize_text_field( $credential['token'] ) );
        } elseif ( ! empty( $credential['token'] ) ) {
            $order->update_meta_data( '_stripe_source_id', sanitize_text_field( $credential['token'] ) );
        }

        $order->save();

        $intent_id = $order->get_meta( '_stripe_intent_id', true );
        if ( $intent_id ) {
            return $this->finalize_stripe_intent( $order, $intent_id );
        }

        // Attempt to process payment through the gateway.
        try {
            $result = $stripe_gateway->process_payment( $order->get_id() );

            if ( isset( $result['result'] ) && 'success' === $result['result'] ) {
                return true;
            }

            return new WP_Error(
                'stripe_payment_failed',
                'Stripe payment processing failed.',
                array( 'status' => 402 )
            );
        } catch ( \Exception $e ) {
            return new WP_Error(
                'stripe_error',
                'Stripe payment error: ' . $e->getMessage(),
                array( 'status' => 500 )
            );
        }
    }

    /**
     * Finalize/confirm a delegated Stripe PaymentIntent without invoking checkout notices.
     *
     * @param WC_Order $order     WooCommerce order.
     * @param string   $intent_id Stripe PaymentIntent ID.
     * @return true|WP_Error
     */
    private function finalize_stripe_intent( $order, $intent_id ) {
        if ( ! class_exists( 'WC_Stripe_API' ) ) {
            return new WP_Error(
                'stripe_unavailable',
                'Stripe payment gateway is not available.',
                array( 'status' => 500 )
            );
        }

        $intent = WC_Stripe_API::request( array(), 'payment_intents/' . $intent_id );
        if ( is_wp_error( $intent ) ) {
            return $intent;
        }

        $status = isset( $intent->status ) ? $intent->status : '';

        if ( 'requires_capture' === $status ) {
            $capture = WC_Stripe_API::request( array(), 'payment_intents/' . $intent_id . '/capture' );
            if ( is_wp_error( $capture ) ) {
                return $capture;
            }
            $intent = $capture;
            $status = isset( $intent->status ) ? $intent->status : $status;
        }

        if ( in_array( $status, array( 'succeeded', 'processing' ), true ) ) {
            $order->payment_complete( $intent_id );
            $order->add_order_note(
                sprintf(
                    /* translators: 1: PaymentIntent ID, 2: status */
                    __( 'Stripe PaymentIntent %1$s finalized via UCP (status: %2$s).', 'woocommerce-ucp' ),
                    $intent_id,
                    $status
                )
            );
            return true;
        }

        return new WP_Error(
            'stripe_payment_failed',
            sprintf( 'Stripe PaymentIntent %s returned status %s.', $intent_id, $status ),
            array( 'status' => 402 )
        );
    }

    /**
     * Process payment via PayPal gateway.
     *
     * @param WC_Order $order      WooCommerce order.
     * @param array    $credential Payment credential.
     * @return true|WP_Error
     */
    private function process_paypal( $order, $credential ) {
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();

        $paypal_gateway = null;
        foreach ( array( 'ppcp-gateway', 'paypal' ) as $gw_id ) {
            if ( isset( $gateways[ $gw_id ] ) ) {
                $paypal_gateway = $gateways[ $gw_id ];
                break;
            }
        }

        if ( ! $paypal_gateway ) {
            return new WP_Error( 'paypal_unavailable', 'PayPal payment gateway is not available.', array( 'status' => 500 ) );
        }

        $order->set_payment_method( $paypal_gateway->id );
        $order->set_payment_method_title( $paypal_gateway->get_title() );

        // Store PayPal order ID for the gateway.
        if ( ! empty( $credential['order_id'] ) ) {
            $order->update_meta_data( '_paypal_order_id', sanitize_text_field( $credential['order_id'] ) );
        }
        if ( ! empty( $credential['token'] ) ) {
            $order->update_meta_data( '_paypal_token', sanitize_text_field( $credential['token'] ) );
        }

        $order->save();

        try {
            $result = $paypal_gateway->process_payment( $order->get_id() );

            if ( isset( $result['result'] ) && 'success' === $result['result'] ) {
                return true;
            }

            return new WP_Error(
                'paypal_payment_failed',
                'PayPal payment processing failed.',
                array( 'status' => 402 )
            );
        } catch ( \Exception $e ) {
            return new WP_Error(
                'paypal_error',
                'PayPal payment error: ' . $e->getMessage(),
                array( 'status' => 500 )
            );
        }
    }

    /**
     * Process payment via WooCommerce Payments gateway.
     *
     * @param WC_Order $order      WooCommerce order.
     * @param array    $credential Payment credential.
     * @return true|WP_Error
     */
    private function process_wc_payments( $order, $credential ) {
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();

        if ( ! isset( $gateways['woocommerce_payments'] ) ) {
            return new WP_Error( 'wcpay_unavailable', 'WooCommerce Payments gateway is not available.', array( 'status' => 500 ) );
        }

        $gateway = $gateways['woocommerce_payments'];

        $order->set_payment_method( $gateway->id );
        $order->set_payment_method_title( $gateway->get_title() );

        if ( ! empty( $credential['token'] ) ) {
            $order->update_meta_data( '_wcpay_payment_method', sanitize_text_field( $credential['token'] ) );
        }

        $order->save();

        try {
            $result = $gateway->process_payment( $order->get_id() );

            if ( isset( $result['result'] ) && 'success' === $result['result'] ) {
                return true;
            }

            return new WP_Error(
                'wcpay_failed',
                'WooCommerce Payments processing failed.',
                array( 'status' => 402 )
            );
        } catch ( \Exception $e ) {
            return new WP_Error(
                'wcpay_error',
                'WooCommerce Payments error: ' . $e->getMessage(),
                array( 'status' => 500 )
            );
        }
    }
}
