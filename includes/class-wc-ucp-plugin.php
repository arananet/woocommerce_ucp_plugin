<?php
/**
 * Core plugin singleton class.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Plugin {

    /**
     * @var WC_UCP_Plugin|null
     */
    private static $instance = null;

    /**
     * @var WC_UCP_Auth
     */
    public $auth;

    /**
     * @var WC_UCP_Discovery
     */
    public $discovery;

    /**
     * @var WC_UCP_Logger
     */
    public $logger;

    /**
     * Get singleton instance.
     *
     * @return WC_UCP_Plugin
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        if ( is_admin() ) {
            new WC_UCP_Admin();
        }
    }

    /**
     * Initialize plugin components on 'init'.
     */
    public function init() {
        $this->logger    = new WC_UCP_Logger();
        $this->auth      = new WC_UCP_Auth();
        $this->discovery = new WC_UCP_Discovery();

        // Schedule expired session cleanup.
        if ( ! wp_next_scheduled( 'wc_ucp_cleanup_sessions' ) ) {
            wp_schedule_event( time(), 'hourly', 'wc_ucp_cleanup_sessions' );
        }
        add_action( 'wc_ucp_cleanup_sessions', array( 'WC_UCP_Session', 'cleanup_expired' ) );
    }

    /**
     * Register all UCP REST API routes.
     */
    public function register_rest_routes() {
        $checkout_controller = new WC_UCP_Checkout_Controller();
        $checkout_controller->register_routes();

        $product_controller = new WC_UCP_Product_Controller();
        $product_controller->register_routes();

        $customer_controller = new WC_UCP_Customer_Controller();
        $customer_controller->register_routes();

        $payments_controller = new WC_UCP_Payments_Controller();
        $payments_controller->register_routes();

        $mcp_handler = new WC_UCP_MCP_Handler();
        $mcp_handler->register_routes();

        $oauth = new WC_UCP_OAuth();
        $oauth->register_routes();
    }

    /**
     * Get plugin option with default.
     *
     * @param string $key     Option key.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public static function get_option( $key, $default = '' ) {
        $options = get_option( 'wc_ucp_settings', array() );
        return isset( $options[ $key ] ) ? $options[ $key ] : $default;
    }

    /**
     * Update a plugin option.
     *
     * @param string $key   Option key.
     * @param mixed  $value Option value.
     */
    public static function update_option( $key, $value ) {
        $options         = get_option( 'wc_ucp_settings', array() );
        $options[ $key ] = $value;
        update_option( 'wc_ucp_settings', $options );
    }
}
