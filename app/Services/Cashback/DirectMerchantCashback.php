<?php

namespace App\Services\Cashback;

use App\Enums\AuditCategory;
use App\Enums\WalletUnit;
use App\Exceptions\ApiException;
use App\Models\MerchantCommissionAgreement;
use App\Models\MerchantCommissionConversion;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Models\Webshop;
use App\Services\Audit\AuditLogger;
use App\Services\Wallet\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Credits ShopBonus redemption credit from a verified direct-merchant
 * conversion. Never combines with affiliate network commissions.
 */
class DirectMerchantCashback
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{external_id: string, order_amount_minor: int, currency?: string, converted_at?: CarbonImmutable|null, commission_minor?: int|null}  $data
     */
    public function creditVerifiedConversion(Webshop $webshop, User $consumer, string $idempotencyKey, array $data): MerchantCommissionConversion
    {
        try {
            return DB::transaction(fn () => $this->apply($webshop, $consumer, $idempotencyKey, $data));
        } catch (UniqueConstraintViolationException) {
            $existing = MerchantCommissionConversion::where('webshop_id', $webshop->id)->where('external_id', $data['external_id'])->first()
                ?? MerchantCommissionConversion::where('idempotency_key', $idempotencyKey)->first();

            return $existing ?? throw new \RuntimeException('Direct merchant conversion race could not be resolved.');
        }
    }

    /**
     * @param  array{external_id: string, order_amount_minor: int, currency?: string, converted_at?: CarbonImmutable|null, commission_minor?: int|null}  $data
     */
    private function apply(Webshop $webshop, User $consumer, string $idempotencyKey, array $data): MerchantCommissionConversion
    {
        if ($existing = MerchantCommissionConversion::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first()) {
            return $existing;
        }
        if ($dup = MerchantCommissionConversion::where('webshop_id', $webshop->id)->where('external_id', $data['external_id'])->lockForUpdate()->first()) {
            return $dup;
        }

        $currency = strtoupper($data['currency'] ?? 'EUR');
        $at = $data['converted_at'] ?? CarbonImmutable::now();
        $agreement = MerchantCommissionAgreement::current($webshop->id, $at);

        if ($agreement === null || ! $agreement->cashback_approved) {
            return MerchantCommissionConversion::create([
                'webshop_id' => $webshop->id, 'user_id' => $consumer->id, 'agreement_id' => $agreement?->id,
                'external_id' => $data['external_id'], 'idempotency_key' => $idempotencyKey,
                'order_amount_minor' => $data['order_amount_minor'], 'commission_minor' => 0, 'consumer_credit_minor' => 0,
                'shopbonus_revenue_minor' => 0, 'currency' => $currency, 'status' => 'exception',
                'exception_reason' => 'no_approved_agreement', 'agreement_snapshot' => $agreement?->evidence() ?? [],
                'converted_at' => $at,
            ]);
        }

        if ($agreement->consumer_share_bps + $agreement->shopbonus_share_bps !== 10_000) {
            throw ApiException::conflict('Merchant commission shares must total 100%.', 'invalid_commission_split');
        }

        if ($currency !== 'EUR') {
            return MerchantCommissionConversion::create([
                'webshop_id' => $webshop->id, 'user_id' => $consumer->id, 'agreement_id' => $agreement->id,
                'external_id' => $data['external_id'], 'idempotency_key' => $idempotencyKey,
                'order_amount_minor' => $data['order_amount_minor'], 'commission_minor' => 0, 'consumer_credit_minor' => 0,
                'shopbonus_revenue_minor' => 0, 'currency' => $currency, 'status' => 'exception',
                'exception_reason' => 'unsupported_currency', 'agreement_snapshot' => $agreement->evidence(),
                'converted_at' => $at,
            ]);
        }

        $commission = array_key_exists('commission_minor', $data) && $data['commission_minor'] !== null
            ? (int) $data['commission_minor']
            : $agreement->commissionForOrder((int) $data['order_amount_minor']);

        if ($commission <= 0) {
            return MerchantCommissionConversion::create([
                'webshop_id' => $webshop->id, 'user_id' => $consumer->id, 'agreement_id' => $agreement->id,
                'external_id' => $data['external_id'], 'idempotency_key' => $idempotencyKey,
                'order_amount_minor' => $data['order_amount_minor'], 'commission_minor' => 0, 'consumer_credit_minor' => 0,
                'shopbonus_revenue_minor' => 0, 'currency' => $currency, 'status' => 'exception',
                'exception_reason' => 'incomplete_commission', 'agreement_snapshot' => $agreement->evidence(),
                'converted_at' => $at,
            ]);
        }

        $consumerCredit = $agreement->consumerCreditFor($commission);
        $platform = $commission - $consumerCredit;
        $wallet = $this->wallets->consumerWallet($consumer, WalletUnit::EurCredit);
        $entry = $this->wallets->credit($wallet, $consumerCredit, 'direct-merchant:'.$idempotencyKey, meta: [
            'source_type' => 'direct_merchant',
            'source_ref' => $data['external_id'],
            'reason' => 'Direct merchant cashback — '.$webshop->name,
        ]);

        $row = MerchantCommissionConversion::create([
            'webshop_id' => $webshop->id, 'user_id' => $consumer->id, 'agreement_id' => $agreement->id,
            'external_id' => $data['external_id'], 'idempotency_key' => $idempotencyKey,
            'order_amount_minor' => $data['order_amount_minor'], 'commission_minor' => $commission,
            'consumer_credit_minor' => $consumerCredit, 'shopbonus_revenue_minor' => $platform,
            'currency' => $currency, 'status' => 'credited', 'wallet_entry_id' => $entry->id,
            'agreement_snapshot' => $agreement->evidence() + [
                'merchant' => $webshop->name,
                'consumer_credit_minor' => $consumerCredit,
                'shopbonus_revenue_minor' => $platform,
                'status' => 'credited',
            ],
            'converted_at' => $at,
        ]);

        $this->audit->record('cashback.direct_merchant_credited', category: AuditCategory::Financial, resource: $row, actorUserId: $consumer->id, webshopId: $webshop->id,
            metadata: ['commission_minor' => $commission, 'consumer_credit_minor' => $consumerCredit, 'ledger' => $entry->public_id]);

        return $row;
    }

    public function reverse(MerchantCommissionConversion $conversion, string $idempotencyKey, ?int $actorUserId = null): MerchantCommissionConversion
    {
        return DB::transaction(function () use ($conversion, $idempotencyKey, $actorUserId) {
            $row = MerchantCommissionConversion::whereKey($conversion->id)->lockForUpdate()->firstOrFail();
            if ($row->status === 'reversed') {
                return $row;
            }
            if ($row->status !== 'credited' || $row->wallet_entry_id === null) {
                throw ApiException::conflict('Only credited conversions can be reversed.', 'conversion_not_reversible');
            }

            $entry = WalletLedgerEntry::findOrFail($row->wallet_entry_id);
            $this->wallets->reverse($entry, $idempotencyKey, 'direct_merchant_reversal', $actorUserId);
            $row->forceFill(['status' => 'reversed'])->save();
            $this->audit->record('cashback.direct_merchant_reversed', category: AuditCategory::Financial, resource: $row, actorUserId: $actorUserId);

            return $row;
        });
    }
}
