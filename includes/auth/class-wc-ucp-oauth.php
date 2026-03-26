<?php
/**
 * OAuth 2.0 Identity Linking endpoints.
 * Implements RFC 6749, RFC 7009 (revocation), and RFC 7636 (PKCE).
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_OAuth {

    /**
     * Register OAuth REST routes.
     */
    public function register_routes() {
        $namespace = 'ucp/v1';

        // Authorization endpoint.
        register_rest_route( $namespace, '/oauth/authorize', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'authorize' ),
                'permission_callback' => '__return_true',
            ),
        ) );

        // Token endpoint.
        register_rest_route( $namespace, '/oauth/token', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'token' ),
                'permission_callback' => '__return_true',
            ),
        ) );

        // Token revocation endpoint (RFC 7009).
        register_rest_route( $namespace, '/oauth/revoke', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'revoke' ),
                'permission_callback' => '__return_true',
            ),
        ) );

        // OAuth metadata endpoint (RFC 8414).
        register_rest_route( $namespace, '/oauth/.well-known/oauth-authorization-server', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'metadata' ),
                'permission_callback' => '__return_true',
            ),
        ) );
    }

    /**
     * Authorization endpoint.
     * In a real implementation, this would render a consent page.
     * For API-only use, it validates parameters and issues auth code.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function authorize( $request ) {
        $response_type = $request->get_param( 'response_type' );
        $client_id     = sanitize_text_field( $request->get_param( 'client_id' ) );
        $redirect_uri  = esc_url_raw( $request->get_param( 'redirect_uri' ) );
        $scope         = sanitize_text_field( $request->get_param( 'scope' ) ?: 'checkout' );
        $state         = sanitize_text_field( $request->get_param( 'state' ) );

        // PKCE parameters.
        $code_challenge        = sanitize_text_field( $request->get_param( 'code_challenge' ) );
        $code_challenge_method = sanitize_text_field( $request->get_param( 'code_challenge_method' ) );

        if ( 'code' !== $response_type ) {
            return new WP_Error( 'unsupported_response_type', 'Only "code" response type is supported.', array( 'status' => 400 ) );
        }

        if ( empty( $client_id ) || empty( $redirect_uri ) ) {
            return new WP_Error( 'invalid_request', 'client_id and redirect_uri are required.', array( 'status' => 400 ) );
        }

        // Validate client.
        $client = $this->validate_client( $client_id, $redirect_uri );
        if ( is_wp_error( $client ) ) {
            return $client;
        }

        // Validate PKCE method.
        if ( ! empty( $code_challenge ) && 'S256' !== $code_challenge_method ) {
            return new WP_Error( 'invalid_request', 'Only S256 code_challenge_method is supported.', array( 'status' => 400 ) );
        }

        // Check if user is logged in (WordPress session).
        if ( ! is_user_logged_in() ) {
            // Redirect to login page with return URL.
            $login_url = wp_login_url( $request->get_route() . '?' . http_build_query( $request->get_params() ) );
            return new WP_REST_Response( array(
                'redirect' => $login_url,
                'message'  => 'User authentication required. Please log in.',
            ), 302 );
        }

        $customer_id = get_current_user_id();

        // Verify user is a WC customer.
        $customer = new WC_Customer( $customer_id );
        if ( ! $customer->get_id() ) {
            return new WP_Error( 'invalid_user', 'User is not a valid customer.', array( 'status' => 403 ) );
        }

        // Generate authorization code.
        $token_store = new WC_UCP_Token_Store();
        $code        = $token_store->create_auth_code(
            $client_id,
            $customer_id,
            $redirect_uri,
            $scope,
            $code_challenge,
            $code_challenge_method
        );

        // Build redirect URL with code.
        $redirect_params = array( 'code' => $code );
        if ( ! empty( $state ) ) {
            $redirect_params['state'] = $state;
        }

        $redirect_url = add_query_arg( $redirect_params, $redirect_uri );

        return new WP_REST_Response( array(
            'redirect' => $redirect_url,
            'code'     => $code,
        ), 200 );
    }

    /**
     * Token endpoint.
     * Handles authorization_code and refresh_token grant types.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function token( $request ) {
        $grant_type = sanitize_text_field( $request->get_param( 'grant_type' ) );

        $token_store = new WC_UCP_Token_Store();

        if ( 'authorization_code' === $grant_type ) {
            $code          = sanitize_text_field( $request->get_param( 'code' ) );
            $client_id     = sanitize_text_field( $request->get_param( 'client_id' ) );
            $redirect_uri  = esc_url_raw( $request->get_param( 'redirect_uri' ) );
            $code_verifier = sanitize_text_field( $request->get_param( 'code_verifier' ) );

            if ( empty( $code ) || empty( $client_id ) || empty( $redirect_uri ) ) {
                return new WP_Error( 'invalid_request', 'code, client_id, and redirect_uri are required.', array( 'status' => 400 ) );
            }

            // Authenticate client.
            $client_secret = sanitize_text_field( $request->get_param( 'client_secret' ) );
            if ( ! empty( $client_secret ) ) {
                $client = $this->authenticate_client( $client_id, $client_secret );
                if ( is_wp_error( $client ) ) {
                    return $client;
                }
            }

            $result = $token_store->exchange_auth_code( $code, $client_id, $redirect_uri, $code_verifier );

        } elseif ( 'refresh_token' === $grant_type ) {
            $refresh_token = sanitize_text_field( $request->get_param( 'refresh_token' ) );
            $client_id     = sanitize_text_field( $request->get_param( 'client_id' ) );

            if ( empty( $refresh_token ) || empty( $client_id ) ) {
                return new WP_Error( 'invalid_request', 'refresh_token and client_id are required.', array( 'status' => 400 ) );
            }

            $result = $token_store->refresh_tokens( $refresh_token, $client_id );

        } else {
            return new WP_Error( 'unsupported_grant_type', 'Only authorization_code and refresh_token grants are supported.', array( 'status' => 400 ) );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $response = new WP_REST_Response( $result, 200 );
        $response->header( 'Cache-Control', 'no-store' );
        $response->header( 'Pragma', 'no-cache' );
        return $response;
    }

    /**
     * Token revocation endpoint (RFC 7009).
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function revoke( $request ) {
        $token      = sanitize_text_field( $request->get_param( 'token' ) );
        $token_type = sanitize_text_field( $request->get_param( 'token_type_hint' ) ?: 'access_token' );

        if ( ! empty( $token ) ) {
            $token_store = new WC_UCP_Token_Store();
            $token_store->revoke_token( $token, $token_type );
        }

        // RFC 7009: Always return 200, even if token was invalid.
        return new WP_REST_Response( null, 200 );
    }

    /**
     * OAuth Server Metadata endpoint (RFC 8414).
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function metadata( $request ) {
        $base_url = rest_url( 'ucp/v1' );

        return new WP_REST_Response( array(
            'issuer'                                     => site_url(),
            'authorization_endpoint'                     => $base_url . '/oauth/authorize',
            'token_endpoint'                             => $base_url . '/oauth/token',
            'revocation_endpoint'                        => $base_url . '/oauth/revoke',
            'response_types_supported'                   => array( 'code' ),
            'grant_types_supported'                      => array( 'authorization_code', 'refresh_token' ),
            'code_challenge_methods_supported'           => array( 'S256' ),
            'scopes_supported'                           => array( 'checkout', 'orders', 'profile' ),
            'token_endpoint_auth_methods_supported'      => array( 'client_secret_post' ),
        ), 200 );
    }

    /**
     * Validate an OAuth client and its redirect URI.
     *
     * @param string $client_id    Client ID.
     * @param string $redirect_uri Redirect URI to validate.
     * @return object|WP_Error Client row or error.
     */
    private function validate_client( $client_id, $redirect_uri ) {
        global $wpdb;

        $client = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ucp_oauth_clients WHERE client_id = %s AND revoked = 0",
                $client_id
            )
        );

        if ( ! $client ) {
            return new WP_Error( 'invalid_client', 'Unknown client.', array( 'status' => 401 ) );
        }

        $allowed_uris = json_decode( $client->redirect_uris, true );
        if ( ! is_array( $allowed_uris ) || ! in_array( $redirect_uri, $allowed_uris, true ) ) {
            return new WP_Error( 'invalid_request', 'Invalid redirect URI.', array( 'status' => 400 ) );
        }

        return $client;
    }

    /**
     * Authenticate a client using client_secret.
     *
     * @param string $client_id     Client ID.
     * @param string $client_secret Client secret.
     * @return object|WP_Error Client row or error.
     */
    private function authenticate_client( $client_id, $client_secret ) {
        global $wpdb;

        $client = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ucp_oauth_clients WHERE client_id = %s AND revoked = 0",
                $client_id
            )
        );

        if ( ! $client ) {
            return new WP_Error( 'invalid_client', 'Unknown client.', array( 'status' => 401 ) );
        }

        if ( ! hash_equals( $client->client_secret_hash, hash( 'sha256', $client_secret ) ) ) {
            return new WP_Error( 'invalid_client', 'Invalid client credentials.', array( 'status' => 401 ) );
        }

        return $client;
    }
}
