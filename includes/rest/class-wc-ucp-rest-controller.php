<?php
/**
 * Base REST controller for UCP endpoints.
 * Provides common functionality for all UCP REST routes.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

abstract class WC_UCP_REST_Controller extends WP_REST_Controller {

    /**
     * REST API namespace.
     *
     * @var string
     */
    protected $namespace = 'ucp/v1';

    /**
     * Get the authenticated customer ID from the request.
     *
     * @param WP_REST_Request $request Request object.
     * @return int|WP_Error Customer ID or error.
     */
    protected function get_authenticated_customer( $request ) {
        $auth_result = $request->get_param( '_ucp_auth' );

        if ( is_wp_error( $auth_result ) ) {
            return $auth_result;
        }

        if ( ! array_key_exists( 'customer_id', $auth_result ) ) {
            return new WP_Error(
                'ucp_auth_required',
                'Authentication is required for this operation.',
                array( 'status' => 401 )
            );
        }

        return absint( $auth_result['customer_id'] );
    }

    /**
     * Standard permission check requiring authentication.
     *
     * @param WP_REST_Request $request Request object.
     * @return true|WP_Error
     */
    public function check_auth_permission( $request ) {
        $auth   = new WC_UCP_Auth();
        $result = $auth->authenticate( $request );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $request->set_param( '_ucp_auth', $result );
        return true;
    }

    /**
     * Public endpoint — no auth required.
     *
     * @param WP_REST_Request $request Request object.
     * @return true
     */
    public function check_public_permission( $request ) {
        // Attempt auth but don't require it.
        $auth   = new WC_UCP_Auth();
        $result = $auth->authenticate( $request );

        if ( ! is_wp_error( $result ) ) {
            $request->set_param( '_ucp_auth', $result );
        }

        return true;
    }

    /**
     * Build a standard UCP error response.
     *
     * @param string $code    Error code.
     * @param string $message Human-readable message.
     * @param int    $status  HTTP status code.
     * @param array  $data    Additional error data.
     * @return WP_Error
     */
    protected function ucp_error( $code, $message, $status = 400, $data = array() ) {
        return new WP_Error( $code, $message, array_merge( array( 'status' => $status ), $data ) );
    }

    /**
     * Send a standard JSON response with UCP headers.
     *
     * @param mixed $data    Response data.
     * @param int   $status  HTTP status code.
     * @return WP_REST_Response
     */
    protected function ucp_response( $data, $status = 200 ) {
        $response = new WP_REST_Response( $data, $status );
        $response->header( 'X-Content-Type-Options', 'nosniff' );
        $response->header( 'Content-Type', 'application/json; charset=utf-8' );
        return $response;
    }
}
