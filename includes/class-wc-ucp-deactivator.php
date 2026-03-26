<?php
/**
 * Plugin deactivation handler.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Deactivator {

    /**
     * Run on plugin deactivation.
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }
}
