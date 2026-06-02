<?php
if (!defined('ABSPATH')) {
    exit('Permission Denied');
}

class ZohoPayAPIHandler
{
    public $authToken;

    private function isSandboxEnabled($user_details)
    {
        return isset($user_details['sandbox_enabled']) && $user_details['sandbox_enabled'] === 'yes';
    }

    private function getApiBaseUrl($service, $user_details)
    {
        $sandbox_enabled = $this->isSandboxEnabled($user_details);
        if ($sandbox_enabled && ($user_details['data_center'] ?? '') !== 'in') {
            return new WP_Error('invalid_sandbox_region', __('Zoho Payments sandbox is only available for IN data center.', ZOHO_PAYMENT_GATEWAY_DOMAIN));
        }

        if ($service === 'accounts') {
            return 'https://accounts.zoho.' . ($sandbox_enabled ? 'in' : $user_details['data_center']);
        }

        return $sandbox_enabled
            ? 'https://paymentssandbox.zoho.in'
            : 'https://payments.zoho.' . $user_details['data_center'];
    }

    // FN1 - Create Payment Session
    function createPaymentSession($order, $user_details, $auth_token_result)
    {

        error_log('createPaymentSession fn started ---');
        // Check if payment session ID already exists for this order
        $existing_session_id = $order->get_meta('zpay_payment_session_id', true);
        if ($existing_session_id) {
            error_log('Using existing payment session ID');
            $existing_session_expiry_time = $order->get_meta('zpay_payment_session_expiry_time', true);
            // No expiry stored → reuse session (can't assume expired)
            if (!$existing_session_expiry_time) {
                error_log('No expiry time stored, reusing existing session ID');
                return [
                    'message' => 'success',
                    'payments_session' => ['payments_session_id' => $existing_session_id]
                ];
            }
            // Support ms timestamps, s timestamps, and date strings
            if (is_numeric($existing_session_expiry_time)) {
                $expiry_ts = strlen((string) intval($existing_session_expiry_time)) >= 13
                    ? intval($existing_session_expiry_time) / 1000
                    : intval($existing_session_expiry_time);
            } else {
                $expiry_ts = strtotime($existing_session_expiry_time);
            }
            if ($expiry_ts && $expiry_ts > time()) {
                return [
                    'message' => 'success',
                    'payments_session' => ['payments_session_id' => $existing_session_id]
                ];
            } else {
                error_log('Existing payment session ID has expired');
                $order->update_meta_data('zpay_payment_session_id', null);
                $order->update_meta_data('zpay_payment_session_expiry_time', null);
                $order->save();
            }
        } else {
            error_log('No existing payment session ID FOUND');

        }

        $data = [
            'amount' => floatval($order->get_total()),
            'currency' => $order->get_currency(),
            'meta_data' => [
                [
                    'key' => 'order_id',
                    "value" => $order->get_id()
                ]
            ],
            'description' => 'Payment for Order #' . $order->get_id(),
            'invoice_number' => 'INV-' . $order->get_id()

        ];

        if (!$auth_token_result) {
            return new WP_Error('missing_access_token', __('Zoho Payments is not connected. Please contact the store admin or try again later.', ZOHO_PAYMENT_GATEWAY_DOMAIN));
        }

        //If not type string then return error
        if (!is_string($auth_token_result)) {
            return new WP_Error('invalid_access_token', __('Invalid access token. Please contact the store admin or try again later.', ZOHO_PAYMENT_GATEWAY_DOMAIN));
        }

        $options = [
            'http' => [
                'header' => "content-type: application/json\r\n" .
                    "Authorization: Zoho-oauthtoken " . $auth_token_result . "\r\n",
                'method' => 'POST',
                'content' => json_encode($data),
                'ignore_errors' => true, // Get response body even on 4xx/5xx
            ],
        ];

        $context = stream_context_create($options);
        $payments_api_base_url = $this->getApiBaseUrl('payments', $user_details);
        if (is_wp_error($payments_api_base_url)) {
            return $payments_api_base_url;
        }
        $url = $payments_api_base_url . '/api/v1/paymentsessions?account_id=' . $user_details['account_id'];
        $response = file_get_contents($url, false, $context);

        error_log('createPaymentSession fn ended ---');
        error_log('Response: ' . $response);

        if ($response === FALSE) {
            $last_error = error_get_last();
            return new WP_Error('payment_failed', __($last_error['message'], ZOHO_PAYMENT_GATEWAY_DOMAIN));
        }

        $decodedResponse = json_decode($response, true);

        // Extract Zoho error details if present
        if (isset($decodedResponse['error_description'])) {
            error_log('Zoho API error: ' . $decodedResponse['error_description']);
            return new WP_Error(isset($decodedResponse['code']) ? $decodedResponse['code'] : 'payment_failed', $decodedResponse['error_description']);
        }
        if (isset($decodedResponse['error'])) {
            error_log('Zoho API error: ' . $decodedResponse['error']);
            return new WP_Error(isset($decodedResponse['code']) ? $decodedResponse['code'] : 'payment_failed', $decodedResponse['error']);
        }
        if (isset($decodedResponse['message']) && $decodedResponse['message'] !== 'success') {
            error_log('Zoho API message: ' . $decodedResponse['message']);
            return new WP_Error(isset($decodedResponse['code']) ? $decodedResponse['code'] : 'payment_failed', $decodedResponse['message']);
        }

        // Store the new payment session ID in order meta
        if (isset($decodedResponse['payments_session']['payments_session_id'])) {
            $order->update_meta_data('zpay_payment_session_id', $decodedResponse['payments_session']['payments_session_id']);
            $order->update_meta_data('zpay_payment_session_expiry_time', $decodedResponse['payments_session']['expiry_time']);
            // Change status from draft to on-hold if needed
            if ($order->get_status() === 'draft') {
                $order->update_status('on-hold', __('Awaiting Zoho payment', ZOHO_PAYMENT_GATEWAY_DOMAIN));
            }
            $order->save();
        }
        return $decodedResponse;
    }


    // FN2 - Generate Access Token from Refresh Token
    function generateAccessTokenFromRefreshToken($user_details)
    {

        if (empty($user_details['refresh_token']) || empty($user_details['client_id']) || empty($user_details['client_secret'])) {
            return new WP_Error('missing_oauth_config', 'Zoho OAuth configuration is incomplete. Please reconnect Zoho in WooCommerce > Settings > Payments > ZPay.');
        }

        $accounts_api_base_url = $this->getApiBaseUrl('accounts', $user_details);
        if (is_wp_error($accounts_api_base_url)) {
            return $accounts_api_base_url;
        }

        $url = $accounts_api_base_url . '/oauth/v2/token';
        $data = http_build_query([
            'refresh_token' => $user_details['refresh_token'],
            'client_id' => $user_details['client_id'],
            'client_secret' => $user_details['client_secret'],
            'grant_type' => 'refresh_token'
        ]);

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'content' => $data,
                'ignore_errors' => true // Get content even on 4xx/5xx
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        if ($response === FALSE) {
            $last_error = error_get_last();
            return new WP_Error('Error', __($last_error['message'], ZOHO_PAYMENT_GATEWAY_DOMAIN));
        }

        $decodedResponse = json_decode($response, true);
        if (isset($decodedResponse['error'])) {
            $error = (string) $decodedResponse['error'];
            $error_description = isset($decodedResponse['error_description']) ? (string) $decodedResponse['error_description'] : '';
            $message = $error_description !== '' ? $error_description : $error;

            // Access denied/invalid grant means refresh token is no longer usable.
            if (stripos($error, 'access_denied') !== false || stripos($message, 'access denied') !== false || stripos($error, 'invalid_grant') !== false) {
                $settings = get_option('woocommerce_zpay_settings', []);
                $settings['access_token'] = '';
                $settings['refresh_token'] = '';
                update_option('woocommerce_zpay_settings', $settings);
                return new WP_Error('zoho_reconnect_required', 'Zoho authentication failed: ' . $message . '. Please reconnect your Zoho account in WooCommerce > Settings > Payments > ZPay.');
            }

            return new WP_Error('zoho_token_error', 'Zoho token refresh failed: ' . $message);
        }

        // Check if the response contains the access token
        $this->authToken = $decodedResponse['access_token'] ?? null;
        if (empty($this->authToken)) {
            return new WP_Error('zoho_token_missing', 'Zoho token refresh did not return an access token. Please reconnect your Zoho account.');
        }

        $settings = get_option('woocommerce_zpay_settings', []);
        if (!empty($this->authToken) && strpos($this->authToken, 'ENC:') !== 0) {
            $settings['access_token'] = 'ENC:' . zpay_encrypt($this->authToken);
        } else {
            $settings['access_token'] = $this->authToken;
        }
        update_option('woocommerce_zpay_settings', $settings);
        return $this->authToken;
    }

    // FN3 - Complete Payment with Retry Logic
    public function complete_payment($order, $user_details)
    {
        error_log('complete_payment fn started -----');
        $access_token = $user_details['access_token'] ?? '';

        $auth_token_result = null;

        if ($access_token !== '' && $access_token !== null) {
            $auth_token_result = $access_token;
        } else {
            $refresh_token = $user_details['refresh_token'];
            //changed
            if ($refresh_token !== '' && $refresh_token !== null) {
                error_log('Refresh token is not empty');
                $auth_token_result = $this->generateAccessTokenFromRefreshToken($user_details);

            } else {
                error_log('Refresh token is empty');
            }
        }

        if (is_wp_error($auth_token_result)) {
            $order->add_order_note('Auth Call failed: ' . $auth_token_result->get_error_message());
            return $auth_token_result;
        }

        return $this->tryPaymentSession($order, $user_details, 1, $auth_token_result);
    }

    // FN4 - Try Payment Session with Retry Logic
    public function tryPaymentSession($order, $user_details, $try, $auth_token_result)
    {
        if ($try <= 3) {
            $result = $this->createPaymentSession($order, $user_details, $auth_token_result);

            if (is_wp_error($result)) {
                $error_code = strtolower((string) $result->get_error_code());
                $error_message = strtolower((string) $result->get_error_message());
                $is_auth_error = (
                    strpos($error_code, 'token') !== false ||
                    strpos($error_code, 'auth') !== false ||
                    strpos($error_code, 'access_denied') !== false ||
                    strpos($error_code, 'invalid_grant') !== false ||
                    strpos($error_message, 'token') !== false ||
                    strpos($error_message, 'auth') !== false ||
                    strpos($error_message, 'access denied') !== false
                );

                error_log("payment session creation failed for order - trial number - " . $try . " for order " . $order->get_id() . "account id: " . $user_details['account_id']);
                error_log($result->get_error_message());
                $order->add_order_note('Payment Session Creation failed (try ' . $try . '): ' . $result->get_error_message());

                // Non-auth errors (e.g., unsupported currency/account constraints) should fail fast.
                if (!$is_auth_error) {
                    $order->update_meta_data('zpay_payment_session_id', null);
                    $order->save();
                    return $result;
                }

                error_log("Retrying to generate new access token");
                $new_token = $this->generateAccessTokenFromRefreshToken($user_details);
                // If token refresh itself failed, stop retrying and surface the real error
                if (is_wp_error($new_token)) {
                    error_log('Token refresh failed: ' . $new_token->get_error_message());
                    $order->add_order_note('Token refresh failed: ' . $new_token->get_error_message());
                    $order->update_meta_data('zpay_payment_session_id', null);
                    $order->save();
                    return new WP_Error(
                        'token_refresh_failed',
                        __('Zoho Payments is not connected. Please contact the merchant for assistance.', ZOHO_PAYMENT_GATEWAY_DOMAIN)
                    );
                }
                // Recursive call with incremented $try
                return $this->tryPaymentSession($order, $user_details, $try + 1, $new_token);
            }

            if (isset($result['message']) && $result['message'] === 'success') {
                $order->add_order_note('Payment Session Created Successfully: ' . $result['payments_session']['payments_session_id']);
                $order->set_transaction_id($result['payments_session']['payments_session_id']);
                $order->save();
                return $result['payments_session']['payments_session_id'];

            }

            // Should not reach here (errors handled above), but guard anyway
            $order->update_meta_data('zpay_payment_session_id', null);
            $order->save();
            return new WP_Error('payment_failed', isset($result['message']) ? $result['message'] : 'Unknown error from Zoho Payments API');

        } else {
            error_log("Maximum tries done - payment sessions creation failed for order id: " . $order->get_id() . "account id: " . $user_details['account_id'] . "after " . $try . " tries");
            $order->update_meta_data('zpay_payment_session_id', null);
            $order->save();
            return new WP_Error('payment_failed', 'Payment session could not be created after ' . ($try - 1) . ' attempts. Please check your Zoho credentials or reconnect your account.');
        }

    }


    // FN5 - Verify Payment after return from Zoho
    function verifyPayment($user_details, $order_id, $payment_session_id)
    {
        error_log("Verify payments fn started ---");
        $options = [
            'http' => [
                'header' => "Authorization: Zoho-oauthtoken " . $user_details['access_token'],
                'method' => 'GET'
            ],
        ];
        $context = stream_context_create($options);
        $payments_api_base_url = $this->getApiBaseUrl('payments', $user_details);
        if (is_wp_error($payments_api_base_url)) {
            return $payments_api_base_url;
        }
        $url = $payments_api_base_url . '/api/v1/paymentsessions/' . $payment_session_id . '?account_id=' . $user_details['account_id'];

        $response = file_get_contents($url, false, $context);

        if ($response === FALSE) {
            $last_error = error_get_last();
            return new WP_Error('Retreive Error', __($last_error['message'], ZOHO_PAYMENT_GATEWAY_DOMAIN));
        }

        $decodedResponse = json_decode($response, true);
        if (isset($decodedResponse['error'])) {
            return new WP_Error('Error', __($decodedResponse['error'], ZOHO_PAYMENT_GATEWAY_DOMAIN));
        }

        return array(
            'status' => isset($decodedResponse['message']) ? $decodedResponse['message'] : '',
            'payment_session_status' => isset($decodedResponse['payments_session']['status']) ? $decodedResponse['payments_session']['status'] : '',
            'payment_id' => isset($decodedResponse['payments_session']['payments'][0]['payment_id']) ? $decodedResponse['payments_session']['payments'][0]['payment_id'] : '',
            'payment_session_id' => $payment_session_id,
            'amount' => isset($decodedResponse['payments_session']['amount']) ? $decodedResponse['payments_session']['amount'] : '',
            'order_id' => isset($decodedResponse['payments_session']['meta_data'][0]['value']) ? $decodedResponse['payments_session']['meta_data'][0]['value'] : ''

        );
    }
}
?>