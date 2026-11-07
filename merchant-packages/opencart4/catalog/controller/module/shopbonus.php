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
        $nativeRoute = (string) ($this->request->get['route'] ?? '');
        if (preg_match('~(?:^|/)(?:checkout|account|payment)(?:/|\.|$)~i', $nativeRoute)) {
            return;
        }
        $cartUrl = $this->url->link('common/cart.info', 'language='.(string) $this->config->get('config_language'), true);
        $script = '<script defer src="'.htmlspecialchars($origin.'/embed/native-cart-v1.js', ENT_QUOTES, 'UTF-8').'" data-platform="opencart" data-cart-url="'.htmlspecialchars($cartUrl, ENT_QUOTES, 'UTF-8').'"></script>'
            .'<script defer src="'.htmlspecialchars($origin.'/embed/v1.js', ENT_QUOTES, 'UTF-8').'" data-shopbonus-key="'.htmlspecialchars($key, ENT_QUOTES, 'UTF-8').'"></script>';
        $output = str_contains($output, '</body>') ? str_replace('</body>', $script.'</body>', $output) : $output.$script;
    }

    /** Header on the existing native cart.info response; no new shop/catalogue read endpoint. */
    public function cartSnapshot(string &$route, array &$args, mixed &$output): void
    {
        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'GET' || ! $this->config->get('module_shopbonus_status') || ! $this->config->get('module_shopbonus_banner')
            || (int) $this->config->get('config_store_id') !== 0 || (! $this->customer->isLogged() && $this->config->get('config_customer_price'))) {
            return;
        }
        $this->response->addHeader('Cache-Control: private, no-store, max-age=0');
        $this->response->addHeader('Vary: Cookie');
        require_once DIR_EXTENSION.'shopbonus/system/library/CartSnapshot.php';
        try {
            $currency = $this->session->data['currency'] ?? null;
            if (! is_string($currency) || ! $this->currency->has($currency) || $this->currency->getDecimalPlace($currency) !== 2) {
                return;
            }
            $totals = [];
            $taxes = $this->cart->getTaxes();
            $total = 0;
            $this->load->model('checkout/cart');
            ($this->model_checkout_cart->getTotals)($totals, $taxes, $total);
            // Use OpenCart's native presentment conversion/rounding, not the base-currency total.
            $amount = $this->currency->format($total, $currency, 0, false);
            $snapshot = \ShopbonusCartSnapshot::fromMajor($amount, $currency, 2, 'opencart');
            if ($snapshot !== null) {
                $this->response->addHeader('X-ShopBonus-Cart: '.json_encode($snapshot, JSON_THROW_ON_ERROR));
            }
        } catch (\Throwable) {
            // Native HTML/cart operation remains usable when the optional snapshot cannot be calculated.
        }
    }
}
