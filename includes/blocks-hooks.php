<?php
if (!defined('ABSPATH')) {
    exit;
}

// Hook the custom function to the 'before_woocommerce_init' action
add_action('before_woocommerce_init', 'declare_cart_checkout_blocks_compatibility');
// 2 - Main Function to declare compatibility with cart_checkout_blocks feature
function declare_cart_checkout_blocks_compatibility()
{
    // Check if the required class exists
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        if (version_compare(WC_VERSION, '6.9', '>=')) {
            // Declare compatibility for 'cart_checkout_blocks'
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', plugin_dir_path(dirname(__FILE__) . '/../index.php'), true);
        } else {
            error_log('WooCommerce version is not compatible');
        }
    }
}

// Hook the custom function to the 'woocommerce_blocks_loaded' action
add_action('woocommerce_blocks_loaded', 'oawoo_register_order_approval_payment_method_type');
// Main function to register the custom payment method with WooCommerce Blocks
function oawoo_register_order_approval_payment_method_type()
{
    // Check if the required class exists
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    // Include the custom Blocks Checkout class
    require_once dirname(__FILE__) . '/../class-zpay-block.php';

    // Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
            // Register an instance of My_Custom_Gateway_Blocks
            $payment_method_registry->register(new Zpay_Gateway_Blocks);
        }
    );
}

