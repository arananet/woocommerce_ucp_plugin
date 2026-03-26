<?php
/**
 * UCP Product catalog REST controller.
 * Provides read-only access to WooCommerce products for AI agents.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Product_Controller extends WC_UCP_REST_Controller {

    protected $rest_base = 'products';

    /**
     * Register REST routes.
     */
    public function register_routes() {
        // GET /products — List products.
        register_rest_route( $this->namespace, '/' . $this->rest_base, array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'list_products' ),
                'permission_callback' => array( $this, 'check_public_permission' ),
                'args'                => $this->get_collection_params(),
            ),
        ) );

        // GET /products/{id} — Get single product.
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_product' ),
                'permission_callback' => array( $this, 'check_public_permission' ),
            ),
        ) );
    }

    /**
     * List products.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function list_products( $request ) {
        $args = array(
            'status'   => 'publish',
            'limit'    => min( absint( $request->get_param( 'per_page' ) ?: 20 ), 100 ),
            'page'     => max( absint( $request->get_param( 'page' ) ?: 1 ), 1 ),
            'orderby'  => 'date',
            'order'    => 'DESC',
        );

        if ( $request->get_param( 'search' ) ) {
            $args['s'] = sanitize_text_field( $request->get_param( 'search' ) );
        }

        if ( $request->get_param( 'category' ) ) {
            $args['category'] = array( sanitize_text_field( $request->get_param( 'category' ) ) );
        }

        if ( $request->get_param( 'in_stock' ) ) {
            $args['stock_status'] = 'instock';
        }

        if ( $request->get_param( 'min_price' ) ) {
            $args['min_price'] = floatval( $request->get_param( 'min_price' ) );
        }

        if ( $request->get_param( 'max_price' ) ) {
            $args['max_price'] = floatval( $request->get_param( 'max_price' ) );
        }

        $products = wc_get_products( $args );
        $data     = array();

        foreach ( $products as $product ) {
            $data[] = $this->format_product( $product );
        }

        $response = $this->ucp_response( array(
            'products'   => $data,
            'pagination' => array(
                'page'     => $args['page'],
                'per_page' => $args['limit'],
                'total'    => count( $data ),
            ),
        ) );

        return $response;
    }

    /**
     * Get a single product.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function get_product( $request ) {
        $product_id = absint( $request->get_param( 'id' ) );
        $product    = wc_get_product( $product_id );

        if ( ! $product || 'publish' !== $product->get_status() ) {
            return $this->ucp_error( 'product_not_found', 'Product not found.', 404 );
        }

        $data = $this->format_product( $product, true );

        return $this->ucp_response( $data );
    }

    /**
     * Format a WC product for UCP response.
     *
     * @param WC_Product $product     WooCommerce product.
     * @param bool       $full_detail Include full details (variations, etc.).
     * @return array
     */
    private function format_product( $product, $full_detail = false ) {
        $data = array(
            'id'           => strval( $product->get_id() ),
            'title'        => $product->get_name(),
            'description'  => wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ),
            'price'        => array(
                'amount'   => wc_format_decimal( $product->get_price(), 2 ),
                'currency' => get_woocommerce_currency(),
            ),
            'sku'          => $product->get_sku(),
            'stock_status' => $product->is_in_stock() ? 'in_stock' : 'out_of_stock',
            'url'          => $product->get_permalink(),
            'type'         => $product->get_type(),
        );

        // Images.
        $images = array();
        $image_id = $product->get_image_id();
        if ( $image_id ) {
            $url = wp_get_attachment_image_url( $image_id, 'large' );
            if ( $url ) {
                $images[] = $url;
            }
        }
        foreach ( $product->get_gallery_image_ids() as $gal_id ) {
            $url = wp_get_attachment_image_url( $gal_id, 'large' );
            if ( $url ) {
                $images[] = $url;
            }
        }
        $data['images'] = $images;

        // Categories.
        $categories = array();
        foreach ( $product->get_category_ids() as $cat_id ) {
            $term = get_term( $cat_id, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $categories[] = array(
                    'id'   => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                );
            }
        }
        $data['categories'] = $categories;

        // Price range for variable products.
        if ( $product->is_type( 'variable' ) ) {
            $data['price_range'] = array(
                'min' => wc_format_decimal( $product->get_variation_price( 'min' ), 2 ),
                'max' => wc_format_decimal( $product->get_variation_price( 'max' ), 2 ),
            );
        }

        // Stock quantity.
        if ( $product->managing_stock() ) {
            $data['stock_quantity'] = $product->get_stock_quantity();
        }

        // Weight and dimensions.
        if ( $product->has_weight() ) {
            $data['weight'] = array(
                'value' => $product->get_weight(),
                'unit'  => get_option( 'woocommerce_weight_unit' ),
            );
        }

        if ( $product->has_dimensions() ) {
            $data['dimensions'] = array(
                'length' => $product->get_length(),
                'width'  => $product->get_width(),
                'height' => $product->get_height(),
                'unit'   => get_option( 'woocommerce_dimension_unit' ),
            );
        }

        // Full detail: variations.
        if ( $full_detail && $product->is_type( 'variable' ) ) {
            $data['variations'] = array();
            $variations = $product->get_available_variations();
            foreach ( $variations as $variation ) {
                $var_product = wc_get_product( $variation['variation_id'] );
                if ( $var_product ) {
                    $data['variations'][] = array(
                        'id'           => strval( $variation['variation_id'] ),
                        'attributes'   => $variation['attributes'],
                        'price'        => array(
                            'amount'   => wc_format_decimal( $var_product->get_price(), 2 ),
                            'currency' => get_woocommerce_currency(),
                        ),
                        'sku'          => $var_product->get_sku(),
                        'stock_status' => $var_product->is_in_stock() ? 'in_stock' : 'out_of_stock',
                    );
                }
            }

            // Attributes.
            $data['attributes'] = array();
            foreach ( $product->get_variation_attributes() as $attr_name => $options ) {
                $data['attributes'][] = array(
                    'name'    => wc_attribute_label( $attr_name ),
                    'options' => array_values( $options ),
                );
            }
        }

        // Full detail: long description.
        if ( $full_detail ) {
            $data['long_description'] = wp_strip_all_tags( $product->get_description() );
        }

        return $data;
    }

    /**
     * Get collection query parameters.
     *
     * @return array
     */
    public function get_collection_params() {
        return array(
            'search'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
            'category'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
            'per_page'  => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
            'page'      => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
            'in_stock'  => array( 'type' => 'boolean', 'default' => false ),
            'min_price' => array( 'type' => 'number' ),
            'max_price' => array( 'type' => 'number' ),
        );
    }
}
