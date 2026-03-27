<?php
/**
 * UCP Discovery endpoint handler.
 * Serves the /.well-known/ucp manifest for AI agent discovery.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Discovery {

    public function __construct() {
        add_action( 'init', array( $this, 'handle_discovery_request' ), 1 );
    }

    /**
     * Handle the discovery request directly by checking the request URI.
     */
    public function handle_discovery_request() {
        if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
            return;
        }

        $request_uri = $_SERVER['REQUEST_URI'];

        // Check if this is a request for /.well-known/ucp
        if ( ! preg_match( '#^/\.well-known/ucp/?$#', $request_uri ) ) {
            return;
        }

        // Check if UCP is enabled.
        if ( 'yes' !== get_option( 'wc_ucp_enabled', 'yes' ) ) {
            status_header( 404 );
            exit;
        }

        // Only handle GET and OPTIONS requests
        if ( ! in_array( $_SERVER['REQUEST_METHOD'], array( 'GET', 'OPTIONS' ) ) ) {
            status_header( 405 );
            header( 'Allow: GET, OPTIONS' );
            exit;
        }

        // Handle OPTIONS request for CORS preflight
        if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
            status_header( 200 );
            header( 'Access-Control-Allow-Origin: *' );
            header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
            header( 'Access-Control-Allow-Headers: *' );
            exit;
        }

        $manifest = $this->build_manifest();

        status_header( 200 );
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Cache-Control: public, max-age=3600' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Access-Control-Allow-Origin: *' );
        header( 'Access-Control-Allow-Methods: GET, OPTIONS' );

        echo wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }

    /**
     * Build the UCP discovery manifest.
     *
     * @return array
     */
    public function build_manifest() {
        $site_url = site_url();
        $rest_url = rest_url( 'ucp/v1' );

        $manifest = array(
            'ucp'      => WC_UCP_SPEC_VERSION,
            'business' => array(
                'name' => get_bloginfo( 'name' ),
                'url'  => $site_url,
            ),
            'services' => array(
                array(
                    'id'         => 'dev.ucp.shopping',
                    'transports' => $this->get_transports( $rest_url ),
                    'capabilities' => $this->get_capabilities(),
                    'extensions'   => $this->get_extensions(),
                    'payment_handlers' => $this->get_payment_handlers(),
                ),
            ),
            'authentication' => $this->get_authentication( $rest_url ),
        );

        return apply_filters( 'wc_ucp_discovery_manifest', $manifest );
    }

    /**
     * Get available transport configurations.
     *
     * @param string $rest_url Base REST URL.
     * @return array
     */
    private function get_transports( $rest_url ) {
        $transports = array(
            array(
                'type'     => 'rest',
                'endpoint' => $rest_url,
            ),
            array(
                'type'     => 'mcp',
                'endpoint' => $rest_url . '/mcp',
            ),
        );

        return $transports;
    }

    /**
     * Get supported capabilities.
     *
     * @return array
     */
    private function get_capabilities() {
        $capabilities = array(
            array(
                'id'      => 'dev.ucp.shopping.checkout',
                'version' => WC_UCP_SPEC_VERSION,
                'spec'    => 'https://ucp.dev/' . WC_UCP_SPEC_VERSION . '/specification/checkout-rest/',
                'schema'  => 'https://ucp.dev/schemas/shopping/checkout.json',
            ),
        );

        return apply_filters( 'wc_ucp_capabilities', $capabilities );
    }

    /**
     * Get supported extensions.
     *
     * @return array
     */
    private function get_extensions() {
        $extensions = array(
            array(
                'id'      => 'dev.ucp.shopping.fulfillment',
                'extends' => 'dev.ucp.shopping.checkout',
                'version' => WC_UCP_SPEC_VERSION,
            ),
            array(
                'id'      => 'dev.ucp.shopping.discount',
                'extends' => 'dev.ucp.shopping.checkout',
                'version' => WC_UCP_SPEC_VERSION,
            ),
            array(
                'id'      => 'dev.ucp.shopping.order',
                'version' => WC_UCP_SPEC_VERSION,
            ),
        );

        return apply_filters( 'wc_ucp_extensions', $extensions );
    }

    /**
     * Get payment handlers from active WooCommerce gateways.
     *
     * @return array
     */
    private function get_payment_handlers() {
        $handlers = array();

        if ( ! function_exists( 'WC' ) ) {
            return $handlers;
        }

        $gateways = WC()->payment_gateways()->get_available_payment_gateways();

        $gateway_map = array(
            'stripe'                   => array( 'id' => 'stripe', 'type' => 'psp' ),
            'ppcp-gateway'             => array( 'id' => 'paypal', 'type' => 'psp' ),
            'paypal'                   => array( 'id' => 'paypal', 'type' => 'psp' ),
            'stripe_cc'               => array( 'id' => 'stripe', 'type' => 'psp' ),
            'woocommerce_payments'    => array( 'id' => 'woocommerce-payments', 'type' => 'psp' ),
        );

        $seen = array();

        foreach ( $gateways as $gateway_id => $gateway ) {
            if ( isset( $gateway_map[ $gateway_id ] ) && ! isset( $seen[ $gateway_map[ $gateway_id ]['id'] ] ) ) {
                $mapped = $gateway_map[ $gateway_id ];
                $handlers[] = array(
                    'id'   => $mapped['id'],
                    'type' => $mapped['type'],
                    'spec' => 'https://ucp.dev/payment-handlers/' . $mapped['id'],
                );
                $seen[ $mapped['id'] ] = true;
            }
        }

        if ( empty( $handlers ) ) {
            $handlers[] = array(
                'id'   => 'manual',
                'type' => 'escalation',
                'spec' => site_url( '/checkout/' ),
            );
        }

        return apply_filters( 'wc_ucp_payment_handlers', $handlers );
    }

    /**
     * Get authentication configuration.
     *
     * @param string $rest_url Base REST URL.
     * @return array
     */
    private function get_authentication( $rest_url ) {
        return array(
            'methods' => array( 'api_key', 'oauth2' ),
            'api_key' => array(
                'header' => 'X-API-Key',
            ),
            'oauth2'  => array(
                'authorization_endpoint' => $rest_url . '/oauth/authorize',
                'token_endpoint'         => $rest_url . '/oauth/token',
                'revocation_endpoint'    => $rest_url . '/oauth/revoke',
                'scopes_supported'       => array( 'checkout', 'orders', 'profile' ),
                'grant_types_supported'  => array( 'authorization_code', 'refresh_token' ),
                'code_challenge_methods_supported' => array( 'S256' ),
            ),
        );
    }
}
