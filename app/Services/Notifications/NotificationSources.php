<?php

namespace App\Services\Notifications;

use App\Enums\AuditOutcome;
use App\Enums\LedgerEntryType;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\PlatformRole;
use App\Enums\WalletKind;
use App\Enums\WorkspacePermission;
use App\Models\AffiliateRewardRule;
use App\Models\AuditEvent;
use App\Models\MerchantNotification;
use App\Models\NotificationEvent;
use App\Models\Offer;
use App\Models\SupportReview;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherEvent;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopMembership;
use App\Services\Analytics\MerchantNotifications;
use Illuminate\Support\Facades\DB;

/** Internal source adapters. No client event-ingestion route exists. */
final class NotificationSources
{
    public static function register(): void
    {
        AffiliateRewardRule::created(function ($rule) {
            if ($rule->active) {
                Webshop::where('status', 'approved')->orderBy('id')->chunkById(100, function ($shops) use ($rule) {
                    foreach ($shops as $shop) {
                        app(self::class)->workspace('commission_changed', $shop->id, 'reward-rule:'.$rule->version.':shop:'.$shop->id);
                    }
                });
            }
        });
        SupportReview::created(fn ($review) => app(self::class)->administrators('ai_support_escalated', 'support-review:'.$review->public_id));
        AffiliateRewardRule::updated(function ($rule) {
            if ($rule->wasChanged('active')) {
                Webshop::where('status', 'approved')->orderBy('id')->chunkById(100, function ($shops) use ($rule) {
                    foreach ($shops as $shop) {
                        app(self::class)->workspace('commission_changed', $shop->id, 'reward-rule:'.$rule->version.':'.(int) $rule->active.':shop:'.$shop->id);
                    }
                });
            }
        });
        VoucherEvent::created(fn (VoucherEvent $e) => app(self::class)->voucher($e));
        WalletLedgerEntry::created(fn (WalletLedgerEntry $e) => app(self::class)->ledger($e));
        AuditEvent::created(fn (AuditEvent $e) => app(self::class)->audit($e));
        MerchantNotification::created(fn (MerchantNotification $n) => app(self::class)->merchant($n));
        WebshopConnection::updated(function (WebshopConnection $c) {
            if ($c->wasChanged('status') && $c->status->value === 'error') {
                app(MerchantNotifications::class)->forWebshop(Webshop::findOrFail($c->webshop_id), now());
            }
        });
    }

    /** @param list<int> $recipients
     * @param  array<string, mixed>  $data
     */
    public function capture(string $type, string $key, array $recipients, array $data = [], ?int $webshop = null): bool
    {
        $definition = NotificationCatalogue::EVENTS[$type] ?? throw new \LogicException('Unknown notification source.');
        if ($recipients === []) {
            return false;
        }

        // Never swallow outbox storage failure: the caller's transaction must roll back.
        return NotificationEvent::firstOrCreate(['event_key' => $key], ['event_type' => $type, 'recipient_type' => $definition[0],
            'webshop_id' => $webshop, 'payload' => ['recipients' => array_values(array_unique($recipients)), 'facts' => $data], 'available_at' => now()])->wasRecentlyCreated;
    }

    public function workspace(string $type, int $shop, string $key): void
    {
        $ids = WebshopMembership::where('webshop_id', $shop)->where('status', MembershipStatus::Active)->where('role', MembershipRole::Owner)
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $this->capture($type, $key, $ids, [], $shop);
    }

    public function administrators(string $type, string $key): void
    {
        $ids = User::whereHas('platformRoles', fn ($q) => $q->where('role', PlatformRole::SuperAdmin)->whereNull('revoked_at'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->capture($type, $key, $ids);
    }

    public function merchant(MerchantNotification $n): void
    {
        if ($n->created_at->lessThan(now()->subDays(app(NotificationPolicy::class)->retentionDays()))) {
            return;
        }
        $ids = WebshopMembership::where('webshop_id', $n->webshop_id)->where('status', MembershipStatus::Active)
            ->get()->filter(fn ($m) => $m->allows(WorkspacePermission::DashboardView))->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $type = match ($n->type) {
            'offer_expiring' => 'offer_expiring', 'connector_error' => 'integration_attention', default => 'merchant_attention'
        };
        $this->capture($type, 'merchant:'.$n->public_id, $ids, ['legacy_id' => $n->id, 'legacy_read_at' => $n->read_at?->toIso8601String()], $n->webshop_id);
    }

    public function ledger(WalletLedgerEntry $entry): void
    {
        $w = Wallet::findOrFail($entry->wallet_id);
        if ($w->owner_type !== 'user' || $w->kind !== WalletKind::ConsumerCredit) {
            return;
        }
        $type = match ($entry->type) {
            LedgerEntryType::Credit => $entry->amount > 0 && $entry->source_type === 'affiliate' ? 'cashback_credited' : null,
            LedgerEntryType::Adjustment, LedgerEntryType::Reversal => 'cashback_adjusted',
            default => null,
        };
        if ($type !== null) {
            $this->capture($type, 'ledger:'.$entry->public_id, [(int) $w->owner_id], ['amount_minor' => $entry->amount, 'unit' => $w->unit->value, 'entry_id' => $entry->id]);
        }
    }

    public function voucher(VoucherEvent $e): void
    {
        $type = match ($e->type) {
            'issued' => 'voucher_received', 'redeemed' => 'voucher_used', 'released' => 'voucher_cancelled', default => null
        };
        $voucher = $type === null || $e->voucher_id === null ? null : Voucher::find($e->voucher_id);
        if ($voucher?->consumer_user_id !== null) {
            $this->capture($type, 'voucher-event:'.$e->id, [$voucher->consumer_user_id], ['voucher_id' => $voucher->id]);
        }
    }

    public function audit(AuditEvent $e): void
    {
        if ($e->outcome !== AuditOutcome::Success && $e->action !== 'affiliate.sync_failed') {
            return;
        }
        if (in_array($e->action, ['workspace.approved', 'workspace.rejected'], true) && $e->resource_type === Webshop::class) {
            $this->workspace(str_replace('.', '_', $e->action), (int) $e->resource_id, 'audit:'.$e->id);
        } elseif ($e->action === 'ownership.review_submitted') {
            $this->administrators('workspace_review_required', 'audit:'.$e->id);
        } elseif ($e->action === 'affiliate.sync_failed') {
            $this->administrators('affiliate_import_failed', 'audit:'.$e->id);

        } elseif ($e->action === 'offer.status_changed' && ($e->metadata['to'] ?? null) === 'active' && $e->resource_type === Offer::class) {
            $offer = Offer::findOrFail($e->resource_id);
            $this->workspace('offer_published', $offer->webshop_id, 'audit:'.$e->id);
            $followers = DB::table('consumer_favorite_webshops')->where('webshop_id', $offer->webshop_id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            $this->capture('favorite_merchant_offer', 'favorite:'.$e->id, $followers, ['offer_id' => $offer->id]);
        }
    }
}
