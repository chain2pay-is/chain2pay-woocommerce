<?php
/**
 * Plugin Name: Chain2Pay - Payment Gateway for WooCommerce
 * Plugin URI: https://chain2pay.is/
 * Description: Accept Visa, Mastercard, Apple Pay, PayPal, Revolut and more on WooCommerce via 18 providers with instant settlement.
 * Version: 2.3.0
 * Requires Plugins: woocommerce
 * Requires at least: 5.8
 * Tested up to: 6.9
 * WC requires at least: 5.8
 * WC tested up to: 10.3.6
 * Requires PHP: 7.2
 * Author: Chain2Pay
 * Author URI: https://chain2pay.is/
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

function chain2paygateway_enqueue_block_assets()
{
    if (!function_exists('WC')) {
        return;
    }
    $wc = WC();
    if (!$wc || !$wc->payment_gateways()) {
        return;
    }

    $chain2paygateway_available_gateways = $wc->payment_gateways()->get_available_payment_gateways();
    $chain2paygateway_gateways_data = array();

    foreach ($chain2paygateway_available_gateways as $gateway_id => $gateway) {
        if (strpos($gateway_id, 'chain2pay-instant-payment-gateway') === 0) {
            $icon_url = method_exists($gateway, 'chain2pay_instant_payment_gateway_get_icon_url') ? $gateway->chain2pay_instant_payment_gateway_get_icon_url() : '';
            $chain2paygateway_gateways_data[] = array(
                'id' => sanitize_key($gateway_id),
                'label' => sanitize_text_field($gateway->get_title()),
                'description' => wp_kses_post($gateway->get_description()),
                'icon_url' => sanitize_url($icon_url),
            );
        }
    }

    wp_enqueue_script(
        'chain2paygateway-block-support',
        plugin_dir_url(__FILE__) . 'assets/js/chain2paygateway-block-checkout-support.js',
        array('wc-blocks-registry', 'wp-element', 'wp-i18n', 'wp-components', 'wp-blocks', 'wp-editor'),
        filemtime(plugin_dir_path(__FILE__) . 'assets/js/chain2paygateway-block-checkout-support.js'),
        true
    );

    wp_localize_script(
        'chain2paygateway-block-support',
        'chain2paygatewayData',
        $chain2paygateway_gateways_data
    );
}
add_action('enqueue_block_assets', 'chain2paygateway_enqueue_block_assets');

function chain2paygateway_enqueue_styles()
{
    if (is_checkout()) {
        wp_enqueue_style(
            'chain2paygateway-styles',
            plugin_dir_url(__FILE__) . 'assets/css/chain2paygateway-payment-gateway-styles.css',
            array(),
            filemtime(plugin_dir_path(__FILE__) . 'assets/css/chain2paygateway-payment-gateway-styles.css')
        );
    }
}
add_action('wp_enqueue_scripts', 'chain2paygateway_enqueue_styles');

// Settings (API key management)
include_once(plugin_dir_path(__FILE__) . 'includes/class-chain2pay-settings.php');

// Payment gateways. Disable a provider by commenting out its include.
include_once(plugin_dir_path(__FILE__) . 'includes/class-chain2pay-instant-payment-gateway-multihosted.php');
include_once(plugin_dir_path(__FILE__) . 'includes/class-chain2pay-instant-payment-gateway-revolut.php');
include_once(plugin_dir_path(__FILE__) . 'includes/class-chain2pay-instant-payment-gateway-stripe.php');
include_once(plugin_dir_path(__FILE__) . 'includes/class-chain2pay-instant-payment-gateway-paypal.php');
include_once(plugin_dir_path(__FILE__) . 'includes/class-chain2pay-instant-payment-gateway-moonpay.php');
include_once(plugin_dir_path(__FILE__) . 'includes/class-chain2pay-instant-payment-gateway-blockchain.php');
include_once(plugin_dir_path(__FILE__) . 'includes/class-chain2pay-instant-payment-gateway-coinbase.php');
include_once(plugin_dir_path(__FILE__) . 'includes/class-chain2pay-instant-payment-gateway-custom.php');
include_once(plugin_dir_path(__FILE__) . 'includes/class-chain2pay-instant-payment-gateway-banxa.php');
include_once(plugin_dir_path(__FILE__) . 'includes/class-chain2pay-instant-payment-gateway-alchemy.php');
include_once(plugin_dir_path(__FILE__) . 'includes/class-chain2pay-instant-payment-gateway-skrill.php');
include_once(plugin_dir_path(__FILE__) . 'includes/class-chain2pay-instant-payment-gateway-robinhood.php');
include_once(plugin_dir_path(__FILE__) . 'includes/class-chain2pay-instant-payment-gateway-stixramp.php');
include_once(plugin_dir_path(__FILE__) . 'includes/class-chain2pay-instant-payment-gateway-crypto.php');
include_once(plugin_dir_path(__FILE__) . 'includes/class-chain2pay-instant-payment-gateway-ramp.php');
include_once(plugin_dir_path(__FILE__) . 'includes/class-chain2pay-instant-payment-gateway-coindisco.php');
include_once(plugin_dir_path(__FILE__) . 'includes/class-chain2pay-instant-payment-gateway-btcdirect.php');
include_once(plugin_dir_path(__FILE__) . 'includes/class-chain2pay-instant-payment-gateway-alchemylocal.php');

function chain2paygateway_is_checkout_block()
{
    if (!class_exists('WC_Blocks_Utils')) {
        return false;
    }
    return WC_Blocks_Utils::has_block_in_page(wc_get_page_id('checkout'), 'woocommerce/checkout');
}

function chain2paygateway_add_notice($chain2paygateway_message, $chain2paygateway_notice_type = 'error')
{

    if (chain2paygateway_is_checkout_block()) {

        if ($chain2paygateway_notice_type === 'error') {
            throw new \WC_Data_Exception('checkout_error', esc_html($chain2paygateway_message));
        }

    } else {

        wc_add_notice(esc_html($chain2paygateway_message), $chain2paygateway_notice_type);
    }
}
