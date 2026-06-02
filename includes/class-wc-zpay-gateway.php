<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WC_ZPay')) {
    class WC_ZPay extends WC_Payment_Gateway
    {
        public $title;
        public $description;
        public $client_id;
        public $client_secret;
        public $account_id;
        public $api_key;
        public $signing_key;
        public $w_signing_key;
        public $user_details;
        public $api_handler;
        public $refresh_token;
        public $access_token;
        public $data_center;
        public $sandbox_enabled;
        public $business;
        public $business_desc;

        private function is_sandbox_enabled()
        {
            return $this->sandbox_enabled === 'yes';
        }

        private function get_widget_domain()
        {
            if ($this->is_sandbox_enabled()) {
                return 'IN';
            }

            $dc_map = ['in' => 'IN', 'com' => 'US'];
            return $dc_map[$this->data_center] ?? 'IN';
        }

        public function get_zoho_auth_status_html()
        {
            $refresh_token = $this->get_option('refresh_token');
            $access_token = $this->get_option('access_token');
            $is_connected = !empty($refresh_token) && !empty($access_token);

            $logo_url = plugins_url('images/zpay-logo.svg', dirname(__FILE__) . '/../index.php');
            $connect_url = esc_url($this->get_zoho_oauth_url());

            $status_html = $is_connected
                ? '<div style="display:flex;align-items:center;gap:12px;margin-bottom:24px;"><span style="font-weight:700;color:#27ae60;font-size:18px;">Connected</span></div>'
                : '<div style="display:flex;align-items:center;gap:12px;margin-bottom:24px;"><span style="font-weight:500;color:#e74c3c;font-size:18px;">Not Connected</span></br></div>';

            $connect_btn = '<a href="' . $connect_url . '" class="button button-primary" style="font-size: 15px; border-radius: 12px; font-weight: 300; background: #356bb3; color: #fff; border: none;">Connect</a>';
            $disconnect_btn = $is_connected
                ? '<button type="button" id="zpay-disconnect-btn" class="button" style="background:#d3544b;color:#fff;font-size:15px;border-radius:12px;font-weight:300;border:none;">Disconnect</button>'
                : '';

            return '<div style="background:#fff;border-radius:24px;box-shadow:0 4px 24px rgba(0,0,0,0.08);max-width:480px;margin:40px 0;border:none;font-family:\'Segoe UI\', Arial, sans-serif;overflow:hidden;"><div style="background:#f6f8fa;padding:32px 40px 24px 40px;display:flex;align-items:center;gap:20px;border-bottom:1px solid #eee;"><img src="' . esc_url($logo_url) . '" alt="Zoho Payments" style="height:56px;width:auto;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.08);background:#fff;"><span style="font-size:32px;font-weight:700;color:#222;">Zoho Payments</span></div><div style="padding:36px 40px 40px 40px;display:flex;flex-direction:column;align-items:flex-start;gap:32px;background:#fff;">' . $status_html . '<div style="display:flex;gap:24px;">' . $connect_btn . $disconnect_btn . '</div></div></div>';
        }

        public function process_admin_options()
        {
            $data_center_key = $this->get_field_key('data_center');
            $sandbox_enabled_key = $this->get_field_key('sandbox_enabled');
            $data_center = isset($_POST[$data_center_key]) ? wc_clean(wp_unslash($_POST[$data_center_key])) : 'in';
            $sandbox_enabled = isset($_POST[$sandbox_enabled_key]) ? 'yes' : 'no';

            if ($data_center !== 'in') {
                $sandbox_enabled = 'no';
                unset($_POST[$sandbox_enabled_key]);
            }

            $previous_settings = get_option($this->get_option_key(), []);
            $previous_environment = [
                'data_center' => $previous_settings['data_center'] ?? 'in',
                'sandbox_enabled' => $previous_settings['sandbox_enabled'] ?? 'no',
            ];
            $new_environment = [
                'data_center' => $data_center,
                'sandbox_enabled' => $sandbox_enabled,
            ];

            parent::process_admin_options();
            $settings = get_option($this->get_option_key());

            if ($previous_environment !== $new_environment) {
                $settings['access_token'] = '';
                $settings['refresh_token'] = '';

                if (class_exists('WC_Admin_Settings')) {
                    WC_Admin_Settings::add_message(__('Zoho Payments environment changed. Please reconnect your Zoho account.', ZOHO_PAYMENT_GATEWAY_DOMAIN));
                }
            }

            foreach (['client_id', 'client_secret', 'api_key', 'signing_key', 'w_signing_key', 'refresh_token', 'access_token'] as $field) {
                if (!empty($settings[$field]) && strpos($settings[$field], 'ENC:') !== 0) {
                    $settings[$field] = 'ENC:' . zpay_encrypt($settings[$field]);
                }
            }
            update_option($this->get_option_key(), $settings);
        }

        public function get_option($key, $empty_value = null)
        {
            $value = parent::get_option($key, $empty_value);
            if (is_admin() && strpos($value, 'ENC:') === 0) {
                return zpay_decrypt(substr($value, 4));
            }
            return $value;
        }

        public function receipt_page($order_id)
        {
            static $executed = false;
            if ($executed) {
                return;
            }
            $executed = true;

            $order = wc_get_order($order_id);
            $payment_result = $this->api_handler->complete_payment($order, $this->user_details);

            if (is_wp_error($payment_result)) {
                wc_add_notice($payment_result->get_error_message(), 'error');
                wp_safe_redirect(wc_get_checkout_url());
                exit;
            }

            $this->enqueue_zpay_script($order_id, $payment_result);
            echo '<p>' . esc_html__('Thank you for your order, Zoho Payments Widget will be launched now', 'zpay') . '</p>';
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $session_id = $order->get_transaction_id();

            if (empty($session_id)) {
                $result = $this->api_handler->complete_payment($order, $this->user_details);

                if (is_wp_error($result)) {
                    wc_add_notice($result->get_error_message(), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                $order = wc_get_order($order_id);
                $session_id = $order->get_transaction_id();
            }

            if (empty($session_id)) {
                wc_add_notice(__('Zoho Payments error: Payment session could not be created. Please try again or contact support.', ZOHO_PAYMENT_GATEWAY_DOMAIN), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            return array(
                'result' => 'success',
                'redirect' => add_query_arg(array('order-pay' => $order->get_id(), 'key' => $order->get_order_key(), 'launch_widget' => 'true'), $order->get_checkout_payment_url(true))
            );
        }

        public function zpay_encrypt_key($order_id, $payment_id)
        {
            $key = hash('sha256', $order_id . $payment_id);
            $generated_key_wp = wp_generate_password(32, true, true);
            $iv = openssl_random_pseudo_bytes(16);
            openssl_encrypt($key, 'AES-256-CBC', $generated_key_wp, 0, $iv);
            return base64_encode($iv . $generated_key_wp);
        }

        public function enqueue_zpay_script($order_id, $payment_result)
        {
            if (!$order_id) {
                return;
            }
            $order = wc_get_order($order_id);
            $zpay_nonce = wp_create_nonce('wp_rest');
            ob_start(); ?>
            <script src="https://static.zohocdn.com/zpay/zpay-js/v1/zpayments.js"></script>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    if (window.widgetInitialized) {
                        return;
                    }
                    window.widgetInitialized = true;
                    const urlParams = new URLSearchParams(window.location.search);
                    if (urlParams.has('launch_widget') && urlParams.get('launch_widget') === 'true') {
                        let config = {
                            "account_id": "<?php echo esc_js($this->account_id); ?>", 
                            "domain": "<?php echo esc_js($this->get_widget_domain()); ?>",
                            "otherOptions": {
                                "api_key": "<?php echo esc_js($this->api_key); ?>", 
                                "is_test_mode": <?php echo $this->is_sandbox_enabled() ? 'true' : 'false'; ?>,
                                "__environment": "", 
                                "request_origin": "woocommerce-plugin"
                            }
                        };
                        let instance = new window.ZPayments(config);
                        let options = {
                            "amount": "<?php echo esc_js($order->get_total()); ?>", 
                            "currency_code": "<?php echo esc_js($order->get_currency()); ?>", 
                            "payments_session_id": "<?php echo esc_js($payment_result); ?>", 
                            "currency_symbol": "<?php echo esc_js(html_entity_decode(get_woocommerce_currency_symbol($order->get_currency()), ENT_QUOTES, 'UTF-8')); ?>", 
                            "business": "<?php echo esc_js($this->business); ?>", 
                            "description": "<?php echo esc_js($this->business_desc); ?>", 
                            "address": {
                                "phone": "<?php echo esc_js($order->get_billing_phone()); ?>",
                            }
                        };
                        (async function () {
                            try {
                                const data = await instance.requestPaymentMethod(options);
                                if (data && data.payment_id && data.signature) {
                                    let body = {
                                        order_id: '<?php echo esc_js($order_id); ?>',
                                        payment_id: data.payment_id,
                                        payment_session_id: "<?php echo esc_js($payment_result); ?>",
                                        signature: data.signature
                                    };

                                    try {
                                        const response = await fetch('<?php echo esc_url(rest_url('zpay/v1/payment_callback')); ?>', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)});
                                        const apiResult = await response.json();

                                        if (apiResult.success) {
                                            window.location.href = apiResult.redirect;
                                        } else {
                                            await instance.close();
                                        }
                                    } catch (error) {
                                        await instance.close();
                                        console.error('Error:', error);
                                    }
                                } else {
                                    // Some payment methods complete asynchronously and are confirmed by webhook.
                                    window.location.href = '<?php echo esc_url($order->get_view_order_url()); ?>';
                                }
                            } catch (error) {
                                const errorCode = (error && error.message) ? error.message : '';
                                const clearSessionUrl = '<?php echo esc_url(rest_url('zpay/v1/clear_session')); ?>';
                                const clearSessionHeaders = {'Content-Type': 'application/json', 'X-WP-Nonce': '<?php echo esc_js($zpay_nonce); ?>'};
                                const clearSessionBody = JSON.stringify({order_id: '<?php echo esc_js($order_id); ?>'});
                                const checkoutUrl = '<?php echo esc_url(wc_get_checkout_url()); ?>';
                                const orderUrl = '<?php echo esc_url($order->get_view_order_url()); ?>';

                                await instance.close();

                                if (errorCode === 'widget_closed') {
                                    // Customer cancelled — not an error, let them retry from checkout
                                    fetch(clearSessionUrl, {method: 'POST', headers: clearSessionHeaders, body: clearSessionBody});
                                    window.location.href = checkoutUrl;
                                } else if (errorCode === 'invalid_payment_session' || errorCode === 'session_expired') {
                                    // Session is stale or exhausted — clear it so a new one is created on retry
                                    await fetch(clearSessionUrl, {method: 'POST', headers: clearSessionHeaders, body: clearSessionBody}).catch(function () {});
                                    window.location.href = checkoutUrl;
                                } else {
                                    // Unexpected error — log it and send customer to their order page
                                    console.error('Zoho Payments widget error:', errorCode, error);
                                    fetch(clearSessionUrl, {method: 'POST', headers: clearSessionHeaders, body: clearSessionBody});
                                    window.location.href = orderUrl;
                                }
                            } finally {
                                await instance.close();
                            }
                        }());
                    }
                });
            </script>
            <?php echo ob_get_clean();
        }

        public function createUserObject()
        {
            $this->user_details = [
                "account_id" => $this->account_id,
                "client_id" => $this->client_id,
                "client_secret" => $this->client_secret,
                "refresh_token" => $this->refresh_token,
                "access_token" => $this->access_token,
                "business" => $this->business,
                "business_desc" => $this->business_desc,
                "api_key" => $this->api_key,
                "signing_key" => $this->signing_key,
                "w_signing_key" => $this->w_signing_key,
                "data_center" => $this->data_center,
                "sandbox_enabled" => $this->sandbox_enabled
            ];
        }

        public function get_zoho_oauth_url()
        {
            $client_id = zpay_decrypt_if_needed($this->get_option('client_id'));
            $redirect_uri = admin_url();
            $data_center = $this->get_option('data_center');
            $sandbox_enabled = $this->get_option('sandbox_enabled', 'no') === 'yes';
            $accounts_data_center = $sandbox_enabled ? 'in' : $data_center;
            $scope_prefix = $sandbox_enabled ? 'ZohoPaySandbox' : 'ZohoPay';
            $scope = $scope_prefix . '.payments.CREATE,' . $scope_prefix . '.payments.READ,' . $scope_prefix . '.payments.UPDATE';
            $soid = $this->get_option('account_id');
            $soid_prefix = $sandbox_enabled ? 'zohopaysandbox' : 'zohopay';
            $query = http_build_query(['response_type' => 'code', 'client_id' => $client_id, 'scope' => $scope, 'access_type' => 'offline', 'prompt' => 'consent', 'soid' => $soid_prefix . '.' . $soid, 'redirect_uri' => $redirect_uri]);
            return "https://accounts.zoho.$accounts_data_center/oauth/v2/org/auth?$query";
        }

        public function init_form_fields()
        {
            $refresh_token = $this->get_option('refresh_token');
            $access_token = $this->get_option('access_token');
            $is_connected = !empty($refresh_token) && !empty($access_token);
            $has_oauth_credentials = !empty($this->get_option('client_id')) && !empty($this->get_option('client_secret')) && !empty($this->get_option('account_id'));
            $connection_status_text = $is_connected
                ? esc_html__('Connected', ZOHO_PAYMENT_GATEWAY_DOMAIN)
                : esc_html__('Not Connected', ZOHO_PAYMENT_GATEWAY_DOMAIN);
            if ($is_connected) {
                $connection_description = '<p>' . esc_html__('Your Zoho Payments account is connected.', ZOHO_PAYMENT_GATEWAY_DOMAIN) . '</p>'
                    . '<p><button type="button" id="zpay-disconnect-btn" class="button button-secondary">' . esc_html__('Disconnect', ZOHO_PAYMENT_GATEWAY_DOMAIN) . '</button></p>';
            } elseif ($has_oauth_credentials) {
                $connection_description = '<p>' . esc_html__('Your Zoho Payments account is not connected.', ZOHO_PAYMENT_GATEWAY_DOMAIN) . '</p>'
                    . '<p><a href="' . esc_url($this->get_zoho_oauth_url()) . '" class="button button-primary">' . esc_html__('Connect', ZOHO_PAYMENT_GATEWAY_DOMAIN) . '</a></p>';
            } else {
                $connection_description = '<p>' . esc_html__('Your Zoho Payments account is not connected.', ZOHO_PAYMENT_GATEWAY_DOMAIN) . '</p>'
                    . '<p>' . esc_html__('Enter Client ID, Client Secret and Account ID then click Save changes to enable Connect.', ZOHO_PAYMENT_GATEWAY_DOMAIN) . '</p>';
            }

            $this->form_fields = [
                'connect_zoho' => array(
                    'type' => 'title',
                    'title' => sprintf(__('Zoho Connection Status: %s', ZOHO_PAYMENT_GATEWAY_DOMAIN), $connection_status_text),
                    'description' => $connection_description,
                ),
                'data_center' => array(
                    'title' => __('Data Center', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'type' => 'select',
                    'options' => array(
                        'in' => __('IN', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                        'com' => __('US', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    ),
                    'default' => 'in',
                    'description' => __('Must match your Zoho Payments account region (India or United States).', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                ),
                'client_id' => array(
                    'title' => __('Client Id', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'type' => 'text',
                    'default' => '',
                    'description' => __('Client ID generated from Zoho API Console.', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'custom_attributes' => array('required' => 'required'),
                ),
                'client_secret' => array(
                    'title' => __('Client Secret', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'type' => 'text',
                    'default' => '',
                    'description' => __('Client Secret generated from Zoho API Console.', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'custom_attributes' => array('required' => 'required'),
                ),
                'sandbox_enabled' => array(
                    'title' => __('Sandbox', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'type' => 'checkbox',
                    'label' => __('Enable sandbox (test mode)', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'default' => 'no',
                    'description' => __('Enable to use your sandbox account. Only available for India (IN).', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                ),
                'account_id' => array(
                    'title' => __('Account Id', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'type' => 'text',
                    'default' => '',
                    'description' => __('Account ID of your Zoho Payments account.', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'custom_attributes' => array('required' => 'required'),
                ),
                'api_key' => array(
                    'title' => __('API key', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'type' => 'password',
                    'default' => '',
                    'description' => __('API Key is found in Zoho Payments Dashboard > Settings > Developer Space', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'class' => 'zpay-sensitive-field',
                    'custom_attributes' => array('required' => 'required'),
                ),
                'signing_key' => array(
                    'title' => __('Signing key', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'type' => 'password',
                    'default' => '',
                    'description' => __('Signing key is found in Zoho Payments Dashboard > Settings > Developer Space', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'class' => 'zpay-sensitive-field',
                    'custom_attributes' => array('required' => 'required'),
                ),
                'w_signing_key' => array(
                    'title' => __('Webhook Signing key', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'type' => 'password',
                    'default' => '',
                    'description' => __('Obtained after configuring webhooks in Zoho Payments Dashboard > Settings > Developer Space > Webhooks.', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'class' => 'zpay-sensitive-field',
                ),
                'title' => array(
                    'title' => __('Title', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'type' => 'text',
                    'default' => __('Pay using Zoho Payments', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'description' => __('Name of the payment method shown to customers at checkout.', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                ),
                'description' => array(
                    'title' => __('Description', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'type' => 'textarea',
                    'default' => __('Pay securely with Zoho Payments using your preferred payment method.', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'description' => __('Optional description of the payment method shown to customers at checkout.', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                ),
                'business' => array(
                    'title' => __('Business Name', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'type' => 'text',
                    'default' => '',
                    'description' => __('Optional business name shown inside the Zoho Payments checkout widget.', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                ),
                'business_desc' => array(
                    'title' => __('Business Description', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                    'type' => 'text',
                    'default' => '',
                    'description' => __('Optional subtitle or description shown in the Zoho Payments checkout widget under your business name.', ZOHO_PAYMENT_GATEWAY_DOMAIN),
                ),
            ];
        }

        private static $oauth_processed = false;

        public function __construct($receiptPageFlag = true)
        {
            $this->id = 'zpay';
            $this->method_title = __('Zoho Payments', 'zpay');
            $this->icon = plugins_url('images/zpay-logo.svg', dirname(__FILE__) . '/../index.php');
            $this->method_description = __('Zoho Payments for WooCommerce', 'zpay');
            $this->has_fields = true;
            $this->api_handler = new ZohoPayAPIHandler();

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title', 'Zoho Payments');
            $this->description = $this->get_option('description', __('Pay securely with Zoho Payments using your preferred payment method.', ZOHO_PAYMENT_GATEWAY_DOMAIN));
            $this->account_id = ($this->get_option('account_id', ''));
            $this->business = $this->get_option('business');
            $this->business_desc = $this->get_option('business_desc');
            $this->data_center = $this->get_option('data_center');
            $this->sandbox_enabled = $this->get_option('sandbox_enabled', 'no');

            $this->client_id = zpay_decrypt_if_needed($this->get_option('client_id', ''));
            $this->client_secret = zpay_decrypt_if_needed($this->get_option('client_secret', ''));
            $this->api_key = zpay_decrypt_if_needed($this->get_option('api_key', ''));
            $this->signing_key = zpay_decrypt_if_needed($this->get_option('signing_key', ''));
            $this->w_signing_key = zpay_decrypt_if_needed($this->get_option('w_signing_key', ''));
            $this->refresh_token = zpay_decrypt_if_needed($this->get_option('refresh_token', ''));
            $this->access_token = zpay_decrypt_if_needed($this->get_option('access_token', ''));

            $this->createUserObject();

            if (is_admin() && isset($_GET['code']) && !self::$oauth_processed) {
                self::$oauth_processed = true;
                $auth_code = sanitize_text_field($_GET['code']);
                $url = "https://accounts.zoho." . $this->data_center . "/oauth/v2/token";
                $body = ['grant_type' => 'authorization_code', 'client_id' => $this->client_id, 'client_secret' => $this->client_secret, 'redirect_uri' => admin_url(), 'code' => $auth_code];
                $response = wp_remote_post($url, ['body' => $body]);
                if (!is_wp_error($response)) {
                    $result = json_decode(wp_remote_retrieve_body($response), true);
                    if (!empty($result['access_token']) && !empty($result['refresh_token'])) {
                        $settings = get_option($this->get_option_key(), []);
                        $settings['access_token'] = 'ENC:' . zpay_encrypt($result['access_token']);
                        $settings['refresh_token'] = 'ENC:' . zpay_encrypt($result['refresh_token']);
                        update_option($this->get_option_key(), $settings);
                        $settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=zpay');
                        wp_safe_redirect($settings_url);
                    }
                }
            }

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('admin_enqueue_scripts', function ($hook) {
                if ($hook === 'woocommerce_page_wc-settings' && isset($_GET['section']) && $_GET['section'] === 'zpay') {
                    wp_enqueue_script('zpay-admin-js', plugins_url('assets/js/zpay-admin.js', dirname(__FILE__) . '/../index.php'), array('jquery'), ZOHO_PAYMENT_GATEWAY_VERSION, true);
                    wp_localize_script('zpay-admin-js', 'zpay_admin_nonce', array('value' => wp_create_nonce('zpay_admin_action')));
                }
            });

            zpay_register_rest_routes();

            if ($receiptPageFlag) {
                if (!has_action('woocommerce_receipt_zpay', array($this, 'receipt_page')) && $receiptPageFlag) {
                    add_action('woocommerce_receipt_zpay', array($this, 'receipt_page'));
                }

                add_action('woocommerce_store_api_checkout_order_processed', function ($order_id) {
                    $order = wc_get_order($order_id);
                    $order->update_status('on_hold', __('Awaiting Zoho payment', ZOHO_PAYMENT_GATEWAY_DOMAIN));
                    $payment_result = $this->api_handler->complete_payment($order, $this->user_details);
                    if (is_wp_error($payment_result)) {
                        return;
                    }
                }, 10, 1);

                add_action('woocommerce_order_status_changed', function ($order_id, $from, $to, $order) {
                    if ($from === 'on-hold' && $to === 'pending') {
                        $order->update_meta_data('zpay_payment_session_id', null);
                        $order->save();
                    }
                }, 10, 4);

                add_action('woocommerce_order_status_failed', function ($order_id) {
                    $order = wc_get_order($order_id);
                    $order->update_meta_data('zpay_payment_session_id', null);
                });

                add_action('woocommerce_checkout_order_processed', function ($order_id) {
                    $order = wc_get_order($order_id);
                    $order->update_status('on_hold', __('Awaiting Zoho payment', ZOHO_PAYMENT_GATEWAY_DOMAIN));
                    $payment_result = $this->api_handler->complete_payment($order, $this->user_details);
                    if (is_wp_error($payment_result)) {
                        return;
                    }
                }, 10, 1);
            }
        }
    }
}

