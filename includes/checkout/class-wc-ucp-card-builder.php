<?php
/**
 * UCP Card response builder.
 * Converts WooCommerce order data into UCP-compliant checkout response objects.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Card_Builder {

    /**
     * Build a complete UCP Card from a WC order and session.
     *
     * @param WC_Order $order   WooCommerce order.
     * @param object   $session UCP session row.
     * @param array    $messages Validation messages.
     * @return array UCP Card data.
     */
    public static function build( $order, $session, $messages = array() ) {
        $card = array(
            'ucp'         => self::build_ucp_block(),
            'id'          => $session->session_key,
            'status'      => $session->status,
            'currency'    => $order->get_currency(),
            'buyer'       => self::build_buyer( $order ),
            'line_items'  => self::build_line_items( $order ),
            'totals'      => self::build_totals( $order ),
            'links'       => self::build_links( $session ),
        );

        // Add fulfillment if applicable.
        $fulfillment = self::build_fulfillment( $order );
        if ( ! empty( $fulfillment ) ) {
            $card['fulfillment'] = $fulfillment;
        }

        // Add discount info if applicable.
        if ( floatval( $order->get_discount_total() ) > 0 ) {
            $card['discounts'] = self::build_discounts( $order );
        }

        // Add messages if any.
        if ( ! empty( $messages ) ) {
            $card['messages'] = $messages;
        }

        // Add order info if completed.
        if ( 'completed' === $session->status ) {
            $card['order'] = array(
                'id'        => $order->get_id(),
                'number'    => $order->get_order_number(),
                'permalink' => $order->get_view_order_url(),
            );
        }

        return apply_filters( 'wc_ucp_card', $card, $order, $session );
    }

    /**
     * Build UCP metadata block.
     *
     * @return array
     */
    private static function build_ucp_block() {
        return array(
            'version'      => WC_UCP_SPEC_VERSION,
            'capabilities' => array( 'dev.ucp.shopping.checkout' ),
            'extensions'   => array(
                'dev.ucp.shopping.fulfillment',
                'dev.ucp.shopping.discount',
            ),
        );
    }

    /**
     * Build buyer information.
     *
     * @param WC_Order $order WooCommerce order.
     * @return array
     */
    private static function build_buyer( $order ) {
        $buyer = array();

        if ( $order->get_billing_email() ) {
            $buyer['email'] = $order->get_billing_email();
        }
        if ( $order->get_billing_first_name() ) {
            $buyer['first_name'] = $order->get_billing_first_name();
        }
        if ( $order->get_billing_last_name() ) {
            $buyer['last_name'] = $order->get_billing_last_name();
        }
        if ( $order->get_billing_phone() ) {
            $buyer['phone'] = $order->get_billing_phone();
        }

        // Shipping address.
        if ( $order->get_shipping_address_1() ) {
            $buyer['shipping_address'] = array(
                'address_1' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
                'city'      => $order->get_shipping_city(),
                'state'     => $order->get_shipping_state(),
                'postcode'  => $order->get_shipping_postcode(),
                'country'   => $order->get_shipping_country(),
            );
            if ( $order->get_shipping_company() ) {
                $buyer['shipping_address']['company'] = $order->get_shipping_company();
            }
        }

        // Billing address.
        if ( $order->get_billing_address_1() ) {
            $buyer['billing_address'] = array(
                'address_1' => $order->get_billing_address_1(),
                'address_2' => $order->get_billing_address_2(),
                'city'      => $order->get_billing_city(),
                'state'     => $order->get_billing_state(),
                'postcode'  => $order->get_billing_postcode(),
                'country'   => $order->get_billing_country(),
            );
        }

        return $buyer;
    }

    /**
     * Build line items array.
     *
     * @param WC_Order $order WooCommerce order.
     * @return array
     */
    private static function build_line_items( $order ) {
        $line_items = array();

        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();

            $li = array(
                'id'   => 'li_' . $item_id,
                'item' => array(
                    'id'    => strval( $product ? $product->get_id() : $item->get_product_id() ),
                    'title' => $item->get_name(),
                ),
                'quantity' => $item->get_quantity(),
                'totals'   => array(
                    'subtotal' => wc_format_decimal( $item->get_subtotal(), 2 ),
                    'total'    => wc_format_decimal( $item->get_total(), 2 ),
                    'tax'      => wc_format_decimal( $item->get_total_tax(), 2 ),
                ),
            );

            if ( $product ) {
                $li['item']['price'] = array(
                    'amount'   => wc_format_decimal( $product->get_price(), 2 ),
                    'currency' => $order->get_currency(),
                );

                if ( $product->get_short_description() ) {
                    $li['item']['description'] = wp_strip_all_tags( $product->get_short_description() );
                }

                $image_id = $product->get_image_id();
                if ( $image_id ) {
                    $image_url = wp_get_attachment_image_url( $image_id, 'medium' );
                    if ( $image_url ) {
                        $li['item']['image_url'] = $image_url;
                    }
                }

                $li['item']['sku']          = $product->get_sku();
                $li['item']['stock_status'] = $product->is_in_stock() ? 'in_stock' : 'out_of_stock';
            }

            $line_items[] = $li;
        }

        return $line_items;
    }

    /**
     * Build order totals.
     *
     * @param WC_Order $order WooCommerce order.
     * @return array
     */
    private static function build_totals( $order ) {
        return array(
            'subtotal' => wc_format_decimal( $order->get_subtotal(), 2 ),
            'shipping' => wc_format_decimal( $order->get_shipping_total(), 2 ),
            'tax'      => wc_format_decimal( $order->get_total_tax(), 2 ),
            'discount' => wc_format_decimal( $order->get_discount_total(), 2 ),
            'total'    => wc_format_decimal( $order->get_total(), 2 ),
        );
    }

    /**
     * Build fulfillment (shipping) data.
     *
     * @param WC_Order $order WooCommerce order.
     * @return array|null
     */
    private static function build_fulfillment( $order ) {
        $needs_shipping = false;
        $line_item_ids  = array();

        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            if ( $product && $product->needs_shipping() ) {
                $needs_shipping  = true;
                $line_item_ids[] = 'li_' . $item_id;
            }
        }

        if ( ! $needs_shipping ) {
            return null;
        }

        $fulfillment = array(
            'methods' => array(
                array(
                    'id'            => 'shipping_1',
                    'line_item_ids' => $line_item_ids,
                    'groups'        => array(),
                ),
            ),
        );

        // Get available shipping options if address is set.
        if ( $order->get_shipping_country() ) {
            $options = self::get_shipping_options( $order );
            $selected_methods = $order->get_shipping_methods();
            $selected_id = null;

            if ( ! empty( $selected_methods ) ) {
                $first_method = reset( $selected_methods );
                $selected_id  = $first_method->get_method_id() . ':' . $first_method->get_instance_id();
            }

            $fulfillment['methods'][0]['groups'][] = array(
                'id'                 => 'package_1',
                'options'            => $options,
                'selected_option_id' => $selected_id,
            );
        }

        return $fulfillment;
    }

    /**
     * Get available shipping options for an order.
     *
     * @param WC_Order $order WooCommerce order.
     * @return array
     */
    private static function get_shipping_options( $order ) {
        $options = array();

        $package = array(
            'destination' => array(
                'country'  => $order->get_shipping_country(),
                'state'    => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'city'     => $order->get_shipping_city(),
            ),
            'contents'       => array(),
            'contents_cost'  => 0,
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

        $shipping = WC()->shipping();
        if ( $shipping ) {
            $shipping->calculate_shipping( array( $package ) );
            $packages = $shipping->get_packages();

            if ( ! empty( $packages[0]['rates'] ) ) {
                foreach ( $packages[0]['rates'] as $rate ) {
                    $options[] = array(
                        'id'     => $rate->get_id(),
                        'label'  => $rate->get_label(),
                        'amount' => wc_format_decimal( $rate->get_cost(), 2 ),
                    );
                }
            }
        }

        return $options;
    }

    /**
     * Build discount information.
     *
     * @param WC_Order $order WooCommerce order.
     * @return array
     */
    private static function build_discounts( $order ) {
        $discounts = array();

        foreach ( $order->get_coupon_codes() as $code ) {
            $discounts[] = array(
                'code'   => $code,
                'amount' => wc_format_decimal(
                    $order->get_discount_total(),
                    2
                ),
            );
        }

        return $discounts;
    }

    /**
     * Build links section.
     *
     * @param object $session UCP session row.
     * @return array
     */
    private static function build_links( $session ) {
        $links = array(
            'self' => rest_url( 'ucp/v1/checkout-sessions/' . $session->session_key ),
        );

        // Add continue_url for escalation.
        if ( in_array( $session->status, array( 'incomplete', 'requires_escalation' ), true ) ) {
            $links['continue_url'] = add_query_arg(
                'ucp_session',
                $session->session_key,
                wc_get_checkout_url()
            );
        }

        // Add order URL if completed.
        if ( 'completed' === $session->status && $session->wc_order_id ) {
            $order = wc_get_order( $session->wc_order_id );
            if ( $order ) {
                $links['order'] = $order->get_view_order_url();
            }
        }

        return $links;
    }
}
