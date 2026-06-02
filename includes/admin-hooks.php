<?php
if (!defined('ABSPATH')) {
    exit;
}

// Handle disconnect connection form submission
if (is_admin() && isset($_POST['zpay_disconnect'])) {
    error_log('Disconnecting Zoho - form submitted');
    $settings    = get_option('woocommerce_zpay_settings', []);
    $data_center = !empty($settings['data_center']) ? $settings['data_center'] : 'in';
    $revoke_url  = 'https://accounts.zoho.' . $data_center . '/oauth/v2/token/revoke';

    $refresh_token = !empty($settings['refresh_token']) ? zpay_decrypt_if_needed($settings['refresh_token']) : '';
    if (!empty($refresh_token)) {
        $response = wp_remote_post($revoke_url, ['body' => ['token' => $refresh_token]]);
        if (is_wp_error($response)) {
            error_log('Zoho refresh token revoke error: ' . $response->get_error_message());
        } else {
            error_log('Zoho refresh token revoked successfully');
        }
    }

    // Always clear tokens locally regardless of revoke API outcome
    $settings['refresh_token'] = '';
    $settings['access_token']  = '';
    update_option('woocommerce_zpay_settings', $settings);

    add_action('admin_notices', function () {
        echo '<div class="notice notice-success"><p>Zoho Payments disconnected successfully.</p></div>';
    });
}

// Add admin scripts for the settings page
add_action('admin_footer', function () {
    ?>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            var btn = document.getElementById("zpay-disconnect-btn");
            if (btn) {
                btn.addEventListener("click", function () {
                    var form = btn.closest("form") || document.querySelector('form');
                    if (form) {
                        var input = document.createElement("input");
                        input.type = "hidden";
                        input.name = "zpay_disconnect";
                        input.value = "1";
                        form.appendChild(input);
                        form.submit();
                    }
                });
            }
        });
    </script>
    <?php
});

// Enqueue admin scripts and styles for the settings page
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook === 'woocommerce_page_wc-settings' && isset($_GET['section']) && $_GET['section'] === 'zpay') {
        wp_enqueue_script(
            'zpay-eye-toggle',
            plugins_url('assets/js/zpay-eye-toggle.js', dirname(__FILE__) . '/../index.php'),
            array('jquery'),
            ZOHO_PAYMENT_GATEWAY_VERSION,
            true
        );
        wp_enqueue_style('dashicons');
    }
});

// Ensure encryption key exists on plugin activation
register_activation_hook(dirname(__FILE__) . '/../index.php', function () {
    if (!get_option('zpay_encryption_key')) {
        $key = bin2hex(random_bytes(32));
        add_option('zpay_encryption_key', $key, '', 'no');
    }
});

