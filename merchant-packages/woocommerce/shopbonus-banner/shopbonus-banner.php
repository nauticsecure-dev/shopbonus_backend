<?php

/**
 * Plugin Name: Shopbonus Banner
 * Description: Merchant-controlled placement of the Shopbonus public banner. Order delivery uses WooCommerce's native webhooks.
 * Version: 0.1.0
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
    echo '<script defer src="'.esc_url($settings['origin'].'/embed/v1.js').'" data-shopbonus-key="'.esc_attr($settings['key']).'"></script>';
});
register_uninstall_hook(__FILE__, 'shopbonus_banner_uninstall');
function shopbonus_banner_uninstall()
{
    delete_option('shopbonus_banner');
}
