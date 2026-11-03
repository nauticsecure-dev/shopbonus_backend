<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once __DIR__.'/Delivery.php';

class Shopbonus extends Module
{
    public function __construct()
    {
        $this->name = 'shopbonus';
        $this->tab = 'advertising_marketing';
        $this->version = '0.1.0';
        $this->author = 'Shopbonus';
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        parent::__construct();
        $this->displayName = 'Shopbonus order bridge';
        $this->description = 'Signed native order notifications and optional storefront banner. Requires restricted Webservice reads and a CLI worker.';
    }

    public function install()
    {
        return parent::install() && Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'shopbonus_outbox` (`id_shop` INT UNSIGNED NOT NULL, `id_order` INT UNSIGNED NOT NULL, `event_id` CHAR(32) NOT NULL, `delivered` TINYINT NOT NULL DEFAULT 0, PRIMARY KEY (`id_shop`,`id_order`)) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4')
            && $this->registerHook(['actionValidateOrderAfter', 'actionObjectOrderHistoryAddAfter', 'actionObjectOrderPaymentAddAfter', 'actionObjectOrderSlipAddAfter', 'displayFooter']);
    }

    public function uninstall()
    {
        foreach (['SB_ENDPOINT', 'SB_SECRET', 'SB_ORIGIN', 'SB_KEY', 'SB_BANNER'] as $key) {
            Configuration::deleteByName($key);
        }

        return Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'shopbonus_outbox`') && parent::uninstall();
    }

    private function setting($key, $shop)
    {
        return (string) Configuration::get($key, null, null, (int) $shop);
    }

    public function getContent()
    {
        if (Shop::getContext() !== Shop::CONTEXT_SHOP) {
            return $this->displayError('Select one shop before configuring Shopbonus.');
        }
        $shop = (int) $this->context->shop->id;
        $message = '';
        if (Tools::isSubmit('submitShopbonus')) {
            if (!hash_equals(Tools::getAdminTokenLite('AdminModules'), (string) Tools::getValue('token'))) {
                return $this->displayError('Invalid administrator token.');
            }
            $endpoint = (string) Tools::getValue('SB_ENDPOINT');
            $secret = (string) Tools::getValue('SB_SECRET');
            $origin = (string) Tools::getValue('SB_ORIGIN');
            $key = (string) Tools::getValue('SB_KEY');
            if (($endpoint !== '' && !ShopbonusDelivery::endpoint($endpoint, 'prestashop')) || ($secret !== '' && !preg_match('/\A[A-Za-z0-9]{32,128}\z/D', $secret))
                || ($origin !== '' && ShopbonusDelivery::origin($origin) === null) || ($key !== '' && !preg_match('/\Apk_[A-Za-z0-9_-]+\z/D', $key))) {
                return $this->displayError('Enter a valid HTTPS webhook, signing secret, backend origin and public key.');
            }
            foreach (['SB_ENDPOINT' => $endpoint, 'SB_ORIGIN' => $origin, 'SB_KEY' => $key, 'SB_BANNER' => Tools::getValue('SB_BANNER') === '1' ? '1' : '0'] as $name => $value) {
                Configuration::updateValue($name, $value, false, null, $shop);
            }
            if ($secret !== '') {
                Configuration::updateValue('SB_SECRET', $secret, false, null, $shop);
            }
            $message = $this->displayConfirmation('Saved. Configure the backend Webservice key and run bin/worker.php every minute.');
        }
        $html = $message.'<form method="post"><input type="hidden" name="token" value="'.htmlspecialchars(Tools::getAdminTokenLite('AdminModules'), ENT_QUOTES, 'UTF-8').'">';
        foreach (['SB_ENDPOINT' => 'Shopbonus webhook URL', 'SB_SECRET' => 'Signing secret (blank preserves existing)', 'SB_ORIGIN' => 'Backend HTTPS origin', 'SB_KEY' => 'Banner public key'] as $name => $label) {
            $html .= '<p><label>'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'<input class="form-control" name="'.$name.'" type="'.($name === 'SB_SECRET' ? 'password' : 'text').'" value="'.($name === 'SB_SECRET' ? '' : htmlspecialchars($this->setting($name, $shop), ENT_QUOTES, 'UTF-8')).'" autocomplete="off"></label></p>';
        }

        return $html.'<label><input type="checkbox" name="SB_BANNER" value="1" '.($this->setting('SB_BANNER', $shop) === '1' ? 'checked' : '').'> Enable storefront banner</label><p><button class="btn btn-primary" name="submitShopbonus">Save</button></p></form>';
    }

    private function queue($id)
    {
        try {
            $order = new Order((int) $id);
            if (!Validate::isLoadedObject($order) || $this->setting('SB_ENDPOINT', $order->id_shop) === '') {
                return;
            }
            $event = bin2hex(random_bytes(16));
            Db::getInstance()->execute('INSERT INTO `'._DB_PREFIX_.'shopbonus_outbox` (id_shop,id_order,event_id,delivered) VALUES ('.(int) $order->id_shop.','.(int) $id.',\''.$event.'\',0) ON DUPLICATE KEY UPDATE event_id=VALUES(event_id),delivered=0');
        } catch (Throwable $error) {
            PrestaShopLogger::addLog('Shopbonus could not queue an order update.', 3);
        }
    }

    public function hookActionValidateOrderAfter($params)
    {
        foreach ($params['orders'] ?? (isset($params['order']) ? [$params['order']] : []) as $order) {
            $this->queue($order->id);
        }
    }

    public function hookActionObjectOrderHistoryAddAfter($params)
    {
        $this->queue($params['object']->id_order);
    }

    public function hookActionObjectOrderSlipAddAfter($params)
    {
        $this->queue($params['object']->id_order);
    }

    public function hookActionObjectOrderPaymentAddAfter($params)
    {
        try {
            $reference = $params['object']->order_reference;
            foreach (Db::getInstance()->executeS('SELECT id_order FROM `'._DB_PREFIX_.'orders` WHERE reference=\''.pSQL($reference).'\'') ?: [] as $row) {
                $this->queue($row['id_order']);
            }
        } catch (Throwable $error) {
            PrestaShopLogger::addLog('Shopbonus could not queue a payment update.', 3);
        }
    }

    public function deliverQueue()
    {
        foreach (Db::getInstance()->executeS('SELECT * FROM `'._DB_PREFIX_.'shopbonus_outbox` WHERE delivered=0 ORDER BY id_shop,id_order LIMIT 25') ?: [] as $row) {
            $shop = (int) $row['id_shop'];
            if (!$this->active || !Db::getInstance()->getValue('SELECT id_module FROM `'._DB_PREFIX_.'module_shop` WHERE id_module='.(int) $this->id.' AND id_shop='.$shop)) {
                continue;
            }
            $payload = ['shopbonus_lookup_v1' => true, 'shop_id' => (string) $shop, 'order_id' => (string) $row['id_order']];
            if (ShopbonusDelivery::send($this->setting('SB_ENDPOINT', $shop), 'prestashop', $this->setting('SB_SECRET', $shop), $row['event_id'], $payload)) {
                Db::getInstance()->execute('UPDATE `'._DB_PREFIX_.'shopbonus_outbox` SET delivered=1 WHERE id_shop='.$shop.' AND id_order='.(int) $row['id_order'].' AND event_id=\''.pSQL($row['event_id']).'\'');
            }
        }
    }

    public function hookDisplayFooter()
    {
        $shop = (int) $this->context->shop->id;
        $origin = ShopbonusDelivery::origin($this->setting('SB_ORIGIN', $shop));
        $key = $this->setting('SB_KEY', $shop);
        if ($this->setting('SB_BANNER', $shop) !== '1' || $origin === null || !preg_match('/\Apk_[A-Za-z0-9_-]+\z/D', $key)) {
            return '';
        }

        return '<script defer src="'.htmlspecialchars($origin.'/embed/v1.js', ENT_QUOTES, 'UTF-8').'" data-shopbonus-key="'.htmlspecialchars($key, ENT_QUOTES, 'UTF-8').'"></script>';
    }
}
