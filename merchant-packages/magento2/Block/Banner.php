<?php

namespace Shopbonus\AsyncBridge\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Shopbonus\AsyncBridge\Model\Config;

/** Ordered external integration scripts only, never cache private cart amounts into page HTML. */
class Banner extends Template
{
    public function __construct(Context $context, private Config $config, array $data = [])
    {
        parent::__construct($context, $data);
    }

    protected function _toHtml(): string
    {
        $action = $this->getRequest()->getFullActionName();
        if (preg_match('/\A(?:checkout|customer|sales|payment)_/i', $action)) {
            return '';
        }
        $store = (int) $this->_storeManager->getStore()->getId();
        if (! $this->config->bannerEnabled($store)) {
            return '';
        }
        require_once __DIR__.'/../Shared/Delivery.php';
        $origin = \ShopbonusDelivery::origin($this->config->bannerOrigin($store));
        $key = $this->config->bannerKey($store);
        if ($origin === null || ! preg_match('/\Apk_[A-Za-z0-9_-]+\z/D', $key)) {
            return '';
        }
        $url = $this->getUrl('customer/section/load', ['_secure' => true, '_nosid' => true, '_query' => ['sections' => 'shopbonus_cart']]);
        $escape = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<script defer src="'.$escape($origin.'/embed/native-cart-v1.js').'" data-platform="magento" data-cart-url="'.$escape($url).'"></script>'
            .'<script defer src="'.$escape($origin.'/embed/v1.js').'" data-shopbonus-key="'.$escape($key).'"></script>';
    }
}
