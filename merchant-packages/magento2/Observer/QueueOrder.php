<?php

namespace Shopbonus\AsyncBridge\Observer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Shopbonus\AsyncBridge\Model\Config;
use Shopbonus\AsyncBridge\Model\Notification;

/** Commit-time local write only. Never call an external service from an order/payment observer. */
class QueueOrder implements ObserverInterface
{
    public function __construct(private ResourceConnection $resource, private StoreManagerInterface $stores, private Config $config, private LoggerInterface $logger) {}

    public function execute(Observer $observer): void
    {
        try {
            $event = $observer->getEvent();
            $entity = $event->getData('order') ?: ($event->getData('invoice') ?: ($event->getData('creditmemo') ?: $event->getData('data_object')));
            if (! is_object($entity) || ! method_exists($entity, 'getData')) {
                return;
            }
            $isOrder = $event->getName() === 'sales_order_save_commit_after';
            $order = (string) $entity->getData($isOrder ? 'entity_id' : 'order_id');
            $store = (int) $entity->getData('store_id');
            if ($store < 1 || ! $this->config->enabled($store)) {
                return;
            }
            $payload = Notification::payload((string) $store, (string) $this->stores->getStore($store)->getCode(), $order);
            $db = $this->resource->getConnection();
            $db->insertOnDuplicate($this->resource->getTableName('shopbonus_async_outbox'), ['store_id' => $store, 'order_id' => $order, 'event_id' => bin2hex(random_bytes(16)), 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'attempts' => 0, 'next_attempt' => 0, 'delivered' => 0], ['event_id', 'payload', 'attempts', 'next_attempt', 'delivered']);
        } catch (\Throwable) {
            $this->logger->error('ShopBonus local outbox enqueue failed; native backend recovery is required.');
        }
    }
}
