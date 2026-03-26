<?php
/**
 * Plugin Name: WooCommerce UCP - Universal Commerce Protocol
 * Plugin URI:  https://github.com/arananet/woocommerce_ucp_plugin
 * Description: Implements the Universal Commerce Protocol (UCP) for WooCommerce, enabling AI agents to discover, negotiate, and transact with your store via standardized REST and MCP APIs. Includes AP2 payment support.
 * Version:     1.0.0
 * Author:      Eduardo Arana & Soda
 * Author URI:  https://github.com/arananet
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woocommerce-ucp
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_UCP_VERSION', '1.0.0' );
define( 'WC_UCP_SPEC_VERSION', '2026-01-23' );
define( 'WC_UCP_PLUGIN_FILE', __FILE__ );
define( 'WC_UCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_UCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_UCP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check if WooCommerce is active before initializing.
 */
function wc_ucp_check_dependencies() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'wc_ucp_missing_woocommerce_notice' );
        return false;
    }
    return true;
}

/**
 * Admin notice when WooCommerce is not active.
 */
function wc_ucp_missing_woocommerce_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php esc_html_e( 'WooCommerce UCP requires WooCommerce to be installed and active.', 'woocommerce-ucp' ); ?></p>
    </div>
    <?php
}

/**
 * Autoload plugin classes.
 */
function wc_ucp_autoloader( $class_name ) {
    $prefix = 'WC_UCP_';

    if ( 0 !== strpos( $class_name, $prefix ) ) {
        return;
    }

    $relative_class = substr( $class_name, strlen( $prefix ) );
    $file_name      = 'class-wc-ucp-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';

    $directories = array(
        WC_UCP_PLUGIN_DIR . 'includes/',
        WC_UCP_PLUGIN_DIR . 'includes/discovery/',
        WC_UCP_PLUGIN_DIR . 'includes/rest/',
        WC_UCP_PLUGIN_DIR . 'includes/mcp/',
        WC_UCP_PLUGIN_DIR . 'includes/checkout/',
        WC_UCP_PLUGIN_DIR . 'includes/auth/',
        WC_UCP_PLUGIN_DIR . 'includes/payments/',
        WC_UCP_PLUGIN_DIR . 'includes/security/',
        WC_UCP_PLUGIN_DIR . 'admin/',
    );

    foreach ( $directories as $directory ) {
        $file = $directory . $file_name;
        if ( file_exists( $file ) ) {
            require_once $file;
            return;
        }
    }
}
spl_autoload_register( 'wc_ucp_autoloader' );

/**
 * Plugin activation.
 */
function wc_ucp_activate() {
    WC_UCP_Activator::activate();
}
register_activation_hook( __FILE__, 'wc_ucp_activate' );

/**
 * Plugin deactivation.
 */
function wc_ucp_deactivate() {
    WC_UCP_Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'wc_ucp_deactivate' );

/**
 * Initialize plugin after all plugins are loaded.
 */
function wc_ucp_init() {
    if ( ! wc_ucp_check_dependencies() ) {
        return;
    }

    WC_UCP_Plugin::instance();
}
add_action( 'plugins_loaded', 'wc_ucp_init' );
