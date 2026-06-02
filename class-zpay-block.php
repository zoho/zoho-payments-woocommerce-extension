<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class ZPay_Gateway_Blocks extends AbstractPaymentMethodType {

    private $order_id;
    private $gateway;
    protected $name = 'zpay';

  

    public function __construct() {
        add_action('woocommerce_checkout_order_processed', array($this, 'set_order_id'), 10, 1);
    }

    public function initialize() {
        $this->settings = get_option( 'woocommerce_zpay_settings', [] );
        $this->gateway = new WC_ZPay(false);
    }

    public function set_order_id($order_id) {
        $this->order_id = $order_id;
    }

    public function get_order_id() {
        return $this->order_id;
    }

    public function is_active() {
        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles() {


        wp_register_script(
            'zpay-gateway-blocks-integration',
            plugin_dir_url(__FILE__) . 'checkout.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            null,
            true
        );
        if( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( 'zpay-gateway-blocks-integration');
            
        }
        return [ 'zpay-gateway-blocks-integration' ];
    }

    public function get_payment_method_data() {
        return [
            'title' => $this->gateway->title,
        ];
    }

}
?>