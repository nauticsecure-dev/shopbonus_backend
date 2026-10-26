<?php

namespace App\Services\Support;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Enums\WalletKind;
use App\Enums\WalletUnit;
use App\Enums\WorkspacePermission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Consumer\WalletController;
use App\Models\FaqItem;
use App\Models\Offer;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Wallet;
use App\Models\Webshop;
use App\Services\Audit\AuditLogger;
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
        'get_my_voucher' => ['voucher_id'],
        'get_workspace_wallet' => [],
        'get_offer_cost' => ['offer_id'],
        'get_approved_knowledge' => ['document_id'],
    ];

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

    /** @return array{string, array<string, int|string|bool|null>} */
    private function wallet(User $actor, string $unit, ?Webshop $shop): array
    {
        if ($shop !== null || WalletUnit::tryFrom($unit) === null) {
            throw new ApiException('Invalid support tool arguments.', 422, 'support_tool_invalid');
        }
        // Reuse the consumer API's authoritative unit-specific summary without creating a wallet.
        $balance = WalletController::summary($actor)['balances'][$unit];

        return ['consumer_wallet:'.$unit, [
            'available' => $balance['available'], 'reserved' => $balance['reserved'], 'pending' => $balance['pending'],
            'spent' => $balance['total_spent'], 'unit' => $unit, 'currency' => $unit === 'eur_credit' ? 'EUR' : null,
        ]];
    }

    /** @return array{string, array<string, int|string|bool|null>} */
    private function voucher(User $actor, string $id, ?Webshop $shop): array
    {
        if ($shop !== null) {
            $this->deny($actor, 403);
        }
        $v = Str::isUlid($id) ? Voucher::where('public_id', strtolower($id))->where('consumer_user_id', $actor->id)->first() : null;
        if ($v === null) {
            $this->deny($actor, 404);
        }

        return ['voucher:'.$v->public_id, ['status' => $v->status->value, 'source_type' => $v->origin->value,
            'issued_at' => $v->issued_at?->toIso8601String(), 'expires_at' => $v->expires_at?->toIso8601String(),
            'redeemed_at' => $v->redeemed_at?->toIso8601String()]];
    }

    /** @return array{string, array<string, int|string|bool|null>} */
    private function funding(User $actor, ?Webshop $shop): array
    {
        $shop = $this->permission($actor, $shop, WorkspacePermission::FinanceView);
        // Do not call MerchantFunding::wallet: that getter creates missing rows.
        $w = Wallet::where('owner_type', 'webshop')->where('owner_id', $shop->id)
            ->where('kind', WalletKind::MerchantFunding)->where('unit', WalletUnit::EurCredit)->first();

        return ['merchant_wallet:'.$shop->public_id, ['available' => $w->available ?? 0, 'reserved' => $w->reserved ?? 0,
            'unit' => 'eur_credit', 'currency' => 'EUR']];
    }

    /** @return array{string, array<string, int|string|bool|null>} */
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

        return ['offer:'.$offer->public_id.':v'.$offer->currentVersion->version, [
            'available' => $known, 'basis' => $preview['basis'] ?? null, 'currency' => $preview['currency'] ?? null,
            'consumer_reward_minor' => $known ? $preview['per_voucher']['reward_minor'] : null,
            'shopbonus_fee_minor' => $known ? $preview['per_voucher']['fee_minor'] : null,
            'merchant_total_economic_cost_minor' => $known ? $preview['per_voucher']['merchant_commitment_minor'] : null,
        ]];
    }

    /** @return array{string, array<string, int|string|bool|null>} */
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

        return ['faq:'.$item->public_id.':v'.$item->revision, ['scope' => $item->audience, 'version' => $item->revision,
            'locale' => isset($item->translations[$locale]) ? $locale : 'nl',
            'question' => $translation['question'], 'content' => $content]];
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
