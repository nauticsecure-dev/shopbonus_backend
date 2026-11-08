<?php

namespace Opencart\Catalog\Controller\Extension\Shopbonus\Module;

use Opencart\System\Engine\Controller;

class Shopbonus extends Controller
{
    public function coupon(): void
    {
        require_once DIR_EXTENSION.'shopbonus/system/library/CouponRead.php';
        $this->response->addHeader('Content-Type: application/json');
        $this->response->addHeader('Cache-Control: no-store');
        $secret = (string) $this->config->get('module_shopbonus_secret');
        $code = $this->request->get['code'] ?? null;
        $time = $this->request->server['HTTP_X_SHOPBONUS_TIME'] ?? '';
        $nonce = $this->request->server['HTTP_X_SHOPBONUS_NONCE'] ?? '';
        $signature = $this->request->server['HTTP_X_SHOPBONUS_SIGNATURE'] ?? '';
        if (! $this->config->get('module_shopbonus_coupon_read') || (int) $this->config->get('config_store_id') !== 0
            || ($this->request->server['REQUEST_METHOD'] ?? '') !== 'GET' || ! is_string($code)
            || ! \ShopbonusOpenCartCouponRead::authorize($secret, $code, $time, $nonce, $signature, time())) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->setOutput('{}');

            return;
        }
        try {
            $timezone = (string) $this->config->get('module_shopbonus_timezone');
            $coupon = \ShopbonusOpenCartCouponRead::coupon($this->db, DB_PREFIX, $code, $timezone);
            $totals = [];
            foreach ($this->db->query('SELECT extension,code FROM `'.DB_PREFIX."extension` WHERE type='total'")->rows as $extension) {
                if ($this->config->get('total_'.$extension['code'].'_status')) {
                    $totals[] = $extension['extension'].':'.$extension['code'];
                }
            }
            sort($totals);
            $payload = json_encode(['schema' => 'shopbonus.opencart.coupon.v1', 'nonce' => $nonce, 'observed_at' => time(), 'store_id' => 0,
                'store_url' => rtrim((string) $this->config->get('config_url'), '/'), 'currency' => $this->config->get('config_currency'),
                'timezone' => $timezone, 'enabled_totals' => $totals, 'coupon' => $coupon], JSON_THROW_ON_ERROR);
            $this->response->setOutput(json_encode(['payload' => $payload, 'signature' => hash_hmac('sha256', 'shopbonus.opencart.coupon.v1.response|'.$payload, $secret)], JSON_THROW_ON_ERROR));
        } catch (\Throwable $error) {
            $this->response->addHeader('HTTP/1.1 503 Service Unavailable');
            $this->response->setOutput('{}');
        }
    }

    public function created(string &$route, array &$args, mixed &$output): void
    {
        $this->queue((int) $output);
    }

    public function updated(string &$route, array &$args, mixed &$output): void
    {
        $this->queue((int) ($args[0] ?? 0));
    }

    private function queue(int $id): void
    {
        if (! $this->config->get('module_shopbonus_status') || (int) $this->config->get('config_store_id') !== 0 || $id < 1) {
            return;
        }
        try {
            $this->db->query('INSERT INTO `'.DB_PREFIX.'shopbonus_outbox` (order_id,event_id,delivered) VALUES ('.$id.',\''.bin2hex(random_bytes(16)).'\',0) ON DUPLICATE KEY UPDATE event_id=VALUES(event_id),delivered=0');
        } catch (\Throwable $error) {
            $this->log->write('Shopbonus could not queue an order update.');
        }
    }

    public function banner(string &$route, array &$args, mixed &$output): void
    {
        if (! $this->config->get('module_shopbonus_banner') || (int) $this->config->get('config_store_id') !== 0 || ! is_string($output)) {
            return;
        }
        require_once DIR_EXTENSION.'shopbonus/system/library/Delivery.php';
        $origin = \ShopbonusDelivery::origin((string) $this->config->get('module_shopbonus_origin'));
        $key = (string) $this->config->get('module_shopbonus_key');
        if ($origin === null || ! preg_match('/\Apk_[A-Za-z0-9_-]+\z/D', $key)) {
            return;
        }
        $script = '<script defer src="'.htmlspecialchars($origin.'/embed/v1.js', ENT_QUOTES, 'UTF-8').'" data-shopbonus-key="'.htmlspecialchars($key, ENT_QUOTES, 'UTF-8').'"></script>';
        $output = str_contains($output, '</body>') ? str_replace('</body>', $script.'</body>', $output) : $output.$script;
    }
}
