<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'init_chain2paygateway_stripe_gateway');

function init_chain2paygateway_stripe_gateway()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class chain2pay_Instant_Payment_Gateway_Stripe extends WC_Payment_Gateway
    {
        protected $icon_url;

        public function __construct()
        {
            $this->id = 'chain2pay-instant-payment-gateway-stripe';
            $this->icon = sanitize_url($this->get_option('icon_url'));
            $this->method_title = esc_html__('Chain2Pay (Stripe)', 'instant-approval-payment-gateway');
            $this->method_description = esc_html__('Accept payments via Stripe through Chain2Pay.', 'instant-approval-payment-gateway');
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->title = sanitize_text_field($this->get_option('title'));
            $this->description = sanitize_text_field($this->get_option('description'));
            $this->icon_url = sanitize_url($this->get_option('icon_url'));

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_chain2pay_stripe', array($this, 'handle_callback'));
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => esc_html__('Enable/Disable', 'instant-approval-payment-gateway'),
                    'type' => 'checkbox',
                    'label' => esc_html__('Enable Chain2Pay Stripe gateway', 'instant-approval-payment-gateway'),
                    'default' => 'no',
                ),
                'title' => array(
                    'title' => esc_html__('Title', 'instant-approval-payment-gateway'),
                    'type' => 'text',
                    'description' => esc_html__('Payment method title shown during checkout.', 'instant-approval-payment-gateway'),
                    'default' => esc_html__('Card (Stripe)', 'instant-approval-payment-gateway'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => esc_html__('Description', 'instant-approval-payment-gateway'),
                    'type' => 'textarea',
                    'description' => esc_html__('Payment method description shown during checkout.', 'instant-approval-payment-gateway'),
                    'default' => esc_html__('Pay with Visa or Mastercard via Stripe', 'instant-approval-payment-gateway'),
                    'desc_tip' => true,
                ),
                'icon_url' => array(
                    'title' => esc_html__('Icon URL', 'instant-approval-payment-gateway'),
                    'type' => 'url',
                    'description' => esc_html__('Custom icon URL for the payment method.', 'instant-approval-payment-gateway'),
                    'desc_tip' => true,
                ),
            );
        }

        public function process_payment($order_id)
        {
            $api_key = chain2pay_get_api_key();
            if (empty($api_key)) {
                chain2paygateway_add_notice(__('Chain2Pay API key not configured. Please contact the store administrator.', 'instant-approval-payment-gateway'), 'error');
                return null;
            }

            $order = wc_get_order($order_id);
            $currency = get_woocommerce_currency();
            $total = $order->get_total();
            $nonce = wp_create_nonce('chain2paygateway_stripe_nonce_' . $order_id);
            $callback = add_query_arg(array('order_id' => $order_id, 'cp_nonce' => $nonce), home_url('/?wc-api=chain2pay_stripe'));

            $final_total = $total;

            $api_response = chain2pay_request_payment($api_key, array(
                    'amount' => (float) $final_total,
                    'currency' => $currency,
                    'callback_url' => $callback,
                    'customer_email' => sanitize_email($order->get_billing_email()),
                    'provider' => 'stripe',
                    'metadata' => array('woo_order_id' => $order_id),
                ));

            if (is_wp_error($api_response)) {
                chain2paygateway_add_notice(__('Payment error: could not connect to payment gateway.', 'instant-approval-payment-gateway'), 'error');
                return null;
            }

            $api_data = json_decode(wp_remote_retrieve_body($api_response), true);

            if ($api_data && isset($api_data['checkout_url'])) {
                $checkout_url = sanitize_url($api_data['checkout_url']);
                $c2p_order_id = sanitize_text_field($api_data['id']);

                $order->add_meta_data('chain2pay_stripe_callback', $callback, true);
                $order->add_meta_data('chain2pay_order_id', $c2p_order_id, true);
                $order->add_meta_data('chain2pay_payment_url', $checkout_url, true);
                $order->add_meta_data('chain2pay_stripe_nonce', $nonce, true);
                $order->save();
            } else {
                $err_msg = chain2pay_extract_error($api_data);
                chain2paygateway_add_notice(__('Payment error: ', 'instant-approval-payment-gateway') . esc_html($err_msg), 'error');
                return null;
            }

            if (chain2paygateway_is_checkout_block()) {
                global $woocommerce;
                $woocommerce->cart->empty_cart();
            }

            return array(
                'result' => 'success',
                'redirect' => $checkout_url,
            );
        }

        public function chain2pay_instant_payment_gateway_get_icon_url()
        {
            return !empty($this->icon_url) ? esc_url($this->icon_url) : '';
        }

        public function handle_callback()
        {
            $order_id = absint(isset($_GET['order_id']) ? $_GET['order_id'] : 0);
            $nonce = sanitize_text_field(isset($_GET['cp_nonce']) ? $_GET['cp_nonce'] : '');
            $txid_out = sanitize_text_field(isset($_GET['txid_out']) ? $_GET['txid_out'] : '');
            $value_coin = (float) (isset($_GET['value_coin']) ? $_GET['value_coin'] : 0);

            if (empty($order_id)) {
                wp_send_json_error(array('message' => 'Order ID missing'), 400);
                return;
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                wp_send_json_error(array('message' => 'Invalid order'), 404);
                return;
            }

            if (empty($nonce) || $order->get_meta('chain2pay_stripe_nonce', true) !== $nonce) {
                wp_send_json_error(array('message' => 'Invalid nonce'), 403);
                return;
            }

            if ($order->get_status() !== 'processing' && $order->get_status() !== 'completed' && 'chain2pay-instant-payment-gateway-stripe' === $order->get_payment_method()) {
                $expected_amount = (float) $order->get_total();
                $threshold = 0.60 * $expected_amount;
                if ($value_coin < $threshold) {
                    $order->update_status('failed', __('Payment received is less than 60% of the order total.', 'instant-approval-payment-gateway'));
                    $order->add_order_note(sprintf(__('Order marked as failed: insufficient payment. TXID: %1$s', 'instant-approval-payment-gateway'), $txid_out));
                    wp_send_json_success(array('message' => 'Order marked as failed due to partial payment.'));
                } else {
                    $order->add_meta_data('chain2pay_order_txid', $txid_out, true);
                    $order->save();
                    $order->payment_complete();
                    $order->add_order_note(sprintf(__('Payment completed. TXID: %1$s', 'instant-approval-payment-gateway'), $txid_out));
                    wp_send_json_success(array('message' => 'Order marked as paid.'));
                }
            } else {
                wp_send_json_error(array('message' => 'Order not eligible'), 400);
            }
        }
    }

    function chain2paygateway_add_instant_payment_gateway_stripe($gateways)
    {
        $gateways[] = 'chain2pay_Instant_Payment_Gateway_Stripe';
        return $gateways;
    }
    add_filter('woocommerce_payment_gateways', 'chain2paygateway_add_instant_payment_gateway_stripe');
}

function chain2paygateway_stripe_change_order_status_rest_endpoint()
{
    register_rest_route('chain2paygateway/v1', '/chain2paygateway-stripe/', array(
        'methods' => 'GET',
        'callback' => 'chain2paygateway_stripe_change_order_status_callback',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'chain2paygateway_stripe_change_order_status_rest_endpoint');

function chain2paygateway_stripe_change_order_status_callback($request)
{
    $order_id = absint($request->get_param('order_id'));
    $nonce = sanitize_text_field($request->get_param('cp_nonce'));
    $txid_out = sanitize_text_field($request->get_param('txid_out'));
    $value_coin = (float) sanitize_text_field($request->get_param('value_coin'));

    if (empty($order_id)) {
        return new WP_Error('missing_order_id', __('Order ID parameter is missing.', 'instant-approval-payment-gateway'), array('status' => 400));
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return new WP_Error('invalid_order', __('Invalid order ID.', 'instant-approval-payment-gateway'), array('status' => 404));
    }

    if (empty($nonce) || $order->get_meta('chain2pay_stripe_nonce', true) !== $nonce) {
        return new WP_Error('invalid_nonce', __('Invalid nonce.', 'instant-approval-payment-gateway'), array('status' => 403));
    }

    if ($order->get_status() !== 'processing' && $order->get_status() !== 'completed' && 'chain2pay-instant-payment-gateway-stripe' === $order->get_payment_method()) {
        $expected_amount = (float) $order->get_total();
        $threshold = 0.60 * $expected_amount;
        if ($value_coin < $threshold) {
            $order->update_status('failed', __('Payment received is less than 60% of the order total.', 'instant-approval-payment-gateway'));
            $order->add_order_note(sprintf(__('Order marked as failed: insufficient payment. TXID: %1$s', 'instant-approval-payment-gateway'), $txid_out));
            return array('message' => 'Order status changed to failed due to partial payment.');
        } else {
            $order->add_meta_data('chain2pay_order_txid', $txid_out, true);
            $order->save();
            $order->payment_complete();
            $order->add_order_note(sprintf(__('Payment completed. TXID: %1$s', 'instant-approval-payment-gateway'), $txid_out));
            return array('message' => 'Order marked as paid and status changed.');
        }
    } else {
        return new WP_Error('order_not_eligible', __('Order is not eligible for status change.', 'instant-approval-payment-gateway'), array('status' => 400));
    }
}
