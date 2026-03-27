<?php
/**
 * Input validation and sanitization for UCP requests.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Validator {

    /**
     * Valid ISO 4217 currency codes (subset of most common).
     */
    const VALID_CURRENCIES = array(
        'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'CNY', 'SEK',
        'NZD', 'MXN', 'SGD', 'HKD', 'NOK', 'KRW', 'TRY', 'INR', 'RUB',
        'BRL', 'ZAR', 'DKK', 'PLN', 'TWD', 'THB', 'MYR', 'CZK', 'HUF',
        'CLP', 'PHP', 'AED', 'COP', 'SAR', 'RON', 'ILS', 'ARS', 'BGN',
    );

    /**
     * Valid checkout session statuses.
     */
    const VALID_STATUSES = array(
        'incomplete',
        'requires_escalation',
        'ready_for_complete',
        'completed',
        'canceled',
    );

    /**
     * Validate and sanitize a checkout creation request.
     *
     * @param array $data Raw request data.
     * @return array|WP_Error Sanitized data or error.
     */
    public static function validate_checkout_create( $data ) {
        $errors = array();

        $data = self::normalize_line_items_input( $data );

        if ( empty( $data['line_items'] ) || ! is_array( $data['line_items'] ) ) {
            $errors[] = array(
                'type'     => 'validation',
                'code'     => 'missing_field',
                'path'     => 'line_items',
                'content'  => 'At least one line item is required.',
                'severity' => 'recoverable',
            );
        } else {
            foreach ( $data['line_items'] as $index => $item ) {
                if ( empty( $item['item']['id'] ) ) {
                    $errors[] = array(
                        'type'     => 'validation',
                        'code'     => 'missing_field',
                        'path'     => "line_items[{$index}].item.id",
                        'content'  => 'Item ID is required.',
                        'severity' => 'recoverable',
                    );
                }
                if ( empty( $item['quantity'] ) || ! is_numeric( $item['quantity'] ) || intval( $item['quantity'] ) < 1 ) {
                    $errors[] = array(
                        'type'     => 'validation',
                        'code'     => 'invalid_value',
                        'path'     => "line_items[{$index}].quantity",
                        'content'  => 'Quantity must be a positive integer.',
                        'severity' => 'recoverable',
                    );
                }
            }
        }

        if ( ! empty( $data['currency'] ) ) {
            $currency = strtoupper( sanitize_text_field( $data['currency'] ) );
            if ( ! in_array( $currency, self::VALID_CURRENCIES, true ) ) {
                $errors[] = array(
                    'type'     => 'validation',
                    'code'     => 'invalid_value',
                    'path'     => 'currency',
                    'content'  => 'Invalid ISO 4217 currency code.',
                    'severity' => 'recoverable',
                );
            }
        }

        if ( ! empty( $errors ) ) {
            return new WP_Error( 'validation_failed', 'Validation failed.', array(
                'status'   => 400,
                'messages' => $errors,
            ) );
        }

        return self::sanitize_checkout_data( $data );
    }

    /**
     * Validate a checkout update request.
     *
     * @param array $data Raw request data.
     * @return array|WP_Error Sanitized data or error.
     */
    public static function validate_checkout_update( $data ) {
        return self::sanitize_checkout_data( $data );
    }

    /**
     * Sanitize checkout data recursively.
     *
     * @param array $data Raw data.
     * @return array Sanitized data.
     */
    public static function sanitize_checkout_data( $data ) {
        $sanitized = array();

        if ( isset( $data['buyer'] ) && is_array( $data['buyer'] ) ) {
            $sanitized['buyer'] = self::sanitize_buyer( $data['buyer'] );
        }

        if ( isset( $data['line_items'] ) && is_array( $data['line_items'] ) ) {
            $sanitized['line_items'] = array();
            foreach ( $data['line_items'] as $item ) {
                $sanitized['line_items'][] = self::sanitize_line_item( $item );
            }
        }

        if ( isset( $data['currency'] ) ) {
            $sanitized['currency'] = strtoupper( sanitize_text_field( $data['currency'] ) );
        }

        if ( isset( $data['fulfillment'] ) && is_array( $data['fulfillment'] ) ) {
            $sanitized['fulfillment'] = self::sanitize_fulfillment( $data['fulfillment'] );
        }

        if ( isset( $data['discount_codes'] ) && is_array( $data['discount_codes'] ) ) {
            $sanitized['discount_codes'] = array_map( 'sanitize_text_field', $data['discount_codes'] );
        }

        if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
            $sanitized['meta'] = $data['meta'];
        }

        return $sanitized;
    }

    /**
     * Sanitize buyer data.
     *
     * @param array $buyer Raw buyer data.
     * @return array
     */
    public static function sanitize_buyer( $buyer ) {
        $sanitized = array();

        $text_fields = array( 'first_name', 'last_name', 'phone' );
        foreach ( $text_fields as $field ) {
            if ( isset( $buyer[ $field ] ) ) {
                $sanitized[ $field ] = sanitize_text_field( $buyer[ $field ] );
            }
        }

        if ( isset( $buyer['email'] ) ) {
            $sanitized['email'] = sanitize_email( $buyer['email'] );
        }

        if ( isset( $buyer['shipping_address'] ) && is_array( $buyer['shipping_address'] ) ) {
            $sanitized['shipping_address'] = self::sanitize_address( $buyer['shipping_address'] );
        }

        if ( isset( $buyer['billing_address'] ) && is_array( $buyer['billing_address'] ) ) {
            $sanitized['billing_address'] = self::sanitize_address( $buyer['billing_address'] );
        }

        return $sanitized;
    }

    /**
     * Sanitize address data.
     *
     * @param array $address Raw address.
     * @return array
     */
    public static function sanitize_address( $address ) {
        $fields    = array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'company' );
        $sanitized = array();

        foreach ( $fields as $field ) {
            if ( isset( $address[ $field ] ) ) {
                $sanitized[ $field ] = sanitize_text_field( $address[ $field ] );
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize a line item.
     *
     * @param array $item Raw line item.
     * @return array
     */
    public static function sanitize_line_item( $item ) {
        $sanitized = array();

        $item = self::normalize_line_item_structure( $item );

        if ( isset( $item['item']['id'] ) ) {
            $sanitized['item'] = array(
                'id' => sanitize_text_field( $item['item']['id'] ),
            );
        }

        if ( isset( $item['quantity'] ) ) {
            $sanitized['quantity'] = absint( $item['quantity'] );
        }

        return $sanitized;
    }

    /**
     * Sanitize fulfillment data.
     *
     * @param array $fulfillment Raw fulfillment data.
     * @return array
     */
    public static function sanitize_fulfillment( $fulfillment ) {
        $sanitized = array();

        if ( isset( $fulfillment['methods'] ) && is_array( $fulfillment['methods'] ) ) {
            $sanitized['methods'] = array();
            foreach ( $fulfillment['methods'] as $method ) {
                $m = array();
                if ( isset( $method['id'] ) ) {
                    $m['id'] = sanitize_text_field( $method['id'] );
                }
                if ( isset( $method['groups'] ) && is_array( $method['groups'] ) ) {
                    $m['groups'] = array();
                    foreach ( $method['groups'] as $group ) {
                        $g = array();
                        if ( isset( $group['id'] ) ) {
                            $g['id'] = sanitize_text_field( $group['id'] );
                        }
                        if ( isset( $group['selected_option_id'] ) ) {
                            $g['selected_option_id'] = sanitize_text_field( $group['selected_option_id'] );
                        }
                        $m['groups'][] = $g;
                    }
                }
                $sanitized['methods'][] = $m;
            }
        }

        return $sanitized;
    }

    /**
     * Validate an email address.
     *
     * @param string $email Email to validate.
     * @return bool
     */
    public static function is_valid_email( $email ) {
        return is_email( $email ) !== false;
    }

    /**
     * Validate a product ID exists and is purchasable.
     *
     * @param int|string $product_id Product ID.
     * @return WC_Product|false
     */
    public static function validate_product( $product_id ) {
        $product = wc_get_product( $product_id );

        if ( ! $product || ! $product->is_purchasable() ) {
            return false;
        }

        return $product;
    }

    /**
     * Normalize shorthand line item structures into canonical shape.
     *
     * Accepts either {"id": 123} or {"item": {"id": 123}} and converts to the latter.
     *
     * @param array $item Raw line item data.
     * @return array
     */
    private static function normalize_line_item_structure( $item ) {
        if ( ! is_array( $item ) ) {
            return $item;
        }

        if ( isset( $item['item'] ) && is_array( $item['item'] ) && isset( $item['item']['id'] ) ) {
            return $item;
        }

        if ( ! isset( $item['item'] ) || ! is_array( $item['item'] ) ) {
            $item['item'] = array();
        }

        if ( isset( $item['id'] ) && ! isset( $item['item']['id'] ) ) {
            $item['item']['id'] = $item['id'];
            unset( $item['id'] );
        }

        if ( isset( $item['product_id'] ) && ! isset( $item['item']['id'] ) ) {
            $item['item']['id'] = $item['product_id'];
        }

        return $item;
    }

    /**
     * Normalize line_item entries on incoming payloads.
     *
     * @param array $data Raw request data.
     * @return array
     */
    private static function normalize_line_items_input( $data ) {
        if ( empty( $data['line_items'] ) || ! is_array( $data['line_items'] ) ) {
            return $data;
        }

        foreach ( $data['line_items'] as $index => $item ) {
            $data['line_items'][ $index ] = self::normalize_line_item_structure( $item );
        }

        return $data;
    }
}
