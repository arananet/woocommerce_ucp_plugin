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
            array(
                'methods'             => WP_REST_Server::CREATABLE,
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
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function authorize( $request ) {
        $response_type = $request->get_param( 'response_type' ) ?: 'code';
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

        if ( ! is_user_logged_in() ) {
            $login_url = $this->build_login_redirect_url( $request );
            return $this->redirect_response( $login_url, 302, array(
                'message' => 'User authentication required. Please log in.',
            ) );
        }

        $customer_id = get_current_user_id();
        $customer    = new WC_Customer( $customer_id );

        if ( ! $customer->get_id() ) {
            return new WP_Error( 'invalid_user', 'User is not a valid customer.', array( 'status' => 403 ) );
        }

        $method = strtoupper( $request->get_method() );
        $params = array(
            'client_id'             => $client_id,
            'redirect_uri'          => $redirect_uri,
            'scope'                 => $scope,
            'state'                 => $state,
            'response_type'         => $response_type,
            'code_challenge'        => $code_challenge,
            'code_challenge_method' => $code_challenge_method,
            'route'                 => $request->get_route(),
        );

        if ( 'POST' !== $method ) {
            return $this->render_consent_screen( $client, $customer, $params );
        }

        $nonce = sanitize_text_field( $request->get_param( '_wpnonce' ) );
        if ( ! wp_verify_nonce( $nonce, 'wc_ucp_oauth_authorize' ) ) {
            return new WP_Error( 'invalid_nonce', 'Session expired. Please try again.', array( 'status' => 400 ) );
        }

        $decision = sanitize_text_field( $request->get_param( 'decision' ) );
        if ( 'deny' === $decision ) {
            $error_params = array( 'error' => 'access_denied' );
            if ( ! empty( $state ) ) {
                $error_params['state'] = $state;
            }

            $deny_url = add_query_arg( $error_params, $redirect_uri );
            return $this->redirect_response( $deny_url, 302 );
        }

        if ( 'approve' !== $decision ) {
            return $this->render_consent_screen( $client, $customer, $params );
        }

        $token_store = new WC_UCP_Token_Store();
        $code        = $token_store->create_auth_code(
            $client_id,
            $customer_id,
            $redirect_uri,
            $scope,
            $code_challenge,
            $code_challenge_method
        );

        $redirect_params = array( 'code' => $code );
        if ( ! empty( $state ) ) {
            $redirect_params['state'] = $state;
        }

        $redirect_url = add_query_arg( $redirect_params, $redirect_uri );

        return $this->redirect_response( $redirect_url, 302, array( 'code' => $code ) );
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

    /**
     * Build a login redirect URL preserving the OAuth query parameters.
     *
     * @param WP_REST_Request $request Request.
     * @return string
     */
    private function build_login_redirect_url( $request ) {
        $route  = trim( $request->get_route(), '/' );
        $target = rest_url( $route );

        $allowed_params = array( 'response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method' );
        $query          = array();

        foreach ( $allowed_params as $param ) {
            $value = $request->get_param( $param );
            if ( null !== $value && '' !== $value ) {
                $query[ $param ] = is_string( $value ) ? $value : wp_json_encode( $value );
            }
        }

        if ( ! empty( $query ) ) {
            $target = add_query_arg( $query, $target );
        }

        return wp_login_url( $target );
    }

    /**
     * Send a redirect response with proper headers.
     *
     * @param string $url    Target URL.
     * @param int    $status HTTP status.
     * @param array  $body   Optional response payload.
     * @return WP_REST_Response
     */
    private function redirect_response( $url, $status = 302, $body = array() ) {
        $response = new WP_REST_Response( $body, $status );
        $response->header( 'Location', $url );
        return $response;
    }

    /**
     * Render the OAuth consent template.
     *
     * @param object      $client   OAuth client row.
     * @param WC_Customer $customer WooCommerce customer.
     * @param array       $params   Request context.
     * @return WP_REST_Response
     */
    private function render_consent_screen( $client, $customer, $params ) {
        $template = WC_UCP_PLUGIN_DIR . 'includes/auth/templates/oauth-consent.php';

        $form_action = rest_url( trim( $params['route'], '/' ) );
        $nonce       = wp_create_nonce( 'wc_ucp_oauth_authorize' );

        $hidden_fields = array(
            'response_type'         => $params['response_type'],
            'client_id'             => $params['client_id'],
            'redirect_uri'          => $params['redirect_uri'],
            'scope'                 => $params['scope'],
            'state'                 => $params['state'],
            'code_challenge'        => $params['code_challenge'],
            'code_challenge_method' => $params['code_challenge_method'],
            '_wpnonce'              => $nonce,
        );

        $hidden_fields = array_filter( $hidden_fields, static function( $value ) {
            return null !== $value && '' !== $value;
        } );

        $scopes = $this->describe_scopes( $params['scope'] );

        $context = array(
            'site_name'      => get_bloginfo( 'name' ),
            'client_name'    => $client->name,
            'customer_name'  => $customer->get_display_name(),
            'customer_email' => $customer->get_email(),
            'scopes'         => $scopes,
            'form_action'    => $form_action,
            'hidden_fields'  => $hidden_fields,
        );

        ob_start();
        extract( $context, EXTR_SKIP );
        require $template;
        $html = ob_get_clean();

        $response = new WP_REST_Response( $html, 200 );
        $response->header( 'Content-Type', 'text/html; charset=utf-8' );
        return $response;
    }

    /**
     * Map requested scopes to human-readable descriptions.
     *
     * @param string $scope_string Scope string from request.
     * @return array
     */
    private function describe_scopes( $scope_string ) {
        $labels = array(
            'checkout' => __( 'Create and update checkout sessions.', 'woocommerce-ucp' ),
            'orders'   => __( 'View your recent orders and status.', 'woocommerce-ucp' ),
            'profile'  => __( 'Access basic profile details (name, email).', 'woocommerce-ucp' ),
        );

        $scopes = preg_split( '/\s+/', trim( (string) $scope_string ) );
        $scopes = array_filter( $scopes );

        if ( empty( $scopes ) ) {
            $scopes = array( 'checkout' );
        }

        $descriptions = array();
        foreach ( $scopes as $scope ) {
            $descriptions[] = array(
                'id'          => $scope,
                'description' => isset( $labels[ $scope ] ) ? $labels[ $scope ] : sprintf( __( 'Access scope: %s', 'woocommerce-ucp' ), $scope ),
            );
        }

        return $descriptions;
    }
}
