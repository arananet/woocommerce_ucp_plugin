<?php
/**
 * UCP Rate Limiter.
 * Limits API requests per API key or IP address using WordPress transients.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Rate_Limiter {

    const DEFAULT_LIMIT_AUTHENTICATED   = 60;   // 60 requests/minute for authenticated.
    const DEFAULT_LIMIT_UNAUTHENTICATED = 30;   // 30 requests/minute for unauthenticated.
    const WINDOW_SECONDS                = 60;

    /**
     * Check rate limit for a request.
     *
     * @param WP_REST_Request $request Request object.
     * @return true|WP_Error
     */
    public static function check( $request ) {
        $api_key = $request->get_header( 'X-API-Key' );
        $auth    = $request->get_header( 'Authorization' );

        if ( ! empty( $api_key ) ) {
            $identifier = 'key_' . substr( hash( 'sha256', $api_key ), 0, 16 );
            $limit      = self::get_limit( 'authenticated' );
        } elseif ( ! empty( $auth ) ) {
            $identifier = 'token_' . substr( hash( 'sha256', $auth ), 0, 16 );
            $limit      = self::get_limit( 'authenticated' );
        } else {
            $identifier = 'ip_' . md5( WC_UCP_Auth::get_client_ip() );
            $limit      = self::get_limit( 'unauthenticated' );
        }

        $transient_key = 'ucp_rate_' . $identifier;
        $current       = get_transient( $transient_key );

        if ( false === $current ) {
            set_transient( $transient_key, 1, self::WINDOW_SECONDS );
            return true;
        }

        $current = intval( $current );

        if ( $current >= $limit ) {
            return new WP_Error(
                'rate_limit_exceeded',
                'Too many requests. Please try again later.',
                array(
                    'status'      => 429,
                    'retry_after' => self::WINDOW_SECONDS,
                )
            );
        }

        set_transient( $transient_key, $current + 1, self::WINDOW_SECONDS );
        return true;
    }

    /**
     * Get the rate limit for a given type.
     *
     * @param string $type 'authenticated' or 'unauthenticated'.
     * @return int
     */
    private static function get_limit( $type ) {
        if ( 'authenticated' === $type ) {
            return intval( WC_UCP_Plugin::get_option( 'rate_limit_authenticated', self::DEFAULT_LIMIT_AUTHENTICATED ) );
        }
        return intval( WC_UCP_Plugin::get_option( 'rate_limit_unauthenticated', self::DEFAULT_LIMIT_UNAUTHENTICATED ) );
    }
}
