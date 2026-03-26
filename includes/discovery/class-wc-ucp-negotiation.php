<?php
/**
 * UCP Capability Negotiation.
 * Computes the intersection of merchant and agent capabilities.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Negotiation {

    /**
     * Merchant's supported capabilities (IDs).
     *
     * @var array
     */
    private static $merchant_capabilities = array(
        'dev.ucp.shopping.checkout',
    );

    /**
     * Merchant's supported extensions (IDs).
     *
     * @var array
     */
    private static $merchant_extensions = array(
        'dev.ucp.shopping.fulfillment',
        'dev.ucp.shopping.discount',
        'dev.ucp.shopping.order',
    );

    /**
     * Negotiate capabilities with an agent profile.
     *
     * @param string $agent_profile_url URL to the agent's UCP profile.
     * @return array Negotiated capabilities and extensions.
     */
    public static function negotiate( $agent_profile_url ) {
        if ( empty( $agent_profile_url ) ) {
            return self::get_full_capabilities();
        }

        // Fetch agent profile (cached for 1 hour).
        $cache_key     = 'ucp_agent_' . md5( $agent_profile_url );
        $agent_profile = get_transient( $cache_key );

        if ( false === $agent_profile ) {
            $agent_profile = self::fetch_agent_profile( $agent_profile_url );

            if ( is_wp_error( $agent_profile ) ) {
                // On failure, return full capabilities.
                return self::get_full_capabilities();
            }

            set_transient( $cache_key, $agent_profile, HOUR_IN_SECONDS );
        }

        return self::compute_intersection( $agent_profile );
    }

    /**
     * Fetch an agent's UCP profile.
     *
     * @param string $url Profile URL.
     * @return array|WP_Error
     */
    private static function fetch_agent_profile( $url ) {
        $response = wp_remote_get( $url, array(
            'timeout'   => 10,
            'sslverify' => true,
            'headers'   => array(
                'Accept' => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            return new WP_Error( 'invalid_profile', 'Agent profile is not valid JSON.' );
        }

        return $data;
    }

    /**
     * Compute the intersection of merchant and agent capabilities.
     *
     * @param array $agent_profile Agent's profile data.
     * @return array Negotiated result.
     */
    private static function compute_intersection( $agent_profile ) {
        $agent_capabilities = array();
        $agent_extensions   = array();

        // Extract agent capabilities from profile.
        if ( ! empty( $agent_profile['services'] ) ) {
            foreach ( $agent_profile['services'] as $service ) {
                if ( ! empty( $service['capabilities'] ) ) {
                    foreach ( $service['capabilities'] as $cap ) {
                        if ( ! empty( $cap['id'] ) ) {
                            $agent_capabilities[] = $cap['id'];
                        }
                    }
                }
                if ( ! empty( $service['extensions'] ) ) {
                    foreach ( $service['extensions'] as $ext ) {
                        if ( ! empty( $ext['id'] ) ) {
                            $agent_extensions[] = $ext['id'];
                        }
                    }
                }
            }
        }

        // Compute intersection.
        $negotiated_capabilities = array_values(
            array_intersect( self::$merchant_capabilities, $agent_capabilities )
        );

        $negotiated_extensions = array_values(
            array_intersect( self::$merchant_extensions, $agent_extensions )
        );

        // If agent has no capabilities declared, assume full support.
        if ( empty( $agent_capabilities ) ) {
            $negotiated_capabilities = self::$merchant_capabilities;
        }
        if ( empty( $agent_extensions ) ) {
            $negotiated_extensions = self::$merchant_extensions;
        }

        return array(
            'capabilities' => $negotiated_capabilities,
            'extensions'   => $negotiated_extensions,
        );
    }

    /**
     * Get the full set of merchant capabilities (no negotiation).
     *
     * @return array
     */
    public static function get_full_capabilities() {
        return array(
            'capabilities' => self::$merchant_capabilities,
            'extensions'   => self::$merchant_extensions,
        );
    }
}
