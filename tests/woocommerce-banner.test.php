<?php

// Isolated WordPress hook/escaping contract fixture; no real WordPress installation.
define('ABSPATH', __DIR__);
$hooks = [];
$settings = ['origin' => 'https://api.example.test', 'key' => 'pk_test', 'enabled' => true];
$checkout = false;
$account = false;
function add_action($name, $callback)
{
    $GLOBALS['hooks'][$name] = $callback;
}
function register_uninstall_hook(...$args) {}
function esc_url_raw($url, $protocols)
{
    return $url;
}
function wp_parse_url($url)
{
    return parse_url($url);
}
function esc_url($url)
{
    return htmlspecialchars($url, ENT_QUOTES);
}
function esc_attr($value)
{
    return htmlspecialchars($value, ENT_QUOTES);
}
function get_option($name, $default)
{
    return $GLOBALS['settings'];
}
function is_checkout()
{
    return $GLOBALS['checkout'];
}
function is_account_page()
{
    return $GLOBALS['account'];
}
function WC() {}
function rest_url($route)
{
    return 'https://store.example.test/subdir/index.php?rest_route=/'.$route;
}
require __DIR__.'/../merchant-packages/woocommerce/shopbonus-banner/shopbonus-banner.php';
function render_banner()
{
    ob_start();
    ($GLOBALS['hooks']['wp_footer'])();

    return ob_get_clean();
}
function check($condition, $message)
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
$html = render_banner();
check(substr_count($html, '<script defer') === 2, 'Reader and renderer must both defer.');
check(strpos($html, '/embed/native-cart-v1.js') < strpos($html, '/embed/v1.js'), 'Reader must load first.');
check(str_contains($html, 'data-platform="woocommerce"'), 'Explicit platform missing.');
check(str_contains($html, 'data-cart-url="https://store.example.test/subdir/index.php?rest_route=/wc/store/v1/cart"'), 'Native WordPress REST URL missing.');
$checkout = true;
check(render_banner() === '', 'Checkout must be excluded.');
$checkout = false;
$account = true;
check(render_banner() === '', 'Localized account page must be excluded.');
$account = false;
$settings['enabled'] = false;
check(render_banner() === '', 'Disabled plugin must not render.');
$settings['enabled'] = true;
$settings['origin'] = 'https://user:secret@api.example.test';
check(render_banner() === '', 'Credential-bearing origin must not render.');
echo "WooCommerce footer contract: 8 checks passed (WordPress fixture only).\n";
