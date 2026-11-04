<?php

namespace Shopbonus\AsyncBridge\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public function __construct(private ScopeConfigInterface $config, private EncryptorInterface $encryptor) {}

    public function enabled(int $store): bool
    {
        return $this->config->isSetFlag('shopbonus_async/general/enabled', ScopeInterface::SCOPE_STORE, $store);
    }

    public function endpoint(int $store): string
    {
        return (string) $this->config->getValue('shopbonus_async/general/endpoint', ScopeInterface::SCOPE_STORE, $store);
    }

    public function secret(int $store): string
    {
        return $this->encryptor->decrypt((string) $this->config->getValue('shopbonus_async/general/secret', ScopeInterface::SCOPE_STORE, $store));
    }
}
