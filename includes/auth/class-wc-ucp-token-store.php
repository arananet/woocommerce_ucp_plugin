<?php
/**
 * OAuth 2.0 Token storage and management.
 * Handles access tokens, refresh tokens, and authorization codes.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Token_Store {

    const ACCESS_TOKEN_EXPIRY  = 3600;      // 1 hour.
    const REFRESH_TOKEN_EXPIRY = 2592000;   // 30 days.
    const AUTH_CODE_EXPIRY     = 300;        // 5 minutes.

    /**
     * Create an authorization code.
     *
     * @param string $client_id              OAuth client ID.
     * @param int    $customer_id            Customer ID.
     * @param string $redirect_uri           Redirect URI.
     * @param string $scope                  Requested scope.
     * @param string $code_challenge         PKCE code challenge (optional).
     * @param string $code_challenge_method  PKCE method (optional).
     * @return string The authorization code.
     */
    public function create_auth_code( $client_id, $customer_id, $redirect_uri, $scope = 'checkout', $code_challenge = null, $code_challenge_method = null ) {
        global $wpdb;

        $code      = wp_generate_password( 48, false );
        $code_hash = hash( 'sha256', $code );

        $wpdb->insert(
            $wpdb->prefix . 'ucp_oauth_codes',
            array(
                'code_hash'             => $code_hash,
                'client_id'             => sanitize_text_field( $client_id ),
                'customer_id'           => absint( $customer_id ),
                'redirect_uri'          => esc_url_raw( $redirect_uri ),
                'scope'                 => sanitize_text_field( $scope ),
                'code_challenge'        => $code_challenge ? sanitize_text_field( $code_challenge ) : null,
                'code_challenge_method' => $code_challenge_method ? sanitize_text_field( $code_challenge_method ) : null,
                'expires_at'            => gmdate( 'Y-m-d H:i:s', time() + self::AUTH_CODE_EXPIRY ),
            ),
            array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        return $code;
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * @param string $code          Authorization code.
     * @param string $client_id     Client ID.
     * @param string $redirect_uri  Redirect URI.
     * @param string $code_verifier PKCE code verifier (optional).
     * @return array|WP_Error Token data or error.
     */
    public function exchange_auth_code( $code, $client_id, $redirect_uri, $code_verifier = null ) {
        global $wpdb;

        $code_hash = hash( 'sha256', $code );

        $auth_code = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ucp_oauth_codes WHERE code_hash = %s AND used = 0",
                $code_hash
            )
        );

        if ( ! $auth_code ) {
            return new WP_Error( 'invalid_grant', 'Invalid or expired authorization code.', array( 'status' => 400 ) );
        }

        // Check expiry.
        if ( strtotime( $auth_code->expires_at ) < time() ) {
            return new WP_Error( 'invalid_grant', 'Authorization code has expired.', array( 'status' => 400 ) );
        }

        // Validate client_id.
        if ( $auth_code->client_id !== $client_id ) {
            return new WP_Error( 'invalid_grant', 'Client ID mismatch.', array( 'status' => 400 ) );
        }

        // Validate redirect_uri.
        if ( $auth_code->redirect_uri !== $redirect_uri ) {
            return new WP_Error( 'invalid_grant', 'Redirect URI mismatch.', array( 'status' => 400 ) );
        }

        // PKCE verification.
        if ( ! empty( $auth_code->code_challenge ) ) {
            if ( empty( $code_verifier ) ) {
                return new WP_Error( 'invalid_grant', 'Code verifier is required.', array( 'status' => 400 ) );
            }

            $expected_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );

            if ( ! hash_equals( $auth_code->code_challenge, $expected_challenge ) ) {
                return new WP_Error( 'invalid_grant', 'Code verifier validation failed.', array( 'status' => 400 ) );
            }
        }

        // Mark code as used.
        $wpdb->update(
            $wpdb->prefix . 'ucp_oauth_codes',
            array( 'used' => 1 ),
            array( 'id' => $auth_code->id ),
            array( '%d' ),
            array( '%d' )
        );

        // Generate tokens.
        return $this->create_tokens( $auth_code->client_id, absint( $auth_code->customer_id ), $auth_code->scope );
    }

    /**
     * Create access and refresh tokens.
     *
     * @param string $client_id   Client ID.
     * @param int    $customer_id Customer ID.
     * @param string $scope       Token scope.
     * @return array Token data.
     */
    public function create_tokens( $client_id, $customer_id, $scope = 'checkout' ) {
        global $wpdb;

        $access_token  = wp_generate_password( 64, false );
        $refresh_token = wp_generate_password( 64, false );

        $wpdb->insert(
            $wpdb->prefix . 'ucp_oauth_tokens',
            array(
                'client_id'          => $client_id,
                'customer_id'        => $customer_id,
                'access_token_hash'  => hash( 'sha256', $access_token ),
                'refresh_token_hash' => hash( 'sha256', $refresh_token ),
                'scope'              => $scope,
                'expires_at'         => gmdate( 'Y-m-d H:i:s', time() + self::ACCESS_TOKEN_EXPIRY ),
                'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::REFRESH_TOKEN_EXPIRY ),
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        return array(
            'access_token'  => $access_token,
            'refresh_token' => $refresh_token,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TOKEN_EXPIRY,
            'scope'         => $scope,
        );
    }

    /**
     * Validate an access token.
     *
     * @param string $token Access token.
     * @return array|WP_Error Auth data or error.
     */
    public function validate_access_token( $token ) {
        global $wpdb;

        $token_hash = hash( 'sha256', $token );

        $token_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ucp_oauth_tokens WHERE access_token_hash = %s AND revoked = 0",
                $token_hash
            )
        );

        if ( ! $token_row ) {
            return new WP_Error( 'invalid_token', 'Invalid or revoked access token.', array( 'status' => 401 ) );
        }

        if ( strtotime( $token_row->expires_at ) < time() ) {
            return new WP_Error( 'token_expired', 'Access token has expired.', array( 'status' => 401 ) );
        }

        return array(
            'customer_id' => absint( $token_row->customer_id ),
            'scope'       => $token_row->scope,
            'client_id'   => $token_row->client_id,
            'method'      => 'oauth2',
        );
    }

    /**
     * Refresh tokens using a refresh token.
     *
     * @param string $refresh_token Refresh token.
     * @param string $client_id     Client ID.
     * @return array|WP_Error New token data or error.
     */
    public function refresh_tokens( $refresh_token, $client_id ) {
        global $wpdb;

        $token_hash = hash( 'sha256', $refresh_token );

        $token_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ucp_oauth_tokens WHERE refresh_token_hash = %s AND revoked = 0",
                $token_hash
            )
        );

        if ( ! $token_row ) {
            return new WP_Error( 'invalid_grant', 'Invalid or revoked refresh token.', array( 'status' => 400 ) );
        }

        if ( strtotime( $token_row->refresh_expires_at ) < time() ) {
            return new WP_Error( 'invalid_grant', 'Refresh token has expired.', array( 'status' => 400 ) );
        }

        if ( $token_row->client_id !== $client_id ) {
            return new WP_Error( 'invalid_grant', 'Client ID mismatch.', array( 'status' => 400 ) );
        }

        // Revoke old token (rotation).
        $wpdb->update(
            $wpdb->prefix . 'ucp_oauth_tokens',
            array( 'revoked' => 1 ),
            array( 'id' => $token_row->id ),
            array( '%d' ),
            array( '%d' )
        );

        // Issue new tokens.
        return $this->create_tokens( $client_id, absint( $token_row->customer_id ), $token_row->scope );
    }

    /**
     * Revoke a token (access or refresh).
     *
     * @param string $token     The token to revoke.
     * @param string $token_type 'access_token' or 'refresh_token'.
     * @return bool
     */
    public function revoke_token( $token, $token_type = 'access_token' ) {
        global $wpdb;

        $token_hash = hash( 'sha256', $token );
        $column     = 'refresh_token' === $token_type ? 'refresh_token_hash' : 'access_token_hash';

        return (bool) $wpdb->update(
            $wpdb->prefix . 'ucp_oauth_tokens',
            array( 'revoked' => 1 ),
            array( $column => $token_hash ),
            array( '%d' ),
            array( '%s' )
        );
    }
}
