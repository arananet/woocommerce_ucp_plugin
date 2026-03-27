<?php
/**
 * UCP Discovery endpoint handler.
 * Serves the /.well-known/ucp manifest for AI agent discovery.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Discovery {

    /**
     * Requested manifest version (draft or dated) derived from the request URI.
     *
     * @var string|null
     */
    private $profile_version = null;

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
        add_rewrite_rule(
            '^\.well-known/ucp/([^/]+)/?$',
            'index.php?wc_ucp_discovery=1&wc_ucp_profile_version=$matches[1]',
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
        $vars[] = 'wc_ucp_profile_version';
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
            $this->profile_version = get_query_var( 'wc_ucp_profile_version', null );
            return true;
        }

        if ( empty( $_SERVER['REQUEST_URI'] ) ) {
            return false;
        }

        $request_path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
        $request_path = trim( $request_path, '/' );

        if ( empty( $request_path ) ) {
            return false;
        }

        $expected = trim( wp_make_link_relative( home_url( '/.well-known/ucp' ) ), '/' );

        if ( $request_path === $expected ) {
            return true;
        }

        if ( preg_match( '#^' . preg_quote( $expected, '#' ) . '/([^/]+)$#', $request_path, $matches ) ) {
            $this->profile_version = sanitize_title_with_dashes( $matches[1] );
            return true;
        }

        return false;
    }

    /**
     * Build the UCP discovery manifest.
     *
     * @return array
     */
    public function build_manifest() {
        $site_url = site_url();
        $rest_url = rest_url( 'ucp/v1' );

        $profile_version = $this->profile_version ?: WC_UCP_SPEC_VERSION;

        $capabilities     = $this->get_capability_map( $profile_version );
        $payment_handlers = $this->get_payment_handler_map( $profile_version );

        $manifest = array(
            'ucp' => array(
                'version'            => $profile_version,
                'spec'               => 'https://ucp.dev/' . $profile_version . '/specification/overview/',
                'supported_versions' => $this->get_supported_versions(),
                'services'           => $this->get_service_map( $rest_url, $profile_version, $capabilities, $payment_handlers ),
                'capabilities'       => $capabilities,
                'payment_handlers'   => $payment_handlers,
            ),
            'business' => array(
                'name' => get_bloginfo( 'name' ),
                'url'  => $site_url,
            ),
            'authentication' => $this->get_authentication( $rest_url ),
        );

        return apply_filters( 'wc_ucp_discovery_manifest', $manifest );
    }

    /**
     * Map the supported UCP versions to manifests.
     *
     * @return array
     */
    private function get_supported_versions() {
        $base = trailingslashit( home_url( '/.well-known/ucp' ) );

        $versions = array(
            'draft'              => $base . 'draft',
            WC_UCP_SPEC_VERSION => $base . WC_UCP_SPEC_VERSION,
        );

        return apply_filters( 'wc_ucp_supported_versions', $versions );
    }

    /**
     * Build the services map, keyed by service id.
     *
     * @param string $rest_url Base REST URL.
     * @param array  $capabilities Capability map keyed by capability id.
     * @param array  $payment_handlers Payment handler map keyed by handler id.
     * @return array
     */
    private function get_service_map( $rest_url, $version, $capabilities, $payment_handlers ) {
        $spec = 'https://ucp.dev/' . $version . '/specification/overview/';
        $rest = untrailingslashit( $rest_url );

        $capability_ids = array_keys( $capabilities );

        $extension_ids = array();
        foreach ( $capabilities as $capability_id => $entries ) {
            foreach ( (array) $entries as $entry ) {
                if ( isset( $entry['extends'] ) ) {
                    $extension_ids[] = $capability_id;
                    break;
                }
            }
        }
        $extension_ids = array_values( array_unique( $extension_ids ) );

        $service = array(
            'id'                => 'dev.ucp.shopping',
            'version'           => $version,
            'spec'              => $spec,
            'capabilities'      => $capability_ids,
            'extensions'        => $extension_ids,
            'payment_handlers'  => array_keys( $payment_handlers ),
            'endpoints'         => array(
                'rest' => $rest,
                'mcp'  => $rest . '/mcp',
            ),
            'schemas'           => array(
                'rest' => 'https://ucp.dev/services/shopping/rest.openrpc.json',
                'mcp'  => 'https://ucp.dev/services/shopping/openrpc.json',
            ),
        );

        $services = array(
            'dev.ucp.shopping' => array( $service ),
        );

        return apply_filters( 'wc_ucp_services', $services, $rest_url, $version, $capabilities, $payment_handlers );
    }

    /**
     * Build the capability map keyed by capability id.
     *
     * @return array
     */
    private function get_capability_map( $version ) {

        $capabilities = array(
            'dev.ucp.shopping.checkout' => array(
                array(
                    'version' => $version,
                    'spec'    => 'https://ucp.dev/' . $version . '/specification/checkout',
                    'schema'  => 'https://ucp.dev/' . $version . '/schemas/shopping/checkout.json',
                ),
            ),
            'dev.ucp.shopping.fulfillment' => array(
                array(
                    'version' => $version,
                    'spec'    => 'https://ucp.dev/' . $version . '/specification/fulfillment',
                    'schema'  => 'https://ucp.dev/' . $version . '/schemas/shopping/fulfillment.json',
                    'extends' => 'dev.ucp.shopping.checkout',
                    'config'  => array(
                        'allows_multi_destination' => array(
                            'shipping' => false,
                        ),
                        'allows_method_combinations' => array(
                            array( 'shipping' ),
                        ),
                    ),
                ),
            ),
            'dev.ucp.shopping.discount' => array(
                array(
                    'version' => $version,
                    'spec'    => 'https://ucp.dev/' . $version . '/specification/discount',
                    'schema'  => 'https://ucp.dev/' . $version . '/schemas/shopping/discount.json',
                    'extends' => 'dev.ucp.shopping.checkout',
                    'config'  => array(
                        'allows_stackable' => false,
                    ),
                ),
            ),
            'dev.ucp.shopping.order' => array(
                array(
                    'version' => $version,
                    'spec'    => 'https://ucp.dev/' . $version . '/specification/order',
                    'schema'  => 'https://ucp.dev/' . $version . '/schemas/shopping/order.json',
                ),
            ),
        );

        return apply_filters( 'wc_ucp_capabilities', $capabilities );
    }

    /**
     * Get payment handlers from active WooCommerce gateways.
     *
     * @return array
     */
    private function get_payment_handler_map( $version ) {
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

        foreach ( $gateways as $gateway_id => $gateway ) {
            if ( isset( $gateway_map[ $gateway_id ] ) ) {
                $mapped  = $gateway_map[ $gateway_id ];
                $handler = array(
                    'id'      => $mapped['id'],
                    'type'    => $mapped['type'],
                    'version' => $version,
                    'spec'    => 'https://ucp.dev/payment-handlers/' . $mapped['id'],
                    'schema'  => 'https://ucp.dev/payment-handlers/' . $mapped['id'] . '/config.json',
                    'config'  => array(),
                );

                $handlers[ $mapped['id'] ][] = $handler;
            }
        }

        if ( empty( $handlers ) ) {
            $handlers['manual'][] = array(
                'id'      => 'manual',
                'type'    => 'escalation',
                'version' => $version,
                'spec'    => site_url( '/checkout/' ),
                'schema'  => '',
                'config'  => array(),
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
