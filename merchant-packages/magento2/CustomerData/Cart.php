<?php

namespace Shopbonus\AsyncBridge\CustomerData;

use Magento\Checkout\Model\Session;
use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;
use Shopbonus\AsyncBridge\Model\Config;

/** Private native section, read through Magento's existing cookie-bound customer/section/load endpoint. */
class Cart implements SectionSourceInterface
{
    public function __construct(private Session $session, private StoreManagerInterface $stores, private Config $config) {}

    public function getSectionData(): array
    {
        $store = $this->stores->getStore();
        if (! $this->config->bannerEnabled((int) $store->getId())) {
            return [];
        }
        try {
            $quote = $this->session->getQuote();
            if (! $quote->getId() || ! $quote->getIsActive() || (int) $quote->getStoreId() !== (int) $store->getId()
                || $quote->getQuoteCurrencyCode() !== $store->getCurrentCurrencyCode()) {
                return [];
            }
            $totals = $quote->getTotals();
            if (! isset($totals['grand_total'])) {
                return [];
            }
            require_once __DIR__.'/../Shared/CartSnapshot.php';

            return \ShopbonusCartSnapshot::fromMajor($totals['grand_total']->getValue(), $quote->getQuoteCurrencyCode(), 2, 'magento') ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}
