<?php

namespace Opencart\Admin\Controller\Extension\Shopbonus\Module;

use Opencart\System\Engine\Controller;

class Shopbonus extends Controller
{
    private const ROUTE = 'extension/shopbonus/module/shopbonus';

    public function index(): void
    {
        $this->document->setTitle('Shopbonus order bridge');
        $data = [];
        foreach (['endpoint', 'origin', 'key', 'timezone', 'status', 'banner'] as $field) {
            $data[$field] = $this->config->get('module_shopbonus_'.$field) ?? '';
        }
        $data['save'] = $this->url->link(self::ROUTE.'.save', 'user_token='.$this->session->data['user_token']);
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view(self::ROUTE, $data));
    }

    public function save(): void
    {
        if (! $this->user->hasPermission('modify', self::ROUTE) || ($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');

            return;
        }
        require_once DIR_EXTENSION.'shopbonus/system/library/Delivery.php';
        $post = $this->request->post;
        $endpoint = (string) ($post['endpoint'] ?? '');
        $origin = (string) ($post['origin'] ?? '');
        $key = (string) ($post['key'] ?? '');
        $secret = (string) ($post['secret'] ?? '');
        $zone = (string) ($post['timezone'] ?? '');
        if (($endpoint !== '' && ! \ShopbonusDelivery::endpoint($endpoint, 'opencart')) || ($origin !== '' && \ShopbonusDelivery::origin($origin) === null)
            || ($key !== '' && ! preg_match('/\Apk_[A-Za-z0-9_-]+\z/D', $key)) || ($secret !== '' && ! preg_match('/\A[A-Za-z0-9]{32,128}\z/D', $secret))
            || ! in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            $this->response->addHeader('HTTP/1.1 422 Unprocessable Entity');
            $this->response->setOutput('Invalid configuration. Supply HTTPS URLs, a public banner key and the actual database timezone.');

            return;
        }
        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting->getSetting('module_shopbonus', 0);
        foreach (['endpoint' => $endpoint, 'origin' => $origin, 'key' => $key, 'timezone' => $zone,
            'status' => ($post['status'] ?? '') === '1' ? '1' : '0', 'banner' => ($post['banner'] ?? '') === '1' ? '1' : '0'] as $field => $value) {
            $settings['module_shopbonus_'.$field] = $value;
        }
        if ($secret !== '') {
            $settings['module_shopbonus_secret'] = $secret;
        }
        $this->model_setting_setting->editSetting('module_shopbonus', $settings, 0);
        $this->response->redirect($this->url->link(self::ROUTE, 'user_token='.$this->session->data['user_token']));
    }

    public function install(): void
    {
        if (! $this->user->hasPermission('modify', 'marketplace/extension')) {
            return;
        }
        $this->db->query('CREATE TABLE IF NOT EXISTS `'.DB_PREFIX.'shopbonus_outbox` (`order_id` INT UNSIGNED NOT NULL PRIMARY KEY, `event_id` CHAR(32) NOT NULL, `delivered` TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode('shopbonus');
        foreach (['checkout/order.addOrder' => 'created', 'checkout/order.addHistory' => 'updated'] as $trigger => $action) {
            $this->model_setting_event->addEvent(['code' => 'shopbonus', 'description' => 'Queue Shopbonus order lookup', 'trigger' => 'catalog/model/'.$trigger.'/after', 'action' => self::ROUTE.'.'.$action, 'status' => 1, 'sort_order' => 0]);
        }
        $this->model_setting_event->addEvent(['code' => 'shopbonus', 'description' => 'Shopbonus session cart snapshot', 'trigger' => 'catalog/controller/common/cart.info/after', 'action' => self::ROUTE.'.cartSnapshot', 'status' => 1, 'sort_order' => 0]);
        $this->model_setting_event->addEvent(['code' => 'shopbonus', 'description' => 'Shopbonus opt-in banner', 'trigger' => 'catalog/controller/common/footer/after', 'action' => self::ROUTE.'.banner', 'status' => 1, 'sort_order' => 0]);
    }

    public function uninstall(): void
    {
        if (! $this->user->hasPermission('modify', 'marketplace/extension')) {
            return;
        }
        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode('shopbonus');
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting('module_shopbonus', 0);
        $this->db->query('DROP TABLE IF EXISTS `'.DB_PREFIX.'shopbonus_outbox`');
    }
}
