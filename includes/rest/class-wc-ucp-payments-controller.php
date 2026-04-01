<?php
/**
 * Payments helper endpoints (PSP token delegation).
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Payments_Controller extends WC_UCP_REST_Controller {

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'payments';

    /**
     * Register REST routes.
     */
    public function register_routes() {
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/intent', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'create_payment_intent' ),
                'permission_callback' => array( $this, 'check_auth_permission' ),
            ),
        ) );
    }

    /**
     * Create a delegated PSP intent/order token the agent can pass back during /complete.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function create_payment_intent( $request ) {
        $customer_id = $this->get_authenticated_customer( $request );
        if ( is_wp_error( $customer_id ) ) {
            return $customer_id;
        }

        $amount = floatval( $request->get_param( 'amount' ) );
        if ( $amount <= 0 ) {
            return $this->ucp_error( 'invalid_amount', 'Amount must be greater than zero.', 400 );
        }

        $currency = strtoupper( sanitize_text_field( $request->get_param( 'currency' ) ?: get_woocommerce_currency() ) );

        $requested_gateway = sanitize_text_field( $request->get_param( 'gateway' ) );
        $available         = WC_UCP_Payment_Utils::get_supported_gateways();

        if ( empty( $available ) ) {
            return $this->ucp_error( 'gateway_unavailable', 'No supported payment gateways are active.', 503 );
        }

        $selected_gateway = null;
        if ( $requested_gateway ) {
            foreach ( $available as $wc_gateway => $handler ) {
                if ( $handler === $requested_gateway ) {
                    $selected_gateway = $handler;
                    break;
                }
            }
            if ( ! $selected_gateway ) {
                return $this->ucp_error( 'unsupported_gateway', 'Requested gateway is not available.', 400 );
            }
        } else {
            $selected_gateway = WC_UCP_Payment_Utils::detect_single_supported_gateway();
            if ( ! $selected_gateway ) {
                return $this->ucp_error( 'gateway_required', 'Multiple gateways are active. Specify the `gateway` parameter.', 400 );
            }
        }

        switch ( $selected_gateway ) {
            case 'stripe':
                $result = $this->create_stripe_intent( $amount, $currency, $customer_id );
                break;
            default:
                $result = $this->ucp_error(
                    'gateway_not_implemented',
                    sprintf( 'Gateway "%s" is not yet supported for delegated intents.', $selected_gateway ),
                    501
                );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return $this->ucp_response( $result, 201 );
    }

    /**
     * Create a Stripe Payment Intent leveraging the active WooCommerce Stripe gateway.
     *
     * @param float $amount       Order total.
     * @param string $currency    Currency code.
     * @param int $customer_id    WooCommerce customer ID.
     * @return array|WP_Error
     */
    private function create_stripe_intent( $amount, $currency, $customer_id ) {
        if ( ! class_exists( 'WC_Stripe_API' ) ) {
            return $this->ucp_error( 'stripe_unavailable', 'Stripe gateway is not available.', 503 );
        }

        if ( class_exists( 'WC_Stripe_Helper' ) && method_exists( 'WC_Stripe_Helper', 'get_stripe_amount' ) ) {
            $stripe_amount = WC_Stripe_Helper::get_stripe_amount( $amount, $currency );
        } else {
            $precision     = in_array( $currency, array( 'JPY', 'KRW', 'VND' ), true ) ? 0 : 2;
            $stripe_amount = (int) round( $amount * pow( 10, $precision ) );
        }

        $stripe_customer_id = get_user_meta( $customer_id, '_stripe_customer_id', true );

        $body = array(
            'amount'               => $stripe_amount,
            'currency'             => strtolower( $currency ),
            'payment_method_types' => array( 'card' ),
            'confirmation_method'  => 'automatic',
            'capture_method'       => 'automatic',
            'metadata'             => array(
                'ucp'         => '1',
                'customer_id' => $customer_id,
            ),
        );

        if ( $stripe_customer_id ) {
            $body['customer'] = $stripe_customer_id;
        }

        $default_payment_method = $this->get_default_stripe_payment_method_id( $customer_id );
        if ( $default_payment_method ) {
            $body['payment_method'] = $default_payment_method;
            $body['confirm']        = true;
            $body['off_session']    = true;
        }

        $intent = WC_Stripe_API::request( $body, 'payment_intents' );

        if ( is_wp_error( $intent ) ) {
            return $intent;
        }

        if ( empty( $intent->id ) ) {
            return $this->ucp_error( 'stripe_intent_failed', 'Stripe did not return a valid PaymentIntent.', 500 );
        }

        $expires_at = ! empty( $intent->created ) ? intval( $intent->created ) + 900 : null;

        return array(
            'payment_method' => 'stripe',
            'payment_token'  => array(
                'gateway'   => 'stripe',
                'value'     => $intent->id,
                'intent_id' => $intent->id,
            ),
            'client_secret' => isset( $intent->client_secret ) ? $intent->client_secret : null,
            'status'        => isset( $intent->status ) ? $intent->status : 'requires_payment_method',
            'expires_at'    => $expires_at,
        );
    }

    /**
     * Attempt to locate the customer's default Stripe payment method/token.
     *
     * @param int $customer_id WooCommerce customer ID.
     * @return string|null
     */
    private function get_default_stripe_payment_method_id( $customer_id ) {
        if ( ! class_exists( 'WC_Payment_Tokens' ) ) {
            return null;
        }

        $token = WC_Payment_Tokens::get_customer_default_token( $customer_id );
        if ( $token && in_array( $token->get_gateway_id(), array( 'stripe', 'stripe_cc' ), true ) ) {
            return $token->get_token();
        }

        $customer_tokens = WC_Payment_Tokens::get_customer_tokens( $customer_id, 'stripe' );
        if ( empty( $customer_tokens ) ) {
            $customer_tokens = WC_Payment_Tokens::get_customer_tokens( $customer_id, 'stripe_cc' );
        }

        if ( ! empty( $customer_tokens ) ) {
            $first = reset( $customer_tokens );
            if ( $first instanceof WC_Payment_Token ) {
                return $first->get_token();
            }
        }

        return null;
    }
}
