<?php

namespace App\Services\Support;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Enums\WalletKind;
use App\Enums\WalletUnit;
use App\Enums\WorkspacePermission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Consumer\WalletController;
use App\Integrations\ConnectorRegistry;
use App\Models\BannerCampaign;
use App\Models\FaqItem;
use App\Models\Offer;
use App\Models\SupportKnowledgeVersion;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Models\Wallet;
use App\Models\WalletPendingCredit;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Audit\AuditLogger;
use App\Services\Banners\BannerMeasurement;
use App\Services\Checkout\CheckoutService;
use App\Services\Cms\RichText;
use App\Services\Finance\FeeCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/** Read-only tools. No HTTP dispatch, dynamic model access, financial writes or secrets. */
final class SupportTools
{
    /** Exact arguments; user, workspace, role and permission are never model arguments. */
    public const ARGUMENTS = [
        'get_my_wallet' => ['unit'],
        'get_my_order_reward_status' => ['order_reference'],
        'get_my_vouchers' => [],
        'get_voucher_quote' => ['offer_id'],
        'get_campaign_performance' => ['campaign_id'],
        'get_integration_health' => ['connection_id'],
        'get_my_voucher' => ['voucher_id'],
        'get_workspace_wallet' => [],
        'get_offer_cost' => ['offer_id'],
        'get_approved_knowledge' => ['document_id'],
    ];

    /** Frequent Dutch/English words that say nothing about the topic. */
    private const STOPWORDS = ['hoe', 'wat', 'waar', 'wanneer', 'waarom', 'kan', 'mijn', 'voor', 'with', 'what', 'where', 'when', 'does', 'have', 'this', 'that', 'from',
        'your', 'about', 'heb', 'hebben', 'niet', 'deze', 'dit', 'een', 'het', 'van', 'naar', 'over', 'zijn', 'wordt', 'worden', 'kunnen', 'mag', 'moet', 'graag', 'please'];

    public function __construct(private readonly AuditLogger $audit, private readonly FeeCalculator $fees) {}

    /**
     * Workspace must come from the authenticated conversation/route context, never model JSON.
     * Every call reloads identity/membership; queued work cannot rely on stale models.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function retrieve(User $actor, string $tool, array $arguments = [], ?string $workspace = null, string $locale = 'en'): ToolEvidence
    {
        $actor = User::find($actor->id) ?? $this->deny($actor, 401);
        if (! $actor->isActive() || $actor->email_verified_at === null || Context::has(AuditLogger::CONTEXT_REAL_ACTOR_ID)) {
            $this->deny($actor, 403);
        }
        if (! isset(self::ARGUMENTS[$tool]) || ! in_array($locale, ['en', 'nl'], true)
            || array_diff(array_keys($arguments), self::ARGUMENTS[$tool]) !== []
            || array_diff(self::ARGUMENTS[$tool], array_keys($arguments)) !== []
            || count(array_filter($arguments, fn ($v) => ! is_string($v) || strlen($v) > 64)) > 0) {
            throw new ApiException('Invalid support tool arguments.', 422, 'support_tool_invalid');
        }
        // Validate the conversation scope even when retrieving audience/public knowledge.
        $shop = $workspace === null ? null : $this->workspace($actor, $workspace);
        [$source, $facts] = match ($tool) {
            'get_my_order_reward_status' => $this->reward($actor, $arguments['order_reference'], $shop),
            'get_my_vouchers' => $this->vouchers($actor, $shop),
            'get_voucher_quote' => $this->quote($actor, $arguments['offer_id'], $shop),
            'get_campaign_performance' => $this->campaign($actor, $shop, $arguments['campaign_id']),
            'get_integration_health' => $this->integration($actor, $shop, $arguments['connection_id']),
            'get_my_wallet' => $this->wallet($actor, $arguments['unit'], $shop),
            'get_my_voucher' => $this->voucher($actor, $arguments['voucher_id'], $shop),
            'get_workspace_wallet' => $this->funding($actor, $shop),
            'get_offer_cost' => $this->offer($actor, $shop, $arguments['offer_id']),
            'get_approved_knowledge' => $this->knowledge($actor, $shop, $arguments['document_id'], $locale),
        };
        $this->audit->recordSafely('support.tool_retrieved', category: AuditCategory::Security, actorUserId: $actor->id,
            webshopId: $shop?->id, metadata: ['tool' => $tool], httpStatus: 200);

        return new ToolEvidence($actor->id, $tool, $arguments, $shop?->public_id, $locale, $source, $facts, CarbonImmutable::now());
    }

    /** @return array{string, array<string, mixed>} */
    private function wallet(User $actor, string $unit, ?Webshop $shop): array
    {
        if ($shop !== null || WalletUnit::tryFrom($unit) === null) {
            throw new ApiException('Invalid support tool arguments.', 422, 'support_tool_invalid');
        }
        // Reuse the consumer API's authoritative unit-specific summary without creating a wallet.
        $balance = WalletController::summary($actor)['balances'][$unit];

        return ['consumer_wallet:'.$unit, [
            'available_minor' => (int) $balance['available'],
            'reserved_minor' => (int) $balance['reserved'],
            'pending_minor' => (int) $balance['pending'],
            'spent_minor' => (int) $balance['total_spent'],
            'currency' => $unit === 'eur_credit' ? 'EUR' : null,
            'unit' => $unit === 'eur_credit' ? 'EUR_REDEMPTION_CREDIT' : 'POINTS',
        ]];
    }

    /** @return array{string, array<string, mixed>} */
    private function voucher(User $actor, string $id, ?Webshop $shop): array
    {
        if ($shop !== null) {
            $this->deny($actor, 403);
        }
        $v = Str::isUlid($id) ? Voucher::where('public_id', strtolower($id))->where('consumer_user_id', $actor->id)->first() : null;
        if ($v === null) {
            $this->deny($actor, 404);
        }
        $claim = VoucherClaim::where('voucher_id', $v->id)->where('claimed_by', $actor->id)->first();

        return ['voucher:'.$v->public_id, [
            'status' => $v->status->value,
            'source_type' => $v->origin->value,
            'claimed_at' => $claim?->claimed_at?->toIso8601String(),
            'issued_at' => $v->issued_at?->toIso8601String(),
            'expires_at' => $v->expires_at?->toIso8601String(),
            'redeemed_at' => $v->redeemed_at?->toIso8601String(),
        ]];
    }

    /** @return array{string, array<string, mixed>} */
    private function funding(User $actor, ?Webshop $shop): array
    {
        $shop = $this->permission($actor, $shop, WorkspacePermission::FinanceView);
        // Do not call MerchantFunding::wallet: that getter creates missing rows.
        $w = Wallet::where('owner_type', 'webshop')->where('owner_id', $shop->id)
            ->where('kind', WalletKind::MerchantFunding)->where('unit', WalletUnit::EurCredit)->first();

        return ['merchant_wallet:'.$shop->public_id, [
            'available_minor' => (int) ($w->available ?? 0),
            'reserved_minor' => (int) ($w->reserved ?? 0),
            'currency' => 'EUR',
            'unit' => 'EUR_REDEMPTION_CREDIT',
        ]];
    }

    /** @return array{string, array<string, mixed>} */
    private function offer(User $actor, ?Webshop $shop, string $id): array
    {
        $shop = $this->permission($actor, $shop, WorkspacePermission::OffersView);
        $offer = Str::isUlid($id) ? Offer::where('public_id', strtolower($id))->where('webshop_id', $shop->id)->with('currentVersion')->first() : null;
        if ($offer === null || $offer->currentVersion === null) {
            $this->deny($actor, 404);
        }
        $preview = $this->fees->offerPreview($offer->acquisition, $offer->currentVersion);
        // Keep non-direct/unknown percentage cost unavailable; never turn a missing cap into zero.
        $known = ($preview['applies'] ?? false) && ($preview['available'] ?? false);
        $rateBps = (int) ($preview['fee_rate_bps'] ?? $this->fees->feeRateBps());

        return ['offer:'.$offer->public_id.':v'.$offer->currentVersion->version, [
            'cost_available' => $known,
            'basis' => $preview['basis'] ?? null,
            'currency' => $preview['currency'] ?? null,
            'consumer_reward_minor' => $known ? $preview['per_voucher']['reward_minor'] : null,
            'shopbonus_fee_rate' => $known ? round($rateBps / 10000, 4) : null,
            'shopbonus_fee_minor' => $known ? $preview['per_voucher']['fee_minor'] : null,
            'merchant_total_economic_cost_minor' => $known ? $preview['per_voucher']['merchant_commitment_minor'] : null,
        ]];
    }

    /**
     * Deterministic FAQ lookup for a free-text question (no model involved): the
     * published article for this audience sharing the most significant words
     * with the question. A tie or no overlap returns null, so the chat asks.
     */
    public function matchKnowledge(bool $workspace, string $question, string $locale): ?string
    {
        $words = fn (string $text) => array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [],
            fn (string $w) => mb_strlen($w) >= 4 && ! in_array($w, self::STOPWORDS, true),
        )));
        $asked = $words($question);
        if ($asked === []) {
            return null;
        }
        $scores = FaqItem::where('published', true)->whereIn('audience', ['public', $workspace ? 'merchants' : 'consumers'])->get(['public_id', 'translations'])
            ->mapWithKeys(function (FaqItem $item) use ($words, $asked, $locale) {
                $t = $item->translations[$locale] ?? $item->translations['nl'] ?? null;
                if ($t === null) {
                    return [$item->public_id => 0];
                }
                // Question words count double: they name the topic.
                $q = $words((string) $t['question']);
                $a = $words(RichText::plainText($t['answer']));

                return [$item->public_id => 2 * count(array_intersect($asked, $q)) + count(array_intersect($asked, $a))];
            })->filter()->sortDesc();
        if ($scores->isEmpty() || ($scores->count() > 1 && $scores->values()[0] === $scores->values()[1])) {
            return null;
        }

        return (string) $scores->keys()->first();
    }

    /** @return array{string, array<string, mixed>} */
    private function knowledge(User $actor, ?Webshop $shop, string $id, string $locale): array
    {
        $audiences = ['public', $shop === null ? 'consumers' : 'merchants'];
        $item = Str::isUlid($id) ? FaqItem::where('public_id', strtolower($id))->where('published', true)->whereIn('audience', $audiences)->first() : null;
        if ($item === null) {
            $this->deny($actor, 404);
        }
        $translation = $item->translations[$locale] ?? $item->translations['nl'] ?? null;
        if ($translation === null) {
            throw new ApiException('Support evidence is unavailable.', 409, 'support_evidence_unavailable');
        }

        $content = RichText::plainText($translation['answer']);
        if ($content === '' || mb_strlen($content) > 4000) {
            throw new ApiException('Support evidence is unavailable.', 409, 'support_evidence_unavailable');
        }

        $version = SupportKnowledgeVersion::where('faq_id', $item->id)->where('revision', $item->revision)->first();
        $approvedAt = $version !== null && $version->origin !== 'backfill' && $version->recorded_by !== null && ($version->snapshot['published'] ?? false)
            ? $version->created_at->toIso8601String() : null;

        return ['faq:'.$item->public_id.':v'.$item->revision, [
            'document_id' => $item->public_id,
            'scope' => $item->audience,
            'version' => $item->revision,
            'locale' => isset($item->translations[$locale]) ? $locale : 'nl',
            'approved_at' => $approvedAt,
            'question' => $translation['question'],
            'content' => $content,
        ]];
    }

    /** @return array{string, array<string, mixed>} */
    private function reward(User $actor, string $reference, ?Webshop $shop): array
    {
        if ($shop !== null) {
            $this->deny($actor, 403);
        }
        // Ownership is proven by an existing issued/claimed voucher relation, not an email match.
        $order = WebshopOrder::whereIn('id', Voucher::where('consumer_user_id', $actor->id)->whereNotNull('source_order_id')->select('source_order_id'))
            ->where(fn ($q) => $q->where('public_id', strtolower($reference))->orWhere('reference', $reference))->limit(2)->get();
        if ($order->isEmpty()) {
            $pending = Str::isUlid($reference) ? WalletPendingCredit::where('public_id', strtolower($reference))
                ->whereIn('wallet_id', Wallet::where('owner_type', 'user')->where('owner_id', $actor->id)->where('kind', WalletKind::ConsumerCredit)->where('unit', WalletUnit::EurCredit)->select('id'))->first() : null;
            if ($pending !== null) {
                return ['wallet_reward:'.$pending->public_id, [
                    'order_reference' => $pending->public_id,
                    'payment_status' => null,
                    'reward_status' => $pending->status,
                    'reward_eligible' => null,
                    'reward_minor' => $pending->amount,
                    'currency' => 'EUR',
                    'source_type' => 'wallet_pending_credit',
                    'claimed_at' => null,
                    'release_date' => null,
                ]];
            }
        }
        if ($order->count() !== 1) {
            $this->deny($actor, 404);
        }
        $order = $order->sole();
        $v = Voucher::where('consumer_user_id', $actor->id)->where('source_order_id', $order->id)->orderByDesc('id')->firstOrFail();
        $claim = VoucherClaim::where('voucher_id', $v->id)->where('claimed_by', $actor->id)->first();
        $sourceId = 'order_reward:'.$order->public_id.':'.$v->public_id;

        return [$sourceId, [
            'order_reference' => $order->reference ?? $order->public_id,
            'payment_status' => $order->payment_status->value,
            'reward_status' => $v->status->value,
            'reward_eligible' => $order->eligibility_status === 'eligible',
            'reward_minor' => ($v->terms_snapshot['discount_type'] ?? null) === 'fixed' ? ($v->terms_snapshot['discount_value'] ?? null) : null,
            'currency' => $v->terms_snapshot['currency'] ?? null,
            'source_type' => $v->origin->value,
            'claimed_at' => $claim?->claimed_at?->toIso8601String(),
            'release_date' => null,
        ]];
    }

    /** @return array{string, array<string, mixed>} */
    private function vouchers(User $actor, ?Webshop $shop): array
    {
        if ($shop !== null) {
            $this->deny($actor, 403);
        }
        $rows = Voucher::where('consumer_user_id', $actor->id)->orderByDesc('id')->limit(50)->get();
        $items = $rows->map(function (Voucher $v) use ($actor) {
            $claim = VoucherClaim::where('voucher_id', $v->id)->where('claimed_by', $actor->id)->first();

            return [
                'status' => $v->status->value,
                'source_type' => $v->origin->value,
                'claimed_at' => $claim?->claimed_at?->toIso8601String(),
                'expires_at' => $v->expires_at?->toIso8601String(),
            ];
        })->values()->all();

        $q = Voucher::where('consumer_user_id', $actor->id);

        return ['owned_vouchers', [
            'items' => $items,
            'issued' => (clone $q)->where('status', 'issued')->count(),
            'redeemed' => (clone $q)->where('status', 'redeemed')->count(),
            'expired' => (clone $q)->where('status', 'expired')->count(),
            'cancelled' => (clone $q)->where('status', 'cancelled')->count(),
        ]];
    }

    /** @return array{string, array<string, mixed>} */
    private function quote(User $actor, string $id, ?Webshop $shop): array
    {
        if ($shop !== null) {
            $this->deny($actor, 403);
        }
        $offer = Str::isUlid($id) ? Offer::where('public_id', strtolower($id))->with('currentVersion')->first() : null;
        if ($offer === null || $offer->status->value !== 'active' || $offer->webshop()->firstOrFail()->status->value !== 'approved') {
            $this->deny($actor, 404);
        }
        $q = app(CheckoutService::class)->quote($actor, $offer);
        $sourceId = 'quote:'.$offer->public_id.':v'.$offer->currentVersion?->version;

        return [$sourceId, [
            'price_minor' => $q['price_minor'],
            'wallet_usable_minor' => $q['wallet_part_minor'],
            'mollie_due_minor' => $q['payment_part_minor'],
            'currency' => $q['currency'],
            'stock_available' => ! in_array('sold_out', $q['blockers'], true),
            'purchasable' => $q['blockers'] === [],
        ]];
    }

    /** @return array{string, array<string, mixed>} */
    private function campaign(User $actor, ?Webshop $shop, string $id): array
    {
        $shop = $this->permission($actor, $shop, WorkspacePermission::OrdersView);
        $this->permission($actor, $shop, WorkspacePermission::OffersView);
        $c = Str::isUlid($id) ? BannerCampaign::where('public_id', strtolower($id))->where('webshop_id', $shop->id)->first() : null;
        if ($c === null) {
            $this->deny($actor, 404);
        }
        $today = CarbonImmutable::now((string) config('shopbonus.business_timezone'))->startOfDay();
        $r = app(BannerMeasurement::class)->results($c, $today->subDays(29), $today, null);
        $sourceId = 'campaign:'.$c->public_id;

        return [$sourceId, [
            'impressions' => $r['viewable_impressions'],
            'clicks' => $r['cta_clicks'],
            // Existing measurement does not track checkout starts; never invent them as sales.
            'tracked_order_starts' => null,
            'attributed_paid_orders' => $r['attributed_orders'],
            'from' => $r['from'],
            'to' => $r['to'],
        ]];
    }

    /** @return array{string, array<string, mixed>} */
    private function integration(User $actor, ?Webshop $shop, string $id): array
    {
        $shop = $this->permission($actor, $shop, WorkspacePermission::IntegrationsView);
        $c = Str::isUlid($id) ? WebshopConnection::where('public_id', strtolower($id))->where('webshop_id', $shop->id)->first() : null;
        if ($c === null) {
            $this->deny($actor, 404);
        }
        $registry = app(ConnectorRegistry::class);
        $entry = $registry->entry($c->platform);
        $capabilities = [
            'order_ingestion_available' => $registry->isAvailable($c->platform),
            'verification' => $entry['verification'] ?? 'unverified',
            'method' => $c->method->value,
        ];
        $sourceId = 'integration:'.$c->public_id;

        return [$sourceId, [
            'platform' => $c->platform->value,
            'status' => $c->status->value,
            'capabilities' => $capabilities,
            'last_sync_at' => $c->last_event_at?->toIso8601String(),
            'last_error_codes' => $c->last_error_code === null ? [] : ['integration_error'],
        ]];
    }

    /** Revalidate conversation scope independently of any individual research tool. */
    public function authorize(User $actor, ?string $workspace): void
    {
        $fresh = User::find($actor->id);
        if ($fresh === null || ! $fresh->isActive() || $fresh->email_verified_at === null || Context::has(AuditLogger::CONTEXT_REAL_ACTOR_ID)) {
            $this->deny($actor, 403);
        }
        if ($workspace !== null) {
            $this->workspace($fresh, $workspace);
        }
    }

    private function workspace(User $actor, string $reference): Webshop
    {
        $shop = Str::isUlid($reference) ? Webshop::where('public_id', strtolower($reference))->first() : null;
        if ($shop === null || ! ($shop->memberships()->where('user_id', $actor->id)->first()?->isActive() ?? false)) {
            $this->deny($actor, 404);
        }
        if (! $shop->status->allowsMemberAccess()) {
            $this->deny($actor, 403);
        }

        return $shop;
    }

    private function permission(User $actor, ?Webshop $shop, WorkspacePermission $permission): Webshop
    {
        if ($shop === null || ! ($shop->memberships()->where('user_id', $actor->id)->first()?->allows($permission) ?? false)) {
            $this->deny($actor, 403);
        }

        return $shop;
    }

    private function deny(User $actor, int $status): never
    {
        $this->audit->recordSafely('support.tool_denied', outcome: AuditOutcome::Denied, category: AuditCategory::Security,
            actorUserId: $actor->id, httpStatus: $status);
        throw new ApiException('Support evidence is unavailable.', $status, $status === 404 ? 'not_found' : 'support_access_denied');
    }
}
