<?php
/**
 * UCP Fulfillment extension handler.
 * Manages shipping method calculations and selection on WC orders.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Fulfillment {

    /**
     * Apply fulfillment selection to a WC order.
     *
     * @param WC_Order $order       WooCommerce order.
     * @param array    $fulfillment Fulfillment data from UCP request.
     * @return bool|WP_Error
     */
    public static function apply_fulfillment( $order, $fulfillment ) {
        if ( empty( $fulfillment['methods'] ) ) {
            return true;
        }

        foreach ( $fulfillment['methods'] as $method ) {
            if ( empty( $method['groups'] ) ) {
                continue;
            }

            foreach ( $method['groups'] as $group ) {
                if ( empty( $group['selected_option_id'] ) ) {
                    continue;
                }

                $selected_id = sanitize_text_field( $group['selected_option_id'] );

                // Remove existing shipping items.
                foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
                    $order->remove_item( $item_id );
                }

                // Validate the selected option exists.
                $rate = self::find_shipping_rate( $order, $selected_id );

                if ( is_wp_error( $rate ) ) {
                    return $rate;
                }

                // Add new shipping item.
                $shipping_item = new WC_Order_Item_Shipping();
                $shipping_item->set_method_title( $rate['label'] );
                $shipping_item->set_method_id( $rate['method_id'] );
                $shipping_item->set_instance_id( $rate['instance_id'] );
                $shipping_item->set_total( $rate['cost'] );

                $order->add_item( $shipping_item );
            }
        }

        $order->calculate_totals();

        return true;
    }

    /**
     * Find a shipping rate by its ID for the given order.
     *
     * @param WC_Order $order     WooCommerce order.
     * @param string   $rate_id   Rate ID (e.g., "flat_rate:1").
     * @return array|WP_Error Rate data or error.
     */
    private static function find_shipping_rate( $order, $rate_id ) {
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

        if ( empty( $package['destination']['country'] ) || empty( $package['destination']['postcode'] ) ) {
            return new WP_Error(
                'shipping_address_required',
                __( 'Shipping address must include country and postcode before selecting a fulfillment option.', 'woocommerce-ucp' ),
                array( 'status' => 409 )
            );
        }

		$shipping = WC()->shipping();
		if ( ! $shipping ) {
			return new WP_Error( 'shipping_unavailable', 'Shipping calculation is not available.', array( 'status' => 500 ) );
		}

		$had_session = isset( WC()->session ) && WC()->session;
		if ( ! $had_session ) {
			WC()->session = new WC_UCP_Runtime_Session();
		}

		try {
			$shipping->calculate_shipping( array( $package ) );
		} catch ( Exception $e ) {
			return new WP_Error(
				'shipping_calculation_failed',
				sprintf( __( 'Unable to calculate shipping rates: %s', 'woocommerce-ucp' ), $e->getMessage() ),
				array( 'status' => 500 )
			);
		} finally {
			if ( ! $had_session ) {
				WC()->session = null;
			}
		}
        $packages = $shipping->get_packages();

        if ( ! empty( $packages[0]['rates'][ $rate_id ] ) ) {
            $rate  = $packages[0]['rates'][ $rate_id ];
            $parts = explode( ':', $rate_id );

            return array(
                'label'       => $rate->get_label(),
                'cost'        => $rate->get_cost(),
                'method_id'   => $parts[0],
                'instance_id' => isset( $parts[1] ) ? $parts[1] : 0,
            );
        }

        return new WP_Error(
            'invalid_fulfillment_option',
            __( 'The selected fulfillment option is not available for the provided address.', 'woocommerce-ucp' ),
            array( 'status' => 400 )
        );
    }
}
