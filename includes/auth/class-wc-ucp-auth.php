<?php
/**
 * UCP Authentication dispatcher.
 * Supports API Key and OAuth 2.0 Bearer token authentication.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Auth {

    /**
     * Authenticate a REST request.
     * Checks X-API-Key header first, then Authorization: Bearer token.
     *
     * @param WP_REST_Request $request Request object.
     * @return array|WP_Error Auth result with customer_id, or error.
     */
    public function authenticate( $request ) {
        // Check rate limiting first.
        $rate_check = WC_UCP_Rate_Limiter::check( $request );
        if ( is_wp_error( $rate_check ) ) {
            return $rate_check;
        }

        // Try API key authentication.
        $api_key = $request->get_header( 'X-API-Key' );
        if ( ! empty( $api_key ) ) {
            return $this->authenticate_api_key( $api_key );
        }

        // Try Bearer token authentication.
        $auth_header = $request->get_header( 'Authorization' );
        if ( ! empty( $auth_header ) && 0 === stripos( $auth_header, 'Bearer ' ) ) {
            $token = substr( $auth_header, 7 );
            return $this->authenticate_bearer_token( $token );
        }

        return new WP_Error(
            'ucp_auth_missing',
            'Authentication required. Provide X-API-Key header or Authorization: Bearer token.',
            array( 'status' => 401 )
        );
    }

    /**
     * Authenticate via API key.
     *
     * @param string $api_key Raw API key.
     * @return array|WP_Error
     */
    private function authenticate_api_key( $api_key ) {
        $api_keys = new WC_UCP_API_Keys();
        $result   = $api_keys->validate( $api_key );

        if ( is_wp_error( $result ) ) {
            $this->log_auth_failure( 'api_key', 'Invalid API key' );
            return $result;
        }

        $this->log_auth_success( 'api_key', $result['customer_id'] );

        return $result;
    }

    /**
     * Authenticate via OAuth 2.0 Bearer token.
     *
     * @param string $token Access token.
     * @return array|WP_Error
     */
    private function authenticate_bearer_token( $token ) {
        $token_store = new WC_UCP_Token_Store();
        $result      = $token_store->validate_access_token( $token );

        if ( is_wp_error( $result ) ) {
            $this->log_auth_failure( 'oauth2', 'Invalid bearer token' );
            return $result;
        }

        $this->log_auth_success( 'oauth2', $result['customer_id'] );

        return $result;
    }

    /**
     * Log a successful authentication.
     *
     * @param string $method      Auth method.
     * @param int    $customer_id Customer ID.
     */
    private function log_auth_success( $method, $customer_id ) {
        if ( isset( WC_UCP_Plugin::instance()->logger ) ) {
            WC_UCP_Plugin::instance()->logger->debug( "Auth success via {$method}.", array(
                'customer_id' => $customer_id,
            ) );
        }
    }

    /**
     * Log a failed authentication attempt.
     *
     * @param string $method Auth method.
     * @param string $reason Failure reason.
     */
    private function log_auth_failure( $method, $reason ) {
        if ( isset( WC_UCP_Plugin::instance()->logger ) ) {
            WC_UCP_Plugin::instance()->logger->warning( "Auth failure via {$method}: {$reason}.", array(
                'ip' => self::get_client_ip(),
            ) );
        }
    }

    /**
     * Get client IP address.
     *
     * @return string
     */
    public static function get_client_ip() {
        $ip = '';

        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
            $ip  = trim( $ips[0] );
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }

        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
    }
}
