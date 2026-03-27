<?php
/**
 * OAuth consent screen template.
 *
 * Variables passed via WC_UCP_OAuth::render_consent_screen().
 */

defined( 'ABSPATH' ) || exit;

$title = sprintf(
    /* translators: 1: OAuth client name, 2: site name */
    __( '%1$s wants to access your %2$s account', 'woocommerce-ucp' ),
    $client_name,
    $site_name
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title><?php echo esc_html( $title ); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f5f5f7; margin: 0; padding: 2rem; color: #1d2327; }
        .wc-ucp-consent-card { max-width: 520px; margin: 0 auto; background: #fff; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.08); padding: 2rem; }
        .wc-ucp-consent-card h1 { margin-top: 0; font-size: 1.5rem; }
        .wc-ucp-consent-meta { font-size: 0.95rem; margin-bottom: 1.5rem; color: #4a5568; }
        .wc-ucp-scope-list { list-style: none; padding: 0; margin: 0 0 1.5rem; }
        .wc-ucp-scope-list li { margin-bottom: 0.75rem; padding-left: 1rem; position: relative; }
        .wc-ucp-scope-list li::before { content: "•"; position: absolute; left: 0; color: #1d72b8; }
        .wc-ucp-consent-actions { display: flex; gap: 0.75rem; justify-content: flex-end; }
        .wc-ucp-consent-actions button { padding: 0.6rem 1.5rem; border-radius: 6px; border: 1px solid transparent; font-size: 0.95rem; cursor: pointer; }
        .wc-ucp-consent-actions .button-primary { background: #1d72b8; color: #fff; border-color: #1d72b8; }
        .wc-ucp-consent-actions .button-secondary { background: #f7f7f7; color: #1d2327; border-color: #d0d7de; }
        .wc-ucp-consent-footnote { font-size: 0.8rem; color: #6b7280; margin-top: 1.5rem; }
        @media (max-width: 600px) { body { padding: 1rem; } .wc-ucp-consent-card { padding: 1.5rem; } }
    </style>
</head>
<body>
    <div class="wc-ucp-consent-card">
        <h1><?php echo esc_html( $title ); ?></h1>
        <p class="wc-ucp-consent-meta">
            <?php
            printf(
                /* translators: 1: user display name, 2: email */
                esc_html__( 'You are signed in as %1$s (%2$s).', 'woocommerce-ucp' ),
                esc_html( $customer_name ),
                esc_html( $customer_email )
            );
            ?>
        </p>
        <p><?php esc_html_e( 'This application is requesting the following permissions:', 'woocommerce-ucp' ); ?></p>
        <ul class="wc-ucp-scope-list">
            <?php foreach ( $scopes as $scope ) : ?>
                <li>
                    <strong><?php echo esc_html( $scope['id'] ); ?></strong><br />
                    <span><?php echo esc_html( $scope['description'] ); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <form method="post" action="<?php echo esc_url( $form_action ); ?>">
            <?php foreach ( $hidden_fields as $field => $value ) : ?>
                <input type="hidden" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $value ); ?>" />
            <?php endforeach; ?>
            <div class="wc-ucp-consent-actions">
                <button type="submit" name="decision" value="deny" class="button-secondary"><?php esc_html_e( 'Deny', 'woocommerce-ucp' ); ?></button>
                <button type="submit" name="decision" value="approve" class="button-primary"><?php esc_html_e( 'Allow', 'woocommerce-ucp' ); ?></button>
            </div>
        </form>
        <p class="wc-ucp-consent-footnote">
            <?php esc_html_e( 'You can revoke this access later from WooCommerce → Settings → UCP.', 'woocommerce-ucp' ); ?>
        </p>
    </div>
</body>
</html>
