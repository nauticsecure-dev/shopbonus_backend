<?php

namespace App\Console\Commands;

use App\Enums\AuditCategory;
use App\Enums\EcommercePlatform;
use App\Models\ShopifyComplianceRequest;
use App\Models\WebshopConnection;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopifyComplianceRequests extends Command
{
    protected $signature = 'shopbonus:shopify-compliance {--resolve= : Request public ID} {--outcome= : completed|retained}';

    protected $description = 'List pending Shopify privacy requests or record the operator outcome; never erases data itself';

    public function handle(AuditLogger $audit): int
    {
        $id = $this->option('resolve');
        if ($id === null) {
            $rows = ShopifyComplianceRequest::where('status', 'pending')->orderBy('due_at')->limit(200)->get();
            $this->table(['id', 'topic', 'shop', 'valid', 'connections', 'deliveries', 'due', 'overdue'], $rows->map(fn ($r) => [
                $r->public_id, $r->topic, $r->shop_domain ?? '-', $r->payload_valid ? 'yes' : 'REVIEW', $r->shop_domain === null ? 0
                    : WebshopConnection::where('platform', EcommercePlatform::Shopify)->where('external_shop_ref', $r->shop_domain)->count(),
                $r->delivery_count, $r->due_at->toIso8601String(), $r->due_at->isPast() ? 'YES' : 'no',
            ])->all());

            return self::SUCCESS;
        }
        $outcome = $this->option('outcome');
        if (! Str::isUlid($id) || ! in_array($outcome, ShopifyComplianceRequest::OUTCOMES, true)) {
            $this->error('Use a request ID and --outcome=completed|retained.');

            return self::INVALID;
        }

        return DB::transaction(function () use ($id, $outcome, $audit) {
            $r = ShopifyComplianceRequest::where('public_id', strtolower($id))->lockForUpdate()->first();
            if ($r === null || $r->status !== 'pending') {
                $this->error('No pending request with this ID.');

                return self::FAILURE;
            }
            $r->forceFill(['status' => $outcome, 'resolved_at' => now()])->save();
            $audit->record('integration.shopify_compliance_resolved', category: AuditCategory::Integration, resource: $r,
                metadata: ['topic' => $r->topic, 'outcome' => $outcome, 'overdue' => $r->due_at->isPast()]);
            $this->info("Recorded {$outcome}.");

            return self::SUCCESS;
        });
    }
}
