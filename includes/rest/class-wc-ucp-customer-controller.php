<?php
/**
 * UCP Customer REST controller.
 * Handles customer lookup, registration, and profile retrieval.
 * Only registered users can purchase via UCP.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Customer_Controller extends WC_UCP_REST_Controller {

    protected $rest_base = 'customers';

    /**
     * Register REST routes.
     */
    public function register_routes() {
        // POST /customers/lookup — Check if user exists.
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/lookup', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'lookup' ),
                'permission_callback' => array( $this, 'check_auth_permission' ),
            ),
        ) );

        // POST /customers/register — Register new customer.
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/register', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'register_customer' ),
                'permission_callback' => array( $this, 'check_auth_permission' ),
            ),
        ) );

        // GET /customers/me — Get authenticated customer profile.
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/me', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_profile' ),
                'permission_callback' => array( $this, 'check_auth_permission' ),
            ),
        ) );
    }

    /**
     * Lookup a customer by email.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function lookup( $request ) {
        $data  = $request->get_json_params();
        $email = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';

        if ( empty( $email ) || ! is_email( $email ) ) {
            return $this->ucp_error( 'invalid_email', 'A valid email address is required.', 400 );
        }

        $user = get_user_by( 'email', $email );

        $response = array(
            'exists' => (bool) $user,
        );

        if ( $user ) {
            $response['customer_id'] = strval( $user->ID );
        }

        return $this->ucp_response( $response );
    }

    /**
     * Register a new customer.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function register_customer( $request ) {
        $data = $request->get_json_params();

        $email      = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
        $first_name = isset( $data['first_name'] ) ? sanitize_text_field( $data['first_name'] ) : '';
        $last_name  = isset( $data['last_name'] ) ? sanitize_text_field( $data['last_name'] ) : '';
        $password   = isset( $data['password'] ) ? $data['password'] : wp_generate_password( 16, true, true );

        if ( empty( $email ) || ! is_email( $email ) ) {
            return $this->ucp_error( 'invalid_email', 'A valid email address is required.', 400 );
        }

        if ( empty( $first_name ) ) {
            return $this->ucp_error( 'missing_field', 'First name is required.', 400 );
        }

        if ( empty( $last_name ) ) {
            return $this->ucp_error( 'missing_field', 'Last name is required.', 400 );
        }

        // Check if user already exists.
        if ( email_exists( $email ) ) {
            return $this->ucp_error( 'customer_exists', 'A customer with this email already exists.', 409 );
        }

        // Create WooCommerce customer.
        $customer_id = wc_create_new_customer( $email, '', $password );

        if ( is_wp_error( $customer_id ) ) {
            return $this->ucp_error( 'registration_failed', 'Customer registration failed.', 500 );
        }

        // Set customer details.
        $customer = new WC_Customer( $customer_id );
        $customer->set_first_name( $first_name );
        $customer->set_last_name( $last_name );
        $customer->set_billing_first_name( $first_name );
        $customer->set_billing_last_name( $last_name );
        $customer->set_billing_email( $email );
        $customer->save();

        // If password was auto-generated, send reset link.
        if ( ! isset( $data['password'] ) ) {
            $reset_key = get_password_reset_key( get_user_by( 'id', $customer_id ) );
            if ( ! is_wp_error( $reset_key ) ) {
                do_action( 'woocommerce_created_customer', $customer_id, array(
                    'user_email' => $email,
                    'user_pass'  => $password,
                ), true );
            }
        }

        WC_UCP_Plugin::instance()->logger->info( 'New customer registered via UCP.', array(
            'customer_id' => $customer_id,
            'email'       => $email,
        ) );

        return $this->ucp_response( array(
            'customer_id'  => strval( $customer_id ),
            'email'        => $email,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'message'      => 'Customer registered successfully.',
        ), 201 );
    }

    /**
     * Get authenticated customer profile.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function get_profile( $request ) {
        $customer_id = $this->get_authenticated_customer( $request );
        if ( is_wp_error( $customer_id ) ) {
            return $customer_id;
        }

        $customer = new WC_Customer( $customer_id );
        if ( ! $customer->get_id() ) {
            return $this->ucp_error( 'customer_not_found', 'Customer not found.', 404 );
        }

        $profile = array(
            'customer_id' => strval( $customer_id ),
            'email'       => $customer->get_email(),
            'first_name'  => $customer->get_first_name(),
            'last_name'   => $customer->get_last_name(),
        );

        // Shipping address.
        if ( $customer->get_shipping_address_1() ) {
            $profile['shipping_address'] = array(
                'address_1' => $customer->get_shipping_address_1(),
                'address_2' => $customer->get_shipping_address_2(),
                'city'      => $customer->get_shipping_city(),
                'state'     => $customer->get_shipping_state(),
                'postcode'  => $customer->get_shipping_postcode(),
                'country'   => $customer->get_shipping_country(),
                'company'   => $customer->get_shipping_company(),
            );
        }

        // Billing address.
        if ( $customer->get_billing_address_1() ) {
            $profile['billing_address'] = array(
                'address_1' => $customer->get_billing_address_1(),
                'address_2' => $customer->get_billing_address_2(),
                'city'      => $customer->get_billing_city(),
                'state'     => $customer->get_billing_state(),
                'postcode'  => $customer->get_billing_postcode(),
                'country'   => $customer->get_billing_country(),
            );
        }

        return $this->ucp_response( $profile );
    }
}
