<?php

namespace App\Services\Affiliate;

use App\Affiliate\AffiliateNetworkRegistry;
use App\Affiliate\Data\AffiliateProgramData;
use App\Enums\AuditCategory;
use App\Models\AffiliateConnection;
use App\Models\AffiliateProgram;
use App\Models\Webshop;
use App\Services\Audit\AuditLogger;
use Throwable;

/**
 * Pulls programs and conversions from a network (or takes them from an
 * admin import of the network's export) and feeds them through
 * AffiliateProcessor. Failures change the connection status; they never
 * touch wallets.
 */
class AffiliateSync
{
    public function __construct(
        private readonly AffiliateNetworkRegistry $registry,
        private readonly AffiliateProcessor $processor,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{programs: int, transactions: int, error: string|null}
     */
    public function sync(AffiliateConnection $connection): array
    {
        $adapter = $this->registry->for($connection->network);

        try {
            $programs = $adapter->fetchPrograms($connection);
            $transactions = $adapter->fetchTransactions($connection, $connection->last_synced_at?->subDays(7));
        } catch (Throwable $e) {
            $error = mb_substr(class_basename($e).': '.$e->getMessage(), 0, 255);
            $connection->forceFill(['status' => $connection->status === 'disabled' ? 'disabled' : ($connection->network === 'tradetracker' ? 'limited' : 'error'), 'last_error' => $error])->save();
            $this->audit->recordSafely('affiliate.sync_failed', category: AuditCategory::Integration, resource: $connection, metadata: ['error' => $error]);

            return ['programs' => 0, 'transactions' => 0, 'error' => $error];
        }

        $this->upsertPrograms($connection, $programs);
        foreach ($transactions as $data) {
            $this->processor->ingest($connection, $data, 'sync');
        }
        $connection->forceFill(['last_synced_at' => now(), 'last_error' => null, 'status' => 'connected'])->save();

        return ['programs' => count($programs), 'transactions' => count($transactions), 'error' => null];
    }

    /**
     * Admin import of the network's own export rows (e.g. TradeTracker, whose
     * API transport is not verified yet).
     *
     * @param  list<array<string, mixed>>  $programs
     * @param  list<array<string, mixed>>  $transactions
     * @return array{programs: int, transactions: int, rejected: list<array{row: int, error: string}>}
     */
    public function import(AffiliateConnection $connection, array $programs, array $transactions, int $actorUserId): array
    {
        $adapter = $this->registry->for($connection->network);
        $rejected = [];
        $normalized = [];
        foreach ($programs as $i => $row) {
            try {
                $normalized[] = $adapter->normalizeProgram($row);
            } catch (Throwable $e) {
                $rejected[] = ['row' => $i, 'error' => 'program: '.mb_substr($e->getMessage(), 0, 120)];
            }
        }
        $this->upsertPrograms($connection, $normalized);

        $count = 0;
        foreach ($transactions as $i => $row) {
            try {
                $this->processor->ingest($connection, $adapter->normalizeTransaction($row), 'import', $actorUserId);
                $count++;
            } catch (Throwable $e) {
                $rejected[] = ['row' => $i, 'error' => 'transaction: '.mb_substr($e->getMessage(), 0, 120)];
            }
        }

        $this->audit->record('affiliate.imported', category: AuditCategory::Integration, resource: $connection, actorUserId: $actorUserId,
            metadata: ['programs' => count($normalized), 'transactions' => $count, 'rejected' => count($rejected)]);

        return ['programs' => count($normalized), 'transactions' => $count, 'rejected' => $rejected];
    }

    /**
     * @param  list<AffiliateProgramData>  $programs
     */
    private function upsertPrograms(AffiliateConnection $connection, array $programs): void
    {
        foreach ($programs as $p) {
            $program = AffiliateProgram::firstOrNew(['connection_id' => $connection->id, 'external_id' => $p->externalId]);
            $program->forceFill([
                'name' => $p->name, 'domain' => $p->domain, 'active' => $p->active, 'commission_type' => $p->commissionType,
                'commission_value' => $p->commissionValue, 'currency' => $p->currency, 'category_restricted' => $p->categoryRestricted,
                'cashback_allowed' => $p->cashbackAllowed, 'tracking_url_template' => $p->trackingUrlTemplate, 'last_seen_at' => now(),
            ]);
            // Auto-match only on a domain the merchant proved they control (B03c); everything else is an admin decision.
            if ($program->webshop_id === null && $p->domain !== null) {
                $matches = Webshop::where('verified_domain', strtolower(preg_replace('/^www\./', '', $p->domain) ?? $p->domain))->pluck('id');
                $program->webshop_id = $matches->count() === 1 ? $matches->first() : null;
            }
            $program->save();
        }
    }
}
