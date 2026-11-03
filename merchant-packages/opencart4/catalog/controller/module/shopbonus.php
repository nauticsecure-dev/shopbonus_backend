<?php

namespace Opencart\Catalog\Controller\Extension\Shopbonus\Module;

use Opencart\System\Engine\Controller;

class Shopbonus extends Controller
{
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
