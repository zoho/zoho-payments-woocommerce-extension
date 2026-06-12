<?php
/*
Plugin Name: Zoho Payments
Plugin URI: https://www.zoho.com/in/payments/
Description: Zoho Payments Plugin for Woo Commerce.
Version: 1.0.1
Author: ZPay
Author URI: https://www.zoho.com/in/payments/
Copyright: © 2024, Zoho Pay. All rights reserved.
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('ZOHO_PAYMENT_GATEWAY_VERSION', '1.0.1');
define('ZOHO_PAYMENT_GATEWAY_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('ZOHO_PAYMENT_GATEWAY_DOMAIN', 'woocommerce-gateway-zoho');

// Include supporting files only. Gateway class is loaded after WooCommerce is ready.
include_once ZOHO_PAYMENT_GATEWAY_PLUGIN_PATH . 'zoho-payments-api-handler.php';
include_once ZOHO_PAYMENT_GATEWAY_PLUGIN_PATH . 'includes/helpers.php';
include_once ZOHO_PAYMENT_GATEWAY_PLUGIN_PATH . 'includes/admin-hooks.php';
include_once ZOHO_PAYMENT_GATEWAY_PLUGIN_PATH . 'includes/blocks-hooks.php';
include_once ZOHO_PAYMENT_GATEWAY_PLUGIN_PATH . 'includes/rest-callbacks.php';

add_action('plugins_loaded', 'woocommerce_zpay_init', 11);
add_filter('woocommerce_payment_gateways', 'add_zpay_gateway');

function woocommerce_zpay_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    require_once ZOHO_PAYMENT_GATEWAY_PLUGIN_PATH . 'includes/class-wc-zpay-gateway.php';
}

function add_zpay_gateway($methods)
{
    $methods[] = 'WC_Zpay';
    return $methods;
}