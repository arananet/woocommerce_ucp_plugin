<?php
/**
 * UCP Admin settings page.
 * Adds a WooCommerce settings tab for UCP configuration.
 *
 * @package WooCommerce_UCP
 */

defined( 'ABSPATH' ) || exit;

class WC_UCP_Admin {

    public function __construct() {
        add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_tab' ), 50 );
        add_action( 'woocommerce_settings_tabs_ucp', array( $this, 'settings_tab' ) );
        add_action( 'woocommerce_update_options_ucp', array( $this, 'update_settings' ) );
        add_action( 'admin_menu', array( $this, 'add_api_keys_page' ) );

        // AJAX handlers for API key management.
        add_action( 'wp_ajax_ucp_generate_api_key', array( $this, 'ajax_generate_api_key' ) );
        add_action( 'wp_ajax_ucp_revoke_api_key', array( $this, 'ajax_revoke_api_key' ) );
    }

    /**
     * Add UCP tab to WooCommerce settings.
     *
     * @param array $tabs Existing tabs.
     * @return array
     */
    public function add_settings_tab( $tabs ) {
        $tabs['ucp'] = __( 'UCP', 'woocommerce-ucp' );
        return $tabs;
    }

    /**
     * Output settings tab content.
     */
    public function settings_tab() {
        woocommerce_admin_fields( $this->get_settings() );

        // API Keys section.
        $this->render_api_keys_section();
    }

    /**
     * Save settings.
     */
    public function update_settings() {
        woocommerce_update_options( $this->get_settings() );
    }

    /**
     * Get settings fields.
     *
     * @return array
     */
    private function get_settings() {
        return array(
            // General section.
            array(
                'title' => __( 'Universal Commerce Protocol (UCP) Settings', 'woocommerce-ucp' ),
                'type'  => 'title',
                'desc'  => __( 'Configure UCP settings for AI agent commerce integration.', 'woocommerce-ucp' ),
                'id'    => 'wc_ucp_general',
            ),
            array(
                'title'   => __( 'Enable UCP', 'woocommerce-ucp' ),
                'desc'    => __( 'Enable Universal Commerce Protocol endpoints.', 'woocommerce-ucp' ),
                'id'      => 'wc_ucp_enabled',
                'default' => 'yes',
                'type'    => 'checkbox',
            ),
            array(
                'title'   => __( 'Public Product Catalog', 'woocommerce-ucp' ),
                'desc'    => __( 'Allow browsing products without authentication.', 'woocommerce-ucp' ),
                'id'      => 'wc_ucp_public_catalog',
                'default' => 'yes',
                'type'    => 'checkbox',
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'wc_ucp_general',
            ),

            // Security section.
            array(
                'title' => __( 'Security Settings', 'woocommerce-ucp' ),
                'type'  => 'title',
                'id'    => 'wc_ucp_security',
            ),
            array(
                'title'             => __( 'Rate Limit (Authenticated)', 'woocommerce-ucp' ),
                'desc'              => __( 'Maximum requests per minute for authenticated agents.', 'woocommerce-ucp' ),
                'id'                => 'wc_ucp_rate_limit_authenticated',
                'default'           => '60',
                'type'              => 'number',
                'custom_attributes' => array( 'min' => '1', 'max' => '1000' ),
            ),
            array(
                'title'             => __( 'Rate Limit (Unauthenticated)', 'woocommerce-ucp' ),
                'desc'              => __( 'Maximum requests per minute for unauthenticated requests.', 'woocommerce-ucp' ),
                'id'                => 'wc_ucp_rate_limit_unauthenticated',
                'default'           => '30',
                'type'              => 'number',
                'custom_attributes' => array( 'min' => '1', 'max' => '500' ),
            ),
            array(
                'title'             => __( 'Session Expiry (Minutes)', 'woocommerce-ucp' ),
                'desc'              => __( 'How long checkout sessions remain active.', 'woocommerce-ucp' ),
                'id'                => 'wc_ucp_session_expiry',
                'default'           => '30',
                'type'              => 'number',
                'custom_attributes' => array( 'min' => '5', 'max' => '120' ),
            ),
            array(
                'title'   => __( 'Allowed Origins (CORS)', 'woocommerce-ucp' ),
                'desc'    => __( 'Comma-separated list of allowed origins. Use * for all.', 'woocommerce-ucp' ),
                'id'      => 'wc_ucp_cors_origins',
                'default' => '*',
                'type'    => 'text',
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'wc_ucp_security',
            ),

            // Capabilities section.
            array(
                'title' => __( 'Capabilities', 'woocommerce-ucp' ),
                'type'  => 'title',
                'desc'  => __( 'Select which UCP capabilities to advertise.', 'woocommerce-ucp' ),
                'id'    => 'wc_ucp_capabilities',
            ),
            array(
                'title'   => __( 'Checkout', 'woocommerce-ucp' ),
                'desc'    => __( 'Enable checkout capability (dev.ucp.shopping.checkout).', 'woocommerce-ucp' ),
                'id'      => 'wc_ucp_cap_checkout',
                'default' => 'yes',
                'type'    => 'checkbox',
            ),
            array(
                'title'   => __( 'Fulfillment', 'woocommerce-ucp' ),
                'desc'    => __( 'Enable fulfillment extension (shipping options).', 'woocommerce-ucp' ),
                'id'      => 'wc_ucp_cap_fulfillment',
                'default' => 'yes',
                'type'    => 'checkbox',
            ),
            array(
                'title'   => __( 'Discounts', 'woocommerce-ucp' ),
                'desc'    => __( 'Enable discount extension (coupon support).', 'woocommerce-ucp' ),
                'id'      => 'wc_ucp_cap_discount',
                'default' => 'yes',
                'type'    => 'checkbox',
            ),
            array(
                'title'   => __( 'MCP Transport', 'woocommerce-ucp' ),
                'desc'    => __( 'Enable Model Context Protocol (JSON-RPC 2.0) transport.', 'woocommerce-ucp' ),
                'id'      => 'wc_ucp_cap_mcp',
                'default' => 'yes',
                'type'    => 'checkbox',
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'wc_ucp_capabilities',
            ),
        );
    }

    /**
     * Render API keys management section.
     */
    private function render_api_keys_section() {
        $keys = WC_UCP_API_Keys::list_all( true );
        ?>
        <h2><?php esc_html_e( 'UCP API Keys', 'woocommerce-ucp' ); ?></h2>
        <p><?php esc_html_e( 'Manage API keys for AI agent authentication.', 'woocommerce-ucp' ); ?></p>

        <table class="widefat striped" id="ucp-api-keys-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Label', 'woocommerce-ucp' ); ?></th>
                    <th><?php esc_html_e( 'Key Prefix', 'woocommerce-ucp' ); ?></th>
                    <th><?php esc_html_e( 'Permissions', 'woocommerce-ucp' ); ?></th>
                    <th><?php esc_html_e( 'Customer', 'woocommerce-ucp' ); ?></th>
                    <th><?php esc_html_e( 'Created', 'woocommerce-ucp' ); ?></th>
                    <th><?php esc_html_e( 'Last Used', 'woocommerce-ucp' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'woocommerce-ucp' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'woocommerce-ucp' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $keys ) ) : ?>
                    <tr><td colspan="8"><?php esc_html_e( 'No API keys created yet.', 'woocommerce-ucp' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $keys as $key ) : ?>
                        <tr>
                            <td><?php echo esc_html( $key->label ); ?></td>
                            <td><code><?php echo esc_html( $key->key_prefix ); ?>...</code></td>
                            <td><?php echo esc_html( $key->permissions ); ?></td>
                            <td><?php echo $key->customer_id ? esc_html( '#' . $key->customer_id ) : '—'; ?></td>
                            <td><?php echo esc_html( $key->created_at ); ?></td>
                            <td><?php echo $key->last_used_at ? esc_html( $key->last_used_at ) : '—'; ?></td>
                            <td><?php echo $key->revoked ? '<span style="color:red;">Revoked</span>' : '<span style="color:green;">Active</span>'; ?></td>
                            <td>
                                <?php if ( ! $key->revoked ) : ?>
                                    <button type="button" class="button ucp-revoke-key" data-key-id="<?php echo absint( $key->id ); ?>"><?php esc_html_e( 'Revoke', 'woocommerce-ucp' ); ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <h3><?php esc_html_e( 'Generate New API Key', 'woocommerce-ucp' ); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="ucp-key-label"><?php esc_html_e( 'Label', 'woocommerce-ucp' ); ?></label></th>
                <td><input type="text" id="ucp-key-label" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., Google Gemini Agent', 'woocommerce-ucp' ); ?>" /></td>
            </tr>
            <tr>
                <th><label for="ucp-key-permissions"><?php esc_html_e( 'Permissions', 'woocommerce-ucp' ); ?></label></th>
                <td>
                    <select id="ucp-key-permissions">
                        <option value="read"><?php esc_html_e( 'Read', 'woocommerce-ucp' ); ?></option>
                        <option value="read_write" selected><?php esc_html_e( 'Read/Write', 'woocommerce-ucp' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="ucp-key-customer"><?php esc_html_e( 'Customer ID (optional)', 'woocommerce-ucp' ); ?></label></th>
                <td><input type="number" id="ucp-key-customer" min="0" value="0" /></td>
            </tr>
        </table>
        <p>
            <button type="button" class="button button-primary" id="ucp-generate-key"><?php esc_html_e( 'Generate API Key', 'woocommerce-ucp' ); ?></button>
        </p>
        <div id="ucp-new-key-result" style="display:none; background:#f0f0f1; padding:12px; margin-top:12px; border-left:4px solid #00a32a;">
            <strong><?php esc_html_e( 'New API Key (copy now — it will not be shown again):', 'woocommerce-ucp' ); ?></strong>
            <code id="ucp-new-key-value" style="display:block; margin-top:8px; word-break:break-all;"></code>
        </div>

        <script>
        jQuery(function($) {
            $('#ucp-generate-key').on('click', function() {
                var label = $('#ucp-key-label').val();
                if (!label) { alert('Please enter a label.'); return; }

                $.post(ajaxurl, {
                    action: 'ucp_generate_api_key',
                    label: label,
                    permissions: $('#ucp-key-permissions').val(),
                    customer_id: $('#ucp-key-customer').val(),
                    _wpnonce: '<?php echo esc_js( wp_create_nonce( 'ucp_api_key' ) ); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#ucp-new-key-value').text(response.data.key);
                        $('#ucp-new-key-result').show();
                        // Clear form fields
                        $('#ucp-key-label').val('');
                        $('#ucp-key-customer').val('0');
                        // Note: Table will be updated on next page load
                    } else {
                        alert(response.data || 'Error generating key.');
                    }
                });
            });

            $('.ucp-revoke-key').on('click', function() {
                if (!confirm('Revoke this API key?')) return;

                $.post(ajaxurl, {
                    action: 'ucp_revoke_api_key',
                    key_id: $(this).data('key-id'),
                    _wpnonce: '<?php echo esc_js( wp_create_nonce( 'ucp_api_key' ) ); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data || 'Error revoking key.');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Add API keys subpage under WooCommerce.
     */
    public function add_api_keys_page() {
        // Handled within WooCommerce settings tab.
    }

    /**
     * AJAX: Generate new API key.
     */
    public function ajax_generate_api_key() {
        check_ajax_referer( 'ucp_api_key' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        $label       = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
        $permissions = sanitize_text_field( wp_unslash( $_POST['permissions'] ?? 'read_write' ) );
        $customer_id = absint( $_POST['customer_id'] ?? 0 );

        if ( empty( $label ) ) {
            wp_send_json_error( 'Label is required.' );
        }

        $result = WC_UCP_API_Keys::generate( $label, $permissions, $customer_id );

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Revoke API key.
     */
    public function ajax_revoke_api_key() {
        check_ajax_referer( 'ucp_api_key' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        $key_id = absint( $_POST['key_id'] ?? 0 );

        if ( ! $key_id ) {
            wp_send_json_error( 'Invalid key ID.' );
        }

        WC_UCP_API_Keys::revoke( $key_id );

        wp_send_json_success();
    }
}
