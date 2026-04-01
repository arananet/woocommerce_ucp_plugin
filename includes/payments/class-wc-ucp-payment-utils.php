<?php
/**
 * Utility helpers for UCP payment handling.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Payment_Utils {

	/**
	 * Normalize payment payloads from agents (handles nested objects, shorthand tokens).
	 *
	 * @param array|null $payload Raw JSON payload.
	 * @return array
	 */
	public static function normalize_payment_payload( $payload ) {
		if ( empty( $payload ) || ! is_array( $payload ) ) {
			return array();
		}

		if ( isset( $payload['payment'] ) && is_array( $payload['payment'] ) ) {
			$payload = $payload['payment'];
		}

        if ( isset( $payload['payment_token'] ) && is_string( $payload['payment_token'] ) ) {
            $payload['payment_token'] = self::wrap_payment_token_string( $payload['payment_token'] );
        }

        return $payload;
    }

	/**
	 * Wrap a token string with gateway metadata when agents send shorthand payloads.
	 *
	 * @param string $token Token value from agent.
	 * @return array
	 */
	public static function wrap_payment_token_string( $token ) {
        $token   = sanitize_text_field( $token );
        $wrapped = array(
            'value' => $token,
        );

        $detected_gateway = self::detect_gateway_from_token_value( $token );
        if ( $detected_gateway ) {
            $wrapped['gateway'] = $detected_gateway;
            return $wrapped;
        }

        $default_gateway = self::detect_single_supported_gateway();
        if ( $default_gateway ) {
            $wrapped['gateway'] = $default_gateway;
        }

        return $wrapped;
    }

	/**
	 * Detect a single supported payment gateway to use as default.
	 *
	 * @return string|null
	 */
	public static function detect_single_supported_gateway() {
		$gateways = self::get_supported_gateways();
		if ( 1 === count( $gateways ) ) {
			return array_shift( $gateways );
		}

		return null;
	}

	/**
	 * Return supported payment gateways keyed by WooCommerce gateway ID => handler slug.
	 *
	 * @return array
	 */
	public static function get_supported_gateways() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return array();
		}

		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		if ( empty( $gateways ) ) {
			return array();
		}

		$supported = array(
			'stripe'               => 'stripe',
			'stripe_cc'            => 'stripe',
			'ppcp-gateway'         => 'paypal',
			'paypal'               => 'paypal',
			'woocommerce_payments' => 'woocommerce-payments',
		);

		$available = array();
		foreach ( $gateways as $gateway_id => $gateway ) {
			if ( isset( $supported[ $gateway_id ] ) ) {
				$available[ $gateway_id ] = $supported[ $gateway_id ];
			}
		}

        return $available;
    }

    /**
     * Infer gateway from token prefix patterns (best-effort).
     *
     * @param string $token Token value.
     * @return string|null
     */
    private static function detect_gateway_from_token_value( $token ) {
        $prefix = substr( $token, 0, 6 );

        $stripe_prefixes = array( 'pm_', 'pi_', 'tok_', 'seti_', 'src_' );
        foreach ( $stripe_prefixes as $stripe_prefix ) {
            if ( 0 === strpos( $token, $stripe_prefix ) ) {
                return 'stripe';
            }
        }

        $paypal_prefixes = array( 'PAYID-', 'PAY-', 'EC-', 'I-', 'BA-' );
        foreach ( $paypal_prefixes as $paypal_prefix ) {
            if ( 0 === strpos( $token, $paypal_prefix ) ) {
                return 'paypal';
            }
        }

        return null;
    }
}
