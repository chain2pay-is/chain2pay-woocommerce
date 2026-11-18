<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CHAIN2PAY_API_BASE')) {
    define('CHAIN2PAY_API_BASE', 'https://chain2pay.is');
}
if (!defined('CHAIN2PAY_OPTION_KEY')) {
    define('CHAIN2PAY_OPTION_KEY', 'chain2pay_api_key');
}

function chain2pay_get_api_key()
{
    return get_option(CHAIN2PAY_OPTION_KEY, '');
}

function chain2pay_is_sandbox()
{
    $key = chain2pay_get_api_key();
    return strpos($key, 'c2p_sandbox_') === 0;
}

/**
 * Extract a human-readable error message from the API response.
 * Handles both {error: {message: "..."}} and {error: "..."} shapes.
 */
function chain2pay_extract_error($api_data)
{
    if (!is_array($api_data) || !isset($api_data['error'])) {
        return 'Payment could not be processed.';
    }
    $err = $api_data['error'];
    if (is_array($err)) {
        return isset($err['message']) ? (string) $err['message'] : 'Payment could not be processed.';
    }
    return (string) $err;
}

/**
 * POST a payment-create request to the Chain2Pay API with automatic retries.
 *
 * Why this exists: transient failures (the WordPress host briefly fails to
 * reach the gateway, a DNS blip, or the gateway returning a 5xx during a
 * deploy/restart) were surfacing to buyers as a hard
 * "could not connect to payment gateway". We now retry a few times with a
 * short backoff before giving up, which absorbs brief blips so a buyer's
 * checkout doesn't fail on the first hiccup.
 *
 * The return value has the SAME shape as wp_remote_post() — a WP_Error on a
 * hard transport failure, or the response array otherwise — so every gateway
 * keeps using is_wp_error()/wp_remote_retrieve_* exactly as before.
 *
 * @param string $api_key Chain2Pay API key used as the Bearer token.
 * @param array  $body    Request body (JSON-encoded before sending).
 * @return array|WP_Error
 */
function chain2pay_request_payment($api_key, $body)
{
    $endpoint = CHAIN2PAY_API_BASE . '/api/v2/payments';
    $attempts = 3;
    $response = null;

    for ($i = 1; $i <= $attempts; $i++) {
        $response = wp_remote_post($endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body' => wp_json_encode($body),
        ));

        if (!is_wp_error($response)) {
            $code = (int) wp_remote_retrieve_response_code($response);
            // 2xx (success) and 4xx (a real client error we must show the
            // buyer) are final. Only a 5xx — a gateway-side hiccup, typically
            // during a deploy — is worth retrying.
            if ($code > 0 && $code < 500) {
                return $response;
            }
        }

        if ($i < $attempts) {
            // Linear backoff (0.5s then 1.0s): rides out a brief gateway blip
            // without making the buyer wait too long on the spinner.
            usleep(500000 * $i);
        }
    }

    return $response;
}

function chain2pay_settings_page()
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Unauthorized access.', 'instant-approval-payment-gateway'));
    }

    $saved = false;
    $error = '';

    if (isset($_POST['chain2pay_save_settings']) && check_admin_referer('chain2pay_settings_nonce')) {
        $raw_key = isset($_POST['chain2pay_api_key']) ? sanitize_text_field(wp_unslash($_POST['chain2pay_api_key'])) : '';

        if (!empty($raw_key)) {
            if (strpos($raw_key, 'c2p_sandbox_') !== 0 && strpos($raw_key, 'c2p_live_') !== 0) {
                $error = __('Invalid API key: must start with c2p_sandbox_ or c2p_live_', 'instant-approval-payment-gateway');
            } else {
                update_option(CHAIN2PAY_OPTION_KEY, $raw_key);
                $saved = true;
            }
        } else {
            delete_option(CHAIN2PAY_OPTION_KEY);
            $saved = true;
        }
    }

    $current_key = chain2pay_get_api_key();
    $has_key = !empty($current_key);
    $is_sandbox = $has_key && chain2pay_is_sandbox();
    $masked = $has_key ? substr($current_key, 0, 14) . str_repeat('*', max(0, strlen($current_key) - 14)) : '';

    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Chain2Pay Settings', 'instant-approval-payment-gateway'); ?></h1>

        <?php if ($saved): ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Settings saved.', 'instant-approval-payment-gateway'); ?></p></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('chain2pay_settings_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="chain2pay_api_key"><?php echo esc_html__('API Key', 'instant-approval-payment-gateway'); ?></label></th>
                    <td>
                        <input type="password" name="chain2pay_api_key" id="chain2pay_api_key" class="regular-text"
                               placeholder="<?php echo esc_attr($has_key ? $masked : 'c2p_live_... or c2p_sandbox_...'); ?>"
                               autocomplete="off" />
                        <p class="description">
                            <?php echo esc_html__('Enter your Chain2Pay API key. Leave empty and save to remove.', 'instant-approval-payment-gateway'); ?>
                        </p>
                        <?php if ($has_key): ?>
                            <p>
                                <strong><?php echo esc_html__('Status:', 'instant-approval-payment-gateway'); ?></strong>
                                <span style="color: <?php echo $is_sandbox ? '#b45309' : '#15803d'; ?>; font-weight: 600;">
                                    <?php echo $is_sandbox ? esc_html__('SANDBOX', 'instant-approval-payment-gateway') : esc_html__('LIVE', 'instant-approval-payment-gateway'); ?>
                                </span>
                            </p>
                            <p>
                                <strong><?php echo esc_html__('Key:', 'instant-approval-payment-gateway'); ?></strong>
                                <code><?php echo esc_html($masked); ?></code>
                            </p>
                        <?php else: ?>
                            <p style="color: #dc2626; font-weight: 600;"><?php echo esc_html__('No API key configured. Payment gateways will not work.', 'instant-approval-payment-gateway'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="chain2pay_save_settings" class="button-primary" value="<?php echo esc_attr__('Save Settings', 'instant-approval-payment-gateway'); ?>" />
            </p>
        </form>
    </div>
    <?php
}

function chain2pay_admin_menu()
{
    add_submenu_page(
        'woocommerce',
        __('Chain2Pay', 'instant-approval-payment-gateway'),
        __('Chain2Pay', 'instant-approval-payment-gateway'),
        'manage_woocommerce',
        'chain2pay-settings',
        'chain2pay_settings_page'
    );
}
add_action('admin_menu', 'chain2pay_admin_menu');
