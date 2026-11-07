<?php

/**
 * Plugin Name: Shopbonus Banner
 * Description: Merchant-controlled placement of the Shopbonus public banner. Order delivery uses WooCommerce's native webhooks.
 * Version: 0.3.0
 * Requires PHP: 8.1
 */
if (! defined('ABSPATH')) {
    exit;
}

function shopbonus_banner_sanitize($input)
{
    $origin = esc_url_raw((string) ($input['origin'] ?? ''), ['https']);
    $parts = wp_parse_url($origin);
    if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
        $origin = '';
    }
    $key = (string) ($input['key'] ?? '');

    return ['origin' => rtrim($origin, '/'), 'key' => preg_match('/\Apk_[A-Za-z0-9_-]+\z/D', $key) ? $key : '', 'enabled' => in_array($input['enabled'] ?? false, [true, '1', 1], true)];
}

add_action('admin_init', static function () {
    register_setting('shopbonus_banner', 'shopbonus_banner', ['type' => 'array', 'sanitize_callback' => 'shopbonus_banner_sanitize', 'show_in_rest' => false]);
});
add_action('admin_menu', static function () {
    add_options_page('Shopbonus Banner', 'Shopbonus Banner', 'manage_options', 'shopbonus-banner', static function () {
        if (! current_user_can('manage_options')) {
            return;
        }
        $settings = shopbonus_banner_sanitize(get_option('shopbonus_banner', []));
        echo '<div class="wrap"><h1>Shopbonus Banner</h1><form method="post" action="options.php">';
        settings_fields('shopbonus_banner');
        echo '<p><label>Backend HTTPS origin <input type="url" name="shopbonus_banner[origin]" value="'.esc_attr($settings['origin']).'"></label></p>';
        echo '<p><label>Public banner key <input type="text" name="shopbonus_banner[key]" value="'.esc_attr($settings['key']).'"></label></p>';
        echo '<p><label><input type="checkbox" name="shopbonus_banner[enabled]" value="1" '.checked($settings['enabled'], true, false).'> Enable storefront banner</label></p>';
        echo '<p>Publish the campaign in Shopbonus and authorize this storefront domain. This plugin places the public loader; it does not grant editor access or verify payments.</p>';
        submit_button();
        echo '</form></div>';
    });
});
add_action('wp_footer', static function () {
    $settings = shopbonus_banner_sanitize(get_option('shopbonus_banner', []));
    if (! $settings['enabled'] || $settings['origin'] === '' || $settings['key'] === '') {
        return;
    }
    if ((function_exists('is_checkout') && is_checkout()) || (function_exists('is_account_page') && is_account_page())) {
        return;
    }
    if (function_exists('WC')) {
        echo '<script defer src="'.esc_url($settings['origin'].'/embed/native-cart-v1.js').'" data-platform="woocommerce" data-cart-url="'.esc_url(rest_url('wc/store/v1/cart')).'"'
            .' data-attribution-url="'.esc_url(admin_url('admin-ajax.php')).'" data-attribution-nonce="'.esc_attr(wp_create_nonce('shopbonus_attribution')).'"></script>';
    }
    echo '<script defer src="'.esc_url($settings['origin'].'/embed/v1.js').'" data-shopbonus-key="'.esc_attr($settings['key']).'"></script>';
});

// Assignment bridge: the storefront reader stores the consented ShopBonus click ID / experiment assignment
// token in the WooCommerce session (admin-ajax, where WooCommerce loads its session), and checkout copies them
// onto the order as `_shopbonus_click_id` / `_shopbonus_assignment_token`, which ShopBonus reads from order meta_data.
const SHOPBONUS_ATTRIBUTION_SESSION = 'shopbonus_attribution';

function shopbonus_banner_attribution_values($input)
{
    $values = [];
    $token = $input['assignment_token'] ?? null;
    $click = $input['click_id'] ?? null;
    if (is_string($token) && preg_match('/\Abx_[a-f0-9]{64}\z/D', $token)) {
        $values['assignment_token'] = $token;
    }
    if (is_string($click) && preg_match('/\Abc_[A-Za-z0-9]{24}\z/D', $click)) {
        $values['click_id'] = $click;
    }

    return $values;
}

function shopbonus_banner_store_attribution()
{
    $settings = shopbonus_banner_sanitize(get_option('shopbonus_banner', []));
    if (! $settings['enabled'] || ! check_ajax_referer('shopbonus_attribution', 'nonce', false) || ! function_exists('WC') || ! WC()->session) {
        wp_send_json(['stored' => false], 403);
    }
    // An empty or invalid submission clears the stored values (tracking withdrawn).
    $values = shopbonus_banner_attribution_values(wp_unslash($_POST));
    if ($values !== [] && ! WC()->session->has_session()) {
        WC()->session->set_customer_session_cookie(true);
    }
    WC()->session->set(SHOPBONUS_ATTRIBUTION_SESSION, $values === [] ? null : $values);
    wp_send_json(['stored' => $values !== []]);
}
add_action('wp_ajax_shopbonus_attribution', 'shopbonus_banner_store_attribution');
add_action('wp_ajax_nopriv_shopbonus_attribution', 'shopbonus_banner_store_attribution');

function shopbonus_banner_copy_attribution($order)
{
    if (! function_exists('WC') || ! WC()->session || ! is_object($order)) {
        return;
    }
    $values = shopbonus_banner_attribution_values((array) WC()->session->get(SHOPBONUS_ATTRIBUTION_SESSION, []));
    if (isset($values['assignment_token'])) {
        $order->update_meta_data('_shopbonus_assignment_token', $values['assignment_token']);
    }
    if (isset($values['click_id'])) {
        $order->update_meta_data('_shopbonus_click_id', $values['click_id']);
    }
    // One order per stored assignment; a later order needs a fresh consented assignment.
    WC()->session->set(SHOPBONUS_ATTRIBUTION_SESSION, null);
}
add_action('woocommerce_checkout_create_order', 'shopbonus_banner_copy_attribution');
add_action('woocommerce_store_api_checkout_update_order_meta', 'shopbonus_banner_copy_attribution');

register_uninstall_hook(__FILE__, 'shopbonus_banner_uninstall');
function shopbonus_banner_uninstall()
{
    delete_option('shopbonus_banner');
}
