<?php
/**
 * UCP MCP (Model Context Protocol) binding.
 * Implements JSON-RPC 2.0 handler that wraps REST endpoints as MCP tools.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_MCP_Handler extends WC_UCP_REST_Controller {

    protected $rest_base = 'mcp';

    /**
     * Map of MCP method names to handler methods.
     *
     * @var array
     */
    private $method_map = array(
        'create_checkout'   => 'handle_create_checkout',
        'update_checkout'   => 'handle_update_checkout',
        'get_checkout'      => 'handle_get_checkout',
        'complete_checkout' => 'handle_complete_checkout',
        'cancel_checkout'   => 'handle_cancel_checkout',
        'lookup_customer'   => 'handle_lookup_customer',
        'register_customer' => 'handle_register_customer',
        'list_products'     => 'handle_list_products',
        'get_product'       => 'handle_get_product',
    );

    /**
     * Register REST routes.
     */
    public function register_routes() {
        register_rest_route( $this->namespace, '/' . $this->rest_base, array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'handle_rpc' ),
                'permission_callback' => array( $this, 'check_auth_permission' ),
            ),
        ) );
    }

    /**
     * Handle incoming JSON-RPC 2.0 request.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function handle_rpc( $request ) {
        $body = $request->get_json_params();

        // Validate JSON-RPC 2.0 structure.
        if ( empty( $body['jsonrpc'] ) || '2.0' !== $body['jsonrpc'] ) {
            return $this->rpc_error( null, -32600, 'Invalid Request: jsonrpc must be "2.0".' );
        }

        if ( empty( $body['method'] ) || ! is_string( $body['method'] ) ) {
            return $this->rpc_error( $body['id'] ?? null, -32600, 'Invalid Request: method is required.' );
        }

        $method = $body['method'];
        $params = isset( $body['params'] ) ? $body['params'] : array();
        $rpc_id = isset( $body['id'] ) ? $body['id'] : null;

        // Check if method exists.
        if ( ! isset( $this->method_map[ $method ] ) ) {
            return $this->rpc_error( $rpc_id, -32601, "Method not found: {$method}" );
        }

        $handler = $this->method_map[ $method ];

        try {
            $result = $this->$handler( $params, $request );

            if ( is_wp_error( $result ) ) {
                $status = $result->get_error_data()['status'] ?? 400;
                return $this->rpc_error( $rpc_id, $status, $result->get_error_message() );
            }

            return $this->rpc_response( $rpc_id, $result );

        } catch ( \Exception $e ) {
            return $this->rpc_error( $rpc_id, -32603, 'Internal error: ' . $e->getMessage() );
        }
    }

    /**
     * Create checkout via MCP.
     */
    private function handle_create_checkout( $params, $request ) {
        $checkout_data = isset( $params['checkout'] ) ? $params['checkout'] : $params;

        $sub_request = new WP_REST_Request( 'POST', '/ucp/v1/checkout-sessions' );
        $sub_request->set_body( wp_json_encode( $checkout_data ) );
        $sub_request->set_header( 'Content-Type', 'application/json' );
        $sub_request->set_param( '_ucp_auth', $request->get_param( '_ucp_auth' ) );

        $controller = new WC_UCP_Checkout_Controller();
        $response   = $controller->create_session( $sub_request );

        return $this->extract_response_data( $response );
    }

    /**
     * Update checkout via MCP.
     */
    private function handle_update_checkout( $params, $request ) {
        if ( empty( $params['id'] ) ) {
            return new WP_Error( 'missing_id', 'Checkout session ID is required.', array( 'status' => 400 ) );
        }

        $checkout_data = isset( $params['checkout'] ) ? $params['checkout'] : array();

        $sub_request = new WP_REST_Request( 'PUT', '/ucp/v1/checkout-sessions/' . sanitize_text_field( $params['id'] ) );
        $sub_request->set_body( wp_json_encode( $checkout_data ) );
        $sub_request->set_header( 'Content-Type', 'application/json' );
        $sub_request->set_param( 'id', sanitize_text_field( $params['id'] ) );
        $sub_request->set_param( '_ucp_auth', $request->get_param( '_ucp_auth' ) );

        $controller = new WC_UCP_Checkout_Controller();
        $response   = $controller->update_session( $sub_request );

        return $this->extract_response_data( $response );
    }

    /**
     * Get checkout via MCP.
     */
    private function handle_get_checkout( $params, $request ) {
        if ( empty( $params['id'] ) ) {
            return new WP_Error( 'missing_id', 'Checkout session ID is required.', array( 'status' => 400 ) );
        }

        $sub_request = new WP_REST_Request( 'GET', '/ucp/v1/checkout-sessions/' . sanitize_text_field( $params['id'] ) );
        $sub_request->set_param( 'id', sanitize_text_field( $params['id'] ) );
        $sub_request->set_param( '_ucp_auth', $request->get_param( '_ucp_auth' ) );

        $controller = new WC_UCP_Checkout_Controller();
        $response   = $controller->get_session( $sub_request );

        return $this->extract_response_data( $response );
    }

    /**
     * Complete checkout via MCP.
     */
    private function handle_complete_checkout( $params, $request ) {
        if ( empty( $params['id'] ) ) {
            return new WP_Error( 'missing_id', 'Checkout session ID is required.', array( 'status' => 400 ) );
        }

        $sub_request = new WP_REST_Request( 'POST', '/ucp/v1/checkout-sessions/' . sanitize_text_field( $params['id'] ) . '/complete' );
        $sub_request->set_body( wp_json_encode( $params ) );
        $sub_request->set_header( 'Content-Type', 'application/json' );
        $sub_request->set_param( 'id', sanitize_text_field( $params['id'] ) );
        $sub_request->set_param( '_ucp_auth', $request->get_param( '_ucp_auth' ) );

        $controller = new WC_UCP_Checkout_Controller();
        $response   = $controller->complete_session( $sub_request );

        return $this->extract_response_data( $response );
    }

    /**
     * Cancel checkout via MCP.
     */
    private function handle_cancel_checkout( $params, $request ) {
        if ( empty( $params['id'] ) ) {
            return new WP_Error( 'missing_id', 'Checkout session ID is required.', array( 'status' => 400 ) );
        }

        $sub_request = new WP_REST_Request( 'POST', '/ucp/v1/checkout-sessions/' . sanitize_text_field( $params['id'] ) . '/cancel' );
        $sub_request->set_param( 'id', sanitize_text_field( $params['id'] ) );
        $sub_request->set_param( '_ucp_auth', $request->get_param( '_ucp_auth' ) );

        $controller = new WC_UCP_Checkout_Controller();
        $response   = $controller->cancel_session( $sub_request );

        return $this->extract_response_data( $response );
    }

    /**
     * Lookup customer via MCP.
     */
    private function handle_lookup_customer( $params, $request ) {
        $sub_request = new WP_REST_Request( 'POST', '/ucp/v1/customers/lookup' );
        $sub_request->set_body( wp_json_encode( $params ) );
        $sub_request->set_header( 'Content-Type', 'application/json' );
        $sub_request->set_param( '_ucp_auth', $request->get_param( '_ucp_auth' ) );

        $controller = new WC_UCP_Customer_Controller();
        $response   = $controller->lookup( $sub_request );

        return $this->extract_response_data( $response );
    }

    /**
     * Register customer via MCP.
     */
    private function handle_register_customer( $params, $request ) {
        $sub_request = new WP_REST_Request( 'POST', '/ucp/v1/customers/register' );
        $sub_request->set_body( wp_json_encode( $params ) );
        $sub_request->set_header( 'Content-Type', 'application/json' );
        $sub_request->set_param( '_ucp_auth', $request->get_param( '_ucp_auth' ) );

        $controller = new WC_UCP_Customer_Controller();
        $response   = $controller->register_customer( $sub_request );

        return $this->extract_response_data( $response );
    }

    /**
     * List products via MCP.
     */
    private function handle_list_products( $params, $request ) {
        $sub_request = new WP_REST_Request( 'GET', '/ucp/v1/products' );
        foreach ( $params as $key => $value ) {
            $sub_request->set_param( $key, $value );
        }
        $sub_request->set_param( '_ucp_auth', $request->get_param( '_ucp_auth' ) );

        $controller = new WC_UCP_Product_Controller();
        $response   = $controller->list_products( $sub_request );

        return $this->extract_response_data( $response );
    }

    /**
     * Get product via MCP.
     */
    private function handle_get_product( $params, $request ) {
        if ( empty( $params['id'] ) ) {
            return new WP_Error( 'missing_id', 'Product ID is required.', array( 'status' => 400 ) );
        }

        $sub_request = new WP_REST_Request( 'GET', '/ucp/v1/products/' . absint( $params['id'] ) );
        $sub_request->set_param( 'id', absint( $params['id'] ) );
        $sub_request->set_param( '_ucp_auth', $request->get_param( '_ucp_auth' ) );

        $controller = new WC_UCP_Product_Controller();
        $response   = $controller->get_product( $sub_request );

        return $this->extract_response_data( $response );
    }

    /**
     * Extract data from a WP_REST_Response or WP_Error.
     *
     * @param mixed $response Response object.
     * @return mixed|WP_Error
     */
    private function extract_response_data( $response ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( $response instanceof WP_REST_Response ) {
            return $response->get_data();
        }

        return $response;
    }

    /**
     * Build a JSON-RPC 2.0 success response.
     *
     * @param mixed $id     Request ID.
     * @param mixed $result Result data.
     * @return WP_REST_Response
     */
    private function rpc_response( $id, $result ) {
        return new WP_REST_Response( array(
            'jsonrpc' => '2.0',
            'result'  => $result,
            'id'      => $id,
        ), 200 );
    }

    /**
     * Build a JSON-RPC 2.0 error response.
     *
     * @param mixed  $id      Request ID.
     * @param int    $code    Error code.
     * @param string $message Error message.
     * @return WP_REST_Response
     */
    private function rpc_error( $id, $code, $message ) {
        return new WP_REST_Response( array(
            'jsonrpc' => '2.0',
            'error'   => array(
                'code'    => $code,
                'message' => $message,
            ),
            'id'      => $id,
        ), 200 ); // JSON-RPC always returns 200 HTTP status.
    }
}
