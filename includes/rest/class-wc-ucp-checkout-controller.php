<?php
/**
 * UCP Checkout session REST controller.
 * Handles create, read, update, complete, and cancel operations.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Checkout_Controller extends WC_UCP_REST_Controller {

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'checkout-sessions';

    /**
     * Register REST routes.
     */
    public function register_routes() {
        // POST /checkout-sessions — Create.
        register_rest_route( $this->namespace, '/' . $this->rest_base, array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'create_session' ),
                'permission_callback' => array( $this, 'check_auth_permission' ),
            ),
        ) );

        // GET /checkout-sessions/{id} — Read.
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[a-zA-Z0-9_]+)', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_session' ),
                'permission_callback' => array( $this, 'check_auth_permission' ),
            ),
        ) );

        // PUT /checkout-sessions/{id} — Update.
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[a-zA-Z0-9_]+)', array(
            array(
                'methods'             => 'PUT',
                'callback'            => array( $this, 'update_session' ),
                'permission_callback' => array( $this, 'check_auth_permission' ),
            ),
        ) );

        // POST /checkout-sessions/{id}/complete — Complete.
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[a-zA-Z0-9_]+)/complete', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'complete_session' ),
                'permission_callback' => array( $this, 'check_auth_permission' ),
            ),
        ) );

        // POST /checkout-sessions/{id}/cancel — Cancel.
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[a-zA-Z0-9_]+)/cancel', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'cancel_session' ),
                'permission_callback' => array( $this, 'check_auth_permission' ),
            ),
        ) );
    }

    /**
     * Create a new checkout session.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function create_session( $request ) {
        $data = $request->get_json_params();

        // For MCP wrapper compatibility, extract checkout from nested structure.
        if ( isset( $data['checkout'] ) ) {
            $data = $data['checkout'];
        }

        // Validate input.
        $validated = WC_UCP_Validator::validate_checkout_create( $data );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }

        $customer_id = $this->get_authenticated_customer( $request );
        if ( is_wp_error( $customer_id ) ) {
            return $customer_id;
        }

        // Determine currency.
        $currency = ! empty( $validated['currency'] ) ? $validated['currency'] : get_woocommerce_currency();

        // Resolve and validate products.
        $line_items_data = array();
        foreach ( $validated['line_items'] as $li ) {
            $product_id = $li['item']['id'];
            $product    = WC_UCP_Validator::validate_product( $product_id );

            if ( ! $product ) {
                return $this->ucp_error(
                    'invalid_product',
                    "Product '{$product_id}' not found or not purchasable.",
                    404
                );
            }

            // Check stock.
            if ( ! $product->is_in_stock() ) {
                return $this->ucp_error(
                    'out_of_stock',
                    "Product '{$product->get_name()}' is out of stock.",
                    409
                );
            }

            if ( $product->managing_stock() && $product->get_stock_quantity() < $li['quantity'] ) {
                return $this->ucp_error(
                    'insufficient_stock',
                    "Only {$product->get_stock_quantity()} units of '{$product->get_name()}' available.",
                    409
                );
            }

            $line_items_data[] = array(
                'product'  => $product,
                'quantity' => $li['quantity'],
            );
        }

        // Create WC order in checkout-draft status.
        $order = wc_create_order( array(
            'customer_id' => $customer_id,
            'status'      => 'checkout-draft',
        ) );

        if ( is_wp_error( $order ) ) {
            return $this->ucp_error( 'order_creation_failed', 'Failed to create checkout session.', 500 );
        }

        $order->set_currency( $currency );

        // Add line items.
        foreach ( $line_items_data as $li_data ) {
            $order->add_product( $li_data['product'], $li_data['quantity'] );
        }

        // Set buyer info if provided.
        if ( ! empty( $validated['buyer'] ) ) {
            $this->apply_buyer_data( $order, $validated['buyer'] );
        }

        // Apply discount codes if provided.
        if ( ! empty( $validated['discount_codes'] ) ) {
            foreach ( $validated['discount_codes'] as $code ) {
                $result = $order->apply_coupon( $code );
                if ( is_wp_error( $result ) ) {
                    $this->get_logger()->warning( "Coupon '{$code}' could not be applied.", array( 'error' => $result->get_error_message() ) );
                }
            }
        }

        $order->calculate_totals();
        $order->save();

        // Mark order as UCP-originated.
        $order->update_meta_data( '_ucp_session', true );
        $order->update_meta_data( '_ucp_version', WC_UCP_SPEC_VERSION );
        $order->save_meta_data();

        // Create UCP session.
        $session_key = WC_UCP_Session::create( $order->get_id(), $customer_id, $currency );

        // Evaluate completeness.
        $completeness = WC_UCP_Session::evaluate_order_completeness( $order );
        WC_UCP_Session::update_status( $session_key, $completeness['status'] );

        $session = WC_UCP_Session::get( $session_key );

        $this->get_logger()->info( 'Checkout session created.', array(
            'session'     => $session_key,
            'order_id'    => $order->get_id(),
            'customer_id' => $customer_id,
        ) );

        $card = WC_UCP_Card_Builder::build( $order, $session, $completeness['messages'] );

        return $this->ucp_response( $card, 201 );
    }

    /**
     * Get an existing checkout session.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function get_session( $request ) {
        $session_key = sanitize_text_field( $request->get_param( 'id' ) );
        $customer_id = $this->get_authenticated_customer( $request );

        if ( is_wp_error( $customer_id ) ) {
            return $customer_id;
        }

        $session = WC_UCP_Session::get( $session_key );

        if ( ! $session ) {
            return $this->ucp_error( 'session_not_found', 'Checkout session not found.', 404 );
        }

        if ( ! WC_UCP_Session::verify_ownership( $session, $customer_id ) ) {
            return $this->ucp_error( 'forbidden', 'You do not have access to this session.', 403 );
        }

        $order = wc_get_order( $session->wc_order_id );
        if ( ! $order ) {
            return $this->ucp_error( 'order_not_found', 'Associated order not found.', 404 );
        }

        $completeness = WC_UCP_Session::evaluate_order_completeness( $order );
        $card         = WC_UCP_Card_Builder::build( $order, $session, $completeness['messages'] );

        return $this->ucp_response( $card );
    }

    /**
     * Update a checkout session (full replacement).
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function update_session( $request ) {
        $session_key = sanitize_text_field( $request->get_param( 'id' ) );
        $customer_id = $this->get_authenticated_customer( $request );

        if ( is_wp_error( $customer_id ) ) {
            return $customer_id;
        }

        $session = WC_UCP_Session::get( $session_key );

        if ( ! $session ) {
            return $this->ucp_error( 'session_not_found', 'Checkout session not found.', 404 );
        }

        if ( ! WC_UCP_Session::verify_ownership( $session, $customer_id ) ) {
            return $this->ucp_error( 'forbidden', 'You do not have access to this session.', 403 );
        }

        if ( in_array( $session->status, array( WC_UCP_Session::STATUS_COMPLETED, WC_UCP_Session::STATUS_CANCELED ), true ) ) {
            return $this->ucp_error( 'session_closed', 'This checkout session is already ' . $session->status . '.', 409 );
        }

        $data = $request->get_json_params();
        if ( isset( $data['checkout'] ) ) {
            $data = $data['checkout'];
        }

        $validated = WC_UCP_Validator::validate_checkout_update( $data );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }

        $order = wc_get_order( $session->wc_order_id );
        if ( ! $order ) {
            return $this->ucp_error( 'order_not_found', 'Associated order not found.', 404 );
        }

        // Update line items if provided (full replacement).
        if ( ! empty( $validated['line_items'] ) ) {
            // Remove existing items.
            foreach ( $order->get_items() as $item_id => $item ) {
                $order->remove_item( $item_id );
            }

            // Add new items.
            foreach ( $validated['line_items'] as $li ) {
                $product = WC_UCP_Validator::validate_product( $li['item']['id'] );
                if ( ! $product ) {
                    return $this->ucp_error( 'invalid_product', "Product '{$li['item']['id']}' not found.", 404 );
                }
                if ( ! $product->is_in_stock() ) {
                    return $this->ucp_error( 'out_of_stock', "Product '{$product->get_name()}' is out of stock.", 409 );
                }
                $order->add_product( $product, $li['quantity'] );
            }
        }

        // Update buyer info.
        if ( ! empty( $validated['buyer'] ) ) {
            $this->apply_buyer_data( $order, $validated['buyer'] );
        }

        // Update currency.
        if ( ! empty( $validated['currency'] ) ) {
            $order->set_currency( $validated['currency'] );
        }

        // Apply fulfillment.
        if ( ! empty( $validated['fulfillment'] ) ) {
            $result = WC_UCP_Fulfillment::apply_fulfillment( $order, $validated['fulfillment'] );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }

        // Apply discount codes.
        if ( isset( $validated['discount_codes'] ) ) {
            // Remove existing coupons.
            foreach ( $order->get_coupon_codes() as $code ) {
                $order->remove_coupon( $code );
            }
            // Add new ones.
            foreach ( $validated['discount_codes'] as $code ) {
                $order->apply_coupon( $code );
            }
        }

        $order->calculate_totals();
        $order->save();

        // Extend session expiry on update.
        WC_UCP_Session::extend_expiry( $session_key );

        // Re-evaluate completeness.
        $completeness = WC_UCP_Session::evaluate_order_completeness( $order );
        WC_UCP_Session::update_status( $session_key, $completeness['status'] );

        $session = WC_UCP_Session::get( $session_key );

        $this->get_logger()->info( 'Checkout session updated.', array(
            'session' => $session_key,
            'status'  => $session->status,
        ) );

        $card = WC_UCP_Card_Builder::build( $order, $session, $completeness['messages'] );

        return $this->ucp_response( $card );
    }

    /**
     * Complete a checkout session (place the order).
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function complete_session( $request ) {
        $session_key = sanitize_text_field( $request->get_param( 'id' ) );
        $customer_id = $this->get_authenticated_customer( $request );

        if ( is_wp_error( $customer_id ) ) {
            return $customer_id;
        }

        $session = WC_UCP_Session::get( $session_key );

        if ( ! $session ) {
            return $this->ucp_error( 'session_not_found', 'Checkout session not found.', 404 );
        }

        if ( ! WC_UCP_Session::verify_ownership( $session, $customer_id ) ) {
            return $this->ucp_error( 'forbidden', 'You do not have access to this session.', 403 );
        }

        if ( WC_UCP_Session::STATUS_READY_FOR_COMPLETE !== $session->status ) {
            // Re-evaluate in case status was stale.
            $order        = wc_get_order( $session->wc_order_id );
            $completeness = WC_UCP_Session::evaluate_order_completeness( $order );

            if ( WC_UCP_Session::STATUS_READY_FOR_COMPLETE !== $completeness['status'] ) {
                WC_UCP_Session::update_status( $session_key, $completeness['status'] );
                $session = WC_UCP_Session::get( $session_key );
                $card    = WC_UCP_Card_Builder::build( $order, $session, $completeness['messages'] );
                return $this->ucp_response( $card );
            }
        }

        $order = wc_get_order( $session->wc_order_id );
        if ( ! $order ) {
            return $this->ucp_error( 'order_not_found', 'Associated order not found.', 404 );
        }

        // Process payment via AP2 if mandate is present.
        $payment_data   = WC_UCP_Payment_Utils::normalize_payment_payload( $request->get_json_params() );
        $payment_result = $this->process_payment( $order, $payment_data );

        if ( is_wp_error( $payment_result ) ) {
            return $payment_result;
        }

        // Transition order status.
        $order->set_status( 'processing' );
        $order->save();

        // Reduce stock.
        wc_reduce_stock_levels( $order->get_id() );

        // Trigger WC emails.
        do_action( 'woocommerce_order_status_pending_to_processing_notification', $order->get_id(), $order );

        // Update session status.
        WC_UCP_Session::update_status( $session_key, WC_UCP_Session::STATUS_COMPLETED );
        $session = WC_UCP_Session::get( $session_key );

        $this->get_logger()->info( 'Checkout session completed.', array(
            'session'  => $session_key,
            'order_id' => $order->get_id(),
        ) );

        $card = WC_UCP_Card_Builder::build( $order, $session );

        return $this->ucp_response( $card );
    }

    /**
     * Cancel a checkout session.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function cancel_session( $request ) {
        $session_key = sanitize_text_field( $request->get_param( 'id' ) );
        $customer_id = $this->get_authenticated_customer( $request );

        if ( is_wp_error( $customer_id ) ) {
            return $customer_id;
        }

        $session = WC_UCP_Session::get( $session_key );

        if ( ! $session ) {
            return $this->ucp_error( 'session_not_found', 'Checkout session not found.', 404 );
        }

        if ( ! WC_UCP_Session::verify_ownership( $session, $customer_id ) ) {
            return $this->ucp_error( 'forbidden', 'You do not have access to this session.', 403 );
        }

        if ( WC_UCP_Session::STATUS_COMPLETED === $session->status ) {
            return $this->ucp_error( 'session_completed', 'Cannot cancel a completed session.', 409 );
        }

        // Cancel the WC order.
        $order = wc_get_order( $session->wc_order_id );
        if ( $order ) {
            $order->set_status( 'cancelled' );
            $order->save();
        }

        WC_UCP_Session::update_status( $session_key, WC_UCP_Session::STATUS_CANCELED );
        $session = WC_UCP_Session::get( $session_key );

        $this->get_logger()->info( 'Checkout session canceled.', array(
            'session' => $session_key,
        ) );

        $card = WC_UCP_Card_Builder::build( $order, $session );

        return $this->ucp_response( $card );
    }

    /**
     * Apply buyer data to a WC order.
     *
     * @param WC_Order $order WooCommerce order.
     * @param array    $buyer Buyer data.
     */
    private function apply_buyer_data( $order, $buyer ) {
        if ( ! empty( $buyer['email'] ) ) {
            $order->set_billing_email( $buyer['email'] );
        }
        if ( ! empty( $buyer['first_name'] ) ) {
            $order->set_billing_first_name( $buyer['first_name'] );
            if ( empty( $order->get_shipping_first_name() ) ) {
                $order->set_shipping_first_name( $buyer['first_name'] );
            }
        }
        if ( ! empty( $buyer['last_name'] ) ) {
            $order->set_billing_last_name( $buyer['last_name'] );
            if ( empty( $order->get_shipping_last_name() ) ) {
                $order->set_shipping_last_name( $buyer['last_name'] );
            }
        }
        if ( ! empty( $buyer['phone'] ) ) {
            $order->set_billing_phone( $buyer['phone'] );
        }

        if ( ! empty( $buyer['shipping_address'] ) ) {
            $addr = $buyer['shipping_address'];
            if ( ! empty( $addr['address_1'] ) ) $order->set_shipping_address_1( $addr['address_1'] );
            if ( ! empty( $addr['address_2'] ) ) $order->set_shipping_address_2( $addr['address_2'] );
            if ( ! empty( $addr['city'] ) )      $order->set_shipping_city( $addr['city'] );
            if ( ! empty( $addr['state'] ) )     $order->set_shipping_state( $addr['state'] );
            if ( ! empty( $addr['postcode'] ) )  $order->set_shipping_postcode( $addr['postcode'] );
            if ( ! empty( $addr['country'] ) )   $order->set_shipping_country( $addr['country'] );
            if ( ! empty( $addr['company'] ) )   $order->set_shipping_company( $addr['company'] );
        }

		if ( ! empty( $buyer['billing_address'] ) ) {
			$addr = $buyer['billing_address'];
			if ( ! empty( $addr['address_1'] ) ) $order->set_billing_address_1( $addr['address_1'] );
			if ( ! empty( $addr['address_2'] ) ) $order->set_billing_address_2( $addr['address_2'] );
			if ( ! empty( $addr['city'] ) )      $order->set_billing_city( $addr['city'] );
			if ( ! empty( $addr['state'] ) )     $order->set_billing_state( $addr['state'] );
			if ( ! empty( $addr['postcode'] ) )  $order->set_billing_postcode( $addr['postcode'] );
			if ( ! empty( $addr['country'] ) )   $order->set_billing_country( $addr['country'] );
		}

		$this->maybe_populate_shipping_from_billing( $order );
		$this->maybe_assign_default_shipping_method( $order );
	}

	/**
	 * Populate shipping fields from billing when only one address was provided.
	 *
	 * Many merchants only collect a billing address in chat. Woo requires a
	 * shipping destination for physical goods, so mirror the billing data when
	 * the order still needs a shipping address and none has been set yet.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	private function maybe_populate_shipping_from_billing( $order ) {
		if ( ! $order->needs_shipping_address() ) {
			return;
		}

		$has_shipping_address = $order->get_shipping_address_1() || $order->get_shipping_city() || $order->get_shipping_country() || $order->get_shipping_postcode();
		if ( $has_shipping_address ) {
			return;
		}

		if ( ! $order->get_billing_address_1() ) {
			return;
		}

		if ( ! $order->get_shipping_first_name() && $order->get_billing_first_name() ) {
			$order->set_shipping_first_name( $order->get_billing_first_name() );
		}

		if ( ! $order->get_shipping_last_name() && $order->get_billing_last_name() ) {
			$order->set_shipping_last_name( $order->get_billing_last_name() );
		}

		if ( $order->get_billing_company() ) {
			$order->set_shipping_company( $order->get_billing_company() );
		}

		if ( $order->get_billing_address_1() ) {
			$order->set_shipping_address_1( $order->get_billing_address_1() );
		}

		if ( $order->get_billing_address_2() ) {
			$order->set_shipping_address_2( $order->get_billing_address_2() );
		}

		if ( $order->get_billing_city() ) {
			$order->set_shipping_city( $order->get_billing_city() );
		}

		if ( $order->get_billing_state() ) {
			$order->set_shipping_state( $order->get_billing_state() );
		}

		if ( $order->get_billing_postcode() ) {
			$order->set_shipping_postcode( $order->get_billing_postcode() );
		}

		if ( $order->get_billing_country() ) {
			$order->set_shipping_country( $order->get_billing_country() );
		}
	}

	/**
	 * Ensure a shipping rate is attached once an address exists.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	private function maybe_assign_default_shipping_method( $order ) {
		if ( ! $order->needs_shipping_address() ) {
			return;
		}

		if ( ! empty( $order->get_shipping_methods() ) ) {
			return;
		}

		if ( empty( $order->get_shipping_country() ) || empty( $order->get_shipping_postcode() ) ) {
			return;
		}

		$package = array(
			'destination' => array(
				'country'  => $order->get_shipping_country(),
				'state'    => $order->get_shipping_state(),
				'postcode' => $order->get_shipping_postcode(),
				'city'     => $order->get_shipping_city(),
			),
			'contents'        => array(),
			'contents_cost'   => 0,
			'applied_coupons' => array(),
		);

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product && $product->needs_shipping() ) {
				$package['contents'][] = array(
					'data'     => $product,
					'quantity' => $item->get_quantity(),
				);
				$package['contents_cost'] += floatval( $item->get_subtotal() );
			}
		}

		if ( empty( $package['contents'] ) ) {
			return;
		}

		$shipping = WC()->shipping();
		if ( ! $shipping ) {
			return;
		}

		$had_session = isset( WC()->session ) && WC()->session;
		if ( ! $had_session ) {
			WC()->session = new WC_UCP_Runtime_Session();
		}

		try {
			$shipping->calculate_shipping( array( $package ) );
			$packages = $shipping->get_packages();
			if ( empty( $packages[0]['rates'] ) ) {
				return;
			}

			$rate = reset( $packages[0]['rates'] );

			foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
				$order->remove_item( $item_id );
			}

			$shipping_item = new WC_Order_Item_Shipping();
			$shipping_item->set_method_title( $rate->get_label() );
			$shipping_item->set_method_id( $rate->get_method_id() );
			$shipping_item->set_instance_id( $rate->get_instance_id() );
			$shipping_item->set_total( $rate->get_cost() );
			$order->add_item( $shipping_item );
		} catch ( Exception $e ) {
			if ( isset( WC_UCP_Plugin::instance()->logger ) ) {
				WC_UCP_Plugin::instance()->logger->warning( 'Unable to assign default shipping method.', array( 'error' => $e->getMessage(), 'order_id' => $order->get_id() ) );
			}
			return;
		} finally {
			if ( ! $had_session ) {
				WC()->session = null;
			}
		}

		$order->calculate_totals();
	}

	/**
	 * Process payment using AP2 mandate or fallback.
     *
     * @param WC_Order $order        WooCommerce order.
     * @param array    $payment_data Payment data from request.
     * @return true|WP_Error
     */
    private function process_payment( $order, $payment_data ) {
        // If AP2 payment mandate is present, process via AP2 handler.
        if ( ! empty( $payment_data['payment_mandate'] ) ) {
            $ap2 = new WC_UCP_AP2_Handler();
            return $ap2->process_mandate( $order, $payment_data['payment_mandate'] );
        }

        // If payment token is present, process via payment bridge.
        if ( ! empty( $payment_data['payment_token'] ) ) {
            $bridge = new WC_UCP_Payment_Bridge();
            return $bridge->process_token( $order, $payment_data['payment_token'] );
        }

        // For orders with zero total (fully discounted), complete directly.
        if ( floatval( $order->get_total() ) <= 0 ) {
            $order->payment_complete();
            return true;
        }

        // No payment method provided — escalate.
        return new WP_Error(
            'payment_required',
            'A payment method is required to complete this checkout.',
            array( 'status' => 402 )
        );
    }

    /**
     * Get the UCP logger.
     *
     * @return WC_UCP_Logger
     */
    private function get_logger() {
        return WC_UCP_Plugin::instance()->logger;
    }
}
