<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zpay_register_rest_routes')) {
    function zpay_register_rest_routes()
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        add_action('rest_api_init', function () {
            register_rest_route('zpay/v1', '/payment_callback', array(
                'methods' => 'POST',
                'callback' => 'zpay_payment_callback',
                'permission_callback' => '__return_true'
            ));
        });

        add_action('rest_api_init', function () {
            register_rest_route('zpay/v1', '/webhook', array(
                'methods' => 'POST',
                'callback' => 'zpay_webhook_handler',
                'permission_callback' => '__return_true',
            ));
        });

        add_action('rest_api_init', function () {
            register_rest_route('zpay/v1', '/clear_session', array(
                'methods' => 'POST',
                'callback' => 'zpay_clear_session_callback',
                'permission_callback' => '__return_true'
            ));
        });
    }
}

if (!function_exists('zpay_payment_callback')) {
    function zpay_payment_callback(WP_REST_Request $request)
    {
        $order_id = $request->get_param('order_id');
        $payment_id = $request->get_param('payment_id');
        $payment_session_id = $request->get_param('payment_session_id');
        $received_signature = $request->get_param('signature');

        if (!$payment_id || !$order_id || !$payment_session_id || !$received_signature) {
            return new WP_Error('missing_params', __('Payment ID, Payment Session ID, Order ID, and Signature are required', ZOHO_PAYMENT_GATEWAY_DOMAIN));
        }

        $settings = get_option('woocommerce_zpay_settings');
        $signing_key = isset($settings['signing_key']) ? zpay_decrypt_if_needed($settings['signing_key']) : '';

        if (!$signing_key) {
            error_log('Signing key is not set in the plugin settings');
            return new WP_Error('missing_signing_key', __('Signing key is not set in the plugin settings', ZOHO_PAYMENT_GATEWAY_DOMAIN));
        }

        $value = $payment_id . '|' . $payment_session_id;
        $expected_signature = hash_hmac('sha256', $value, $signing_key);
        if (!hash_equals($expected_signature, $received_signature)) {
            error_log('Signature verified Failed');
            return new WP_Error('invalid_signature', __('Invalid signature. Request denied.', ZOHO_PAYMENT_GATEWAY_DOMAIN));
        } else {
            error_log('Signature verified successfully');
        }

        $order = wc_get_order($order_id);
        $gateway = new WC_ZPay();
        $user_details = $gateway->user_details;
        $api_handler = new ZohoPayAPIHandler();
        $payment_result = $api_handler->verifyPayment($user_details, $order, $payment_session_id);

        error_log('Response from verifyPayment handle');

        if (!$order) {
            return new WP_Error('order_not_found', __('Order not found', ZOHO_PAYMENT_GATEWAY_DOMAIN));
        }

        if (is_array($payment_result) && isset($payment_result['status']) && $payment_result['status'] === 'success') {
            if (!isset($payment_result['payment_session_status']) || $payment_result['payment_session_status'] !== 'succeeded') {
                error_log('Payment session is not succeeded. Status: ' . ($payment_result['payment_session_status'] ?? 'missing'));
                $order->update_status('failed', __('Payment verification failed: Payment session not succeeded', ZOHO_PAYMENT_GATEWAY_DOMAIN));
                $order->save();
                return rest_ensure_response(['success' => false, 'redirect' => wc_get_cart_url()]);
            }

            if ($payment_result['payment_id'] == $payment_id && $payment_result['order_id'] == $order_id && $payment_result['payment_session_id'] == $payment_session_id) {
                $order_amount = floatval($order->get_total());
                $paid_amount = (isset($payment_result['amount']) && is_numeric($payment_result['amount']))
                    ? floatval($payment_result['amount'])
                    : null;

                if ($paid_amount === null) {
                    error_log('Payment result does not contain a valid amount.');
                    $order->update_status('failed', __('Payment verification failed: Amount missing or invalid', ZOHO_PAYMENT_GATEWAY_DOMAIN));
                    $order->save();
                    return rest_ensure_response(['success' => false, 'redirect' => wc_get_cart_url()]);
                }

                if ($order_amount === $paid_amount) {
                    $order->add_order_note(sprintf(__('Payment verified via Zoho Payments session %s Payment ID: %s', ZOHO_PAYMENT_GATEWAY_DOMAIN), $payment_result['payment_session_id'], $payment_result['payment_id']));
                    $order->update_meta_data('payment_id', $payment_id);
                    $order->payment_complete($payment_id);
                    $order->update_status('processing', __('Payment processed', ZOHO_PAYMENT_GATEWAY_DOMAIN));
                    $order->save();
                    return rest_ensure_response(['success' => true, 'redirect' => $order->get_checkout_order_received_url()]);
                } else {
                    error_log('Order amount mismatch: ' . $order_amount . ', got ' . $paid_amount);
                    $order->update_status('failed', __('Payment verification failed: Amount mismatch', ZOHO_PAYMENT_GATEWAY_DOMAIN));
                    $order->save();
                    return rest_ensure_response(['success' => false, 'redirect' => wc_get_cart_url()]);
                }
            } else {
                error_log('Payment ID mismatch');
                $order->update_status('failed', __('Payment verification failed: Payment ID mismatch', ZOHO_PAYMENT_GATEWAY_DOMAIN));
                $order->save();
                return rest_ensure_response(['success' => false, 'redirect' => wc_get_cart_url()]);
            }
        } else {
            error_log('Payment verification failed');
            $order->update_status('failed', __('Payment verification failed', ZOHO_PAYMENT_GATEWAY_DOMAIN));
            $order->save();
            return rest_ensure_response(['success' => false, 'redirect' => wc_get_cart_url()]);
        }
    }
}

if (!function_exists('zpay_webhook_handler')) {
    function zpay_webhook_handler(WP_REST_Request $request)
    {
        $signature_header = $request->get_header('x-zoho-webhook-signature');
        if (!$signature_header) {
            return new WP_Error('missing_signature', 'Missing webhook signature header', array('status' => 401));
        }

        $matches = [];
        preg_match('/t=([0-9]+),v=([a-f0-9]+)/i', $signature_header, $matches);
        if (count($matches) !== 3) {
            return new WP_Error('invalid_signature_format', 'Invalid webhook signature format', array('status' => 401));
        }
        $timestamp = $matches[1];
        $received_signature = $matches[2];

        $settings = get_option('woocommerce_zpay_settings');
        $webhook_secret = isset($settings['w_signing_key']) ? zpay_decrypt_if_needed($settings['w_signing_key']) : '';
        $body = $request->get_body();
        $data = $timestamp . '.' . $body;
        $expected_signature = hash_hmac('sha256', $data, $webhook_secret);

        if (!hash_equals($expected_signature, $received_signature)) {
            return new WP_Error('invalid_signature', 'Invalid webhook signature', array('status' => 401));
        }

        $data = json_decode($body, true);
        $event_type = $data['event_type'] ?? '';
        $payment = $data['event_object']['payment'] ?? [];
        $meta_data = $payment['meta_data'] ?? [];
        $order_id = null;

        foreach ($meta_data as $meta) {
            if (isset($meta['key']) && $meta['key'] === 'order_id') {
                $order_id = $meta['value'];
                break;
            }
        }

        $payment_id = $payment['payment_id'] ?? '';
        $amount = $payment['net_amount'] ?? '';
        $status = $payment['status'] ?? '';
        $payment_session_id = $payment['payments_session_id'] ?? '';

        if (!$order_id || !$payment_id || !$amount || !$status) {
            return new WP_Error('missing_params', 'Required parameters missing', array('status' => 400));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found', array('status' => 404));
        }

        if ($order->is_paid()) {
            return rest_ensure_response(['success' => true, 'message' => 'Order already paid']);
        }

        $current_status = $order->get_status();
        if (in_array($current_status, ['processing', 'completed'])) {
            return rest_ensure_response(['success' => true, 'message' => 'Order payment status already updated']);
        }

        $order_amount = floatval($order->get_total());
        if ($order_amount != floatval($amount)) {
            return new WP_Error('amount_mismatch', 'Amount does not match order total', array('status' => 400));
        }

        if ($event_type === 'payment.succeeded' && $status === 'succeeded') {
            $order->payment_complete($payment_id);
            $order->add_order_note('Payment completed via Zoho Payments Webhook. Payment ID: ' . $payment_id);
            $order->set_transaction_id($payment_session_id);
            $order->save();
            return rest_ensure_response(['success' => true, 'message' => 'Order payment updated']);
        } else {
            $order->update_status('failed', 'Zoho Payments Webhook reported payment failure.');
            $order->save();
            return rest_ensure_response(['success' => false, 'message' => 'Payment failed']);
        }
    }
}

if (!function_exists('zpay_clear_session_callback')) {
    function zpay_clear_session_callback(WP_REST_Request $request)
    {
        $nonce = $request->get_header('X-WP-Nonce');
        if (!wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('unauthorized', 'Invalid nonce', array('status' => 403));
        }

        $order_id = $request->get_param('order_id');
        $order = wc_get_order($order_id);

        if ($order) {
            $order->update_meta_data('zpay_payment_session_id', null);
            $order->set_transaction_id(null);
            $order->save();
            return rest_ensure_response(['success' => true]);
        }
        return new WP_Error('order_not_found', 'Order not found', array('status' => 404));
    }
}

