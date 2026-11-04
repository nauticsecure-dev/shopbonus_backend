<?php

namespace Shopbonus\AsyncBridge\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Shopbonus\AsyncBridge\Model\Config;
use Shopbonus\AsyncBridge\Model\Notification;

class Deliver
{
    public function __construct(private ResourceConnection $resource, private Config $config, private LockManagerInterface $locks, private LoggerInterface $logger, private StoreManagerInterface $stores) {}

    public function execute(): void
    {
        if (! $this->locks->lock('shopbonus_async_delivery', 0)) {
            return;
        }
        try {
            require_once __DIR__.'/../Shared/Delivery.php';
            $db = $this->resource->getConnection();
            $table = $this->resource->getTableName('shopbonus_async_outbox');
            $enabled = [];
            foreach ($this->stores->getStores() as $store) {
                if ($this->config->enabled((int) $store->getId())) {
                    $enabled[] = (int) $store->getId();
                }
            }
            if ($enabled === []) {
                return;
            }
            $rows = $db->fetchAll($db->select()->from($table)->where('store_id IN (?)', $enabled)->where('delivered = ?', 0)->where('next_attempt <= ?', time())->order('id ASC')->limit(10));
            foreach ($rows as $row) {
                $store = (int) $row['store_id'];
                if (! $this->config->enabled($store)) {
                    continue;
                }
                $payload = json_decode($row['payload'], true, 8, JSON_THROW_ON_ERROR);
                $expected = Notification::payload((string) $store, (string) ($payload['store_code'] ?? ''), (string) $row['order_id']);
                if ($payload !== $expected) {
                    throw new \RuntimeException('Invalid native outbox payload.');
                }
                $ok = \ShopbonusDelivery::send($this->config->endpoint($store), 'magento', $this->config->secret($store), $row['event_id'], $payload);
                // A concurrent observer replaces event_id; never acknowledge or postpone its newer event.
                $db->update($table, $ok ? ['delivered' => 1] : ['attempts' => (int) $row['attempts'] + 1, 'next_attempt' => time() + Notification::delay((int) $row['attempts'])], ['id = ?' => $row['id'], 'event_id = ?' => $row['event_id']]);
            }
        } catch (\Throwable) {
            $this->logger->error('ShopBonus asynchronous delivery failed; inspect cron/outbox and native recovery.');
        } finally {
            $this->locks->unlock('shopbonus_async_delivery');
        }
    }
}
