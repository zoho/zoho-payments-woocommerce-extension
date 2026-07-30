<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zpay_get_encryption_key')) {
    function zpay_get_encryption_key()
    {
        $hex = get_option('zpay_encryption_key');

        if (!is_string($hex) || strlen($hex) !== 64 || !ctype_xdigit($hex)) {
            $hex = bin2hex(random_bytes(32));
            update_option('zpay_encryption_key', $hex, false);
        }

        return hex2bin($hex);
    }
}

if (!function_exists('zpay_encrypt')) {
    function zpay_encrypt($data)
    {
        $key = zpay_get_encryption_key();
        $method = 'AES-256-CBC';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));
        $encrypted = openssl_encrypt($data, $method, $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
}

if (!function_exists('zpay_decrypt')) {
    function zpay_decrypt($data)
    {
        $method  = 'AES-256-CBC';
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return false;
        }
        $iv_length = openssl_cipher_iv_length($method);
        $iv        = substr($decoded, 0, $iv_length);
        $cipher    = substr($decoded, $iv_length);

        $result = openssl_decrypt($cipher, $method, zpay_get_encryption_key(), 0, $iv);
        if ($result !== false) {
            return $result;
        }

        $result = openssl_decrypt($cipher, $method, str_repeat("\0", 32), 0, $iv);
        if ($result !== false) {
            $original     = 'ENC:' . $data;
            $re_encrypted = 'ENC:' . zpay_encrypt($result);
            $settings     = get_option('woocommerce_zpay_settings', []);
            $changed      = false;
            foreach ($settings as $key => $val) {
                if ($val === $original) {
                    $settings[$key] = $re_encrypted;
                    $changed        = true;
                }
            }
            if ($changed) {
                update_option('woocommerce_zpay_settings', $settings);
            }
        }
        return $result;
    }
}

if (!function_exists('zpay_decrypt_if_needed')) {
    function zpay_decrypt_if_needed($value)
    {
        if (strpos($value, 'ENC:') === 0) {
            return zpay_decrypt(substr($value, 4));
        }
        return $value;
    }
}

if (!function_exists('zpay_with_order_lock')) {
    function zpay_with_order_lock($order_id, callable $callback, $timeout = 10)
    {
        global $wpdb;
        $lock_name = 'zpay_order_' . (int) $order_id;

        $acquired = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, $timeout)
        );

        if ($acquired !== 1) {
            return new WP_Error(
                'zpay_locked',
                __('Payment session is being set up for this order. Please wait a moment and try again.', ZOHO_PAYMENT_GATEWAY_DOMAIN)
            );
        }

        try {
            return $callback();
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }
}

