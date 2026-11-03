<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$lock = fopen(sys_get_temp_dir().'/shopbonus-'.hash('sha256', __DIR__).'.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
require dirname(__DIR__, 3).'/config.php';
require dirname(__DIR__).'/system/library/Delivery.php';
require dirname(__DIR__).'/system/library/OrderPayload.php';
if (DB_DRIVER !== 'mysqli' || !preg_match('/\A[A-Za-z0-9_]*\z/D', DB_PREFIX)) {
    fwrite(STDERR, "Shopbonus worker requires the reviewed mysqli database driver.\n");
    exit(1);
}
try {
    $db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int) DB_PORT);
    $db->set_charset('utf8mb4');
    $settings = [];
    foreach ($db->query("SELECT `key`,`value` FROM `".DB_PREFIX."setting` WHERE store_id=0 AND code='module_shopbonus'") as $row) {
        $settings[$row['key']] = $row['value'];
    }
    if (($settings['module_shopbonus_status'] ?? '') !== '1') {
        exit(0);
    }
    foreach ($db->query('SELECT * FROM `'.DB_PREFIX.'shopbonus_outbox` WHERE delivered=0 ORDER BY order_id LIMIT 25') as $row) {
        $order = $db->query('SELECT * FROM `'.DB_PREFIX.'order` WHERE store_id=0 AND order_id='.(int) $row['order_id'])->fetch_assoc();
        if (!$order) {
            continue;
        }
        $payload = ShopbonusOpenCartOrder::payload($order, $settings['module_shopbonus_timezone'] ?? '');
        if (ShopbonusDelivery::send($settings['module_shopbonus_endpoint'] ?? '', 'opencart', $settings['module_shopbonus_secret'] ?? '', $row['event_id'], $payload)) {
            $db->query('UPDATE `'.DB_PREFIX.'shopbonus_outbox` SET delivered=1 WHERE order_id='.(int) $row['order_id'].' AND event_id=\''.$db->real_escape_string($row['event_id']).'\'');
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Shopbonus delivery failed; inspect configuration and retry.\n");
    exit(1);
}
