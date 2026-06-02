<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zpay_ensure_encryption_key')) {
    function zpay_ensure_encryption_key()
    {
        $key = get_option('zpay_encryption_key');
        if (!$key) {
            $key = bin2hex(random_bytes(32));
            add_option('zpay_encryption_key', $key, '', 'no');
        }
        return $key;
    }
}

if (!function_exists('zpay_get_encryption_key')) {
    function zpay_get_encryption_key()
    {
        return get_option('zpay_encryption_key');
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
        $key = zpay_get_encryption_key();
        $method = 'AES-256-CBC';
        $data = base64_decode($data);
        $iv_length = openssl_cipher_iv_length($method);
        $iv = substr($data, 0, $iv_length);
        $encrypted = substr($data, $iv_length);
        return openssl_decrypt($encrypted, $method, $key, 0, $iv);
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

