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
final class FakeSession
{
    public array $data = [];

    public bool $cookie = false;

    public function get($key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function set($key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function has_session(): bool
    {
        return $this->cookie;
    }

    public function set_customer_session_cookie($set): void
    {
        $this->cookie = true;
    }
}
final class FakeOrder
{
    public array $meta = [];

    public function update_meta_data($key, $value): void
    {
        $this->meta[$key] = $value;
    }
}
final class JsonSent extends Exception
{
    public function __construct(public array $payload, public int $status) {}
}
$wc = (object) ['session' => new FakeSession];
$nonceValid = true;
function WC()
{
    return $GLOBALS['wc'];
}
function admin_url($path)
{
    return 'https://store.example.test/subdir/wp-admin/'.$path;
}
function wp_create_nonce($action)
{
    return 'n0nce123';
}
function check_ajax_referer($action, $field, $die)
{
    return $GLOBALS['nonceValid'];
}
function wp_unslash($value)
{
    return $value;
}
function wp_send_json($payload, $status = 200)
{
    throw new JsonSent($payload, $status);
}
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
$settings['origin'] = 'https://api.example.test';
check(str_contains(render_banner(), 'data-attribution-url="https://store.example.test/subdir/wp-admin/admin-ajax.php" data-attribution-nonce="n0nce123"'), 'Attribution bridge attributes missing.');

// Assignment bridge: exact values stored in the WooCommerce session, copied to the order once, cleared on withdrawal.
function post_attribution(array $post): JsonSent
{
    $_POST = $post;
    try {
        $GLOBALS['hooks']['wp_ajax_nopriv_shopbonus_attribution']();
    } catch (JsonSent $sent) {
        return $sent;
    }
    throw new RuntimeException('No JSON response.');
}
$token = 'bx_'.str_repeat('a', 64);
$click = 'bc_'.str_repeat('A1', 12);
check(post_attribution(['assignment_token' => $token, 'click_id' => $click])->payload === ['stored' => true], 'Valid attribution must be stored.');
check($wc->session->cookie, 'Guest session cookie must be started for the bridge.');
$order = new FakeOrder;
($GLOBALS['hooks']['woocommerce_checkout_create_order'])($order);
check($order->meta === ['_shopbonus_assignment_token' => $token, '_shopbonus_click_id' => $click], 'Classic checkout must copy both values to order meta.');
$second = new FakeOrder;
($GLOBALS['hooks']['woocommerce_store_api_checkout_update_order_meta'])($second);
check($second->meta === [], 'A stored assignment is used for one order only.');
post_attribution(['assignment_token' => $token]);
check(post_attribution([])->payload === ['stored' => false] && $wc->session->get(SHOPBONUS_ATTRIBUTION_SESSION) === null, 'Withdrawal must clear the stored values.');
post_attribution(['assignment_token' => strtoupper($token), 'click_id' => 'bc_short']);
check($wc->session->get(SHOPBONUS_ATTRIBUTION_SESSION) === null, 'Malformed values must never be stored.');
$nonceValid = false;
check(post_attribution(['assignment_token' => $token])->status === 403, 'A missing/invalid nonce must be refused.');
echo "WooCommerce footer and attribution bridge contract: 16 checks passed (WordPress fixture only).\n";
