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
        add_action( 'init', array( $this, 'add_rewrite_rules' ) );
        add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'handle_discovery_request' ) );
    }

    /**
     * Add rewrite rules for /.well-known/ucp.
     */
    public function add_rewrite_rules() {
        add_rewrite_rule(
            '^\.well-known/ucp/?$',
            'index.php?wc_ucp_discovery=1',
            'top'
        );
    }

    /**
     * Register custom query variable.
     *
     * @param array $vars Existing query vars.
     * @return array
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'wc_ucp_discovery';
        return $vars;
    }

    /**
     * Handle the discovery request.
     */
    public function handle_discovery_request() {
        if ( ! $this->is_discovery_request() ) {
            return;
        }

        // Check if UCP is enabled.
        if ( 'yes' !== get_option( 'wc_ucp_enabled', 'yes' ) ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            exit;
        }

        global $wp_query;
        $wp_query->is_404 = false;

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
     * Determine whether the current request targets /.well-known/ucp.
     *
     * This lets us serve the manifest even if rewrite rules have not been
     * flushed yet (common after manual deployments).
     *
     * @return bool
     */
    private function is_discovery_request() {
        if ( get_query_var( 'wc_ucp_discovery' ) ) {
            return true;
        }

        if ( empty( $_SERVER['REQUEST_URI'] ) ) {
            return false;
        }

        $request_path  = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
        $expected_path = wp_make_link_relative( home_url( '/.well-known/ucp' ) );

        if ( empty( $request_path ) || empty( $expected_path ) ) {
            return false;
        }

        $request_path  = trim( $request_path, '/' );
        $expected_path = trim( $expected_path, '/' );

        return $request_path === $expected_path;
    }

    /**
     * Build the UCP discovery manifest.
     *
     * @return array
     */
    public function build_manifest() {
        $site_url = site_url();
        $rest_url = rest_url( 'ucp/v1' );

        $profile = array(
            'ucp'      => array(
                'version' => WC_UCP_SPEC_VERSION,
                'spec'    => 'https://ucp.dev/' . WC_UCP_SPEC_VERSION . '/specification/overview/',
            ),
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

        $manifest = array(
            'profile' => $profile,
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
