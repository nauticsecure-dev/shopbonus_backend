<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Enums\LedgerEntryType;
use App\Enums\WalletKind;
use App\Enums\WalletUnit;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AffiliateTransaction;
use App\Models\MerchantCommissionConversion;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCheckout;
use App\Models\Wallet;
use App\Models\WalletCreditLot;
use App\Models\WalletLedgerEntry;
use App\Models\WalletPendingCredit;
use App\Models\Webshop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The consumer's own central wallet (Trello #19 "My Points", #10 §7).
 * Read-only: balances change only through WalletService. There is no
 * transfer, withdrawal or cash-out endpoint.
 *
 * Pending affiliate cashback is visible but never spendable.
 */
class WalletController extends Controller
{
    private const FILTERS = [
        'earned' => [LedgerEntryType::Credit],
        'spent' => [LedgerEntryType::Debit],
        'adjustments' => [LedgerEntryType::Adjustment, LedgerEntryType::Reversal, LedgerEntryType::Expire],
        'holds' => [LedgerEntryType::Reserve, LedgerEntryType::Release],
    ];

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => self::summary($request->user())]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filter' => ['sometimes', Rule::in(['all', 'pending', ...array_keys(self::FILTERS)])],
            'unit' => ['sometimes', Rule::enum(WalletUnit::class)],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $wallets = $this->wallets($request->user(), isset($data['unit']) ? WalletUnit::from($data['unit']) : null);
        $filter = $data['filter'] ?? 'all';

        if ($filter === 'pending') {
            $query = WalletPendingCredit::whereIn('wallet_id', $wallets->keys())->where('status', 'pending')->orderByDesc('id');
            $page = $query->paginate($data['per_page'] ?? 25)->withQueryString();

            return response()->json([
                'data' => collect($page->items())->map(fn (WalletPendingCredit $p) => self::pendingEntry($p, $wallets[$p->wallet_id]))->all(),
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            ]);
        }

        $query = WalletLedgerEntry::whereIn('wallet_id', $wallets->keys())->orderByDesc('id');
        if ($filter !== 'all') {
            $query->whereIn('type', self::FILTERS[$filter]);
        }
        if (! empty($data['q'])) {
            $q = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $data['q']).'%';
            $query->where(fn ($builder) => $builder->where('reason', 'like', $q)->orWhere('source_ref', 'like', $q)->orWhere('source_type', 'like', $q));
        }
        $page = $query->paginate($data['per_page'] ?? 25)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (WalletLedgerEntry $e) => self::entry($e, $wallets[$e->wallet_id]))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function transaction(Request $request, string $id): JsonResponse
    {
        $wallets = $this->wallets($request->user(), null);
        $entry = WalletLedgerEntry::where('public_id', $id)->whereIn('wallet_id', $wallets->keys())->first()
            ?? throw new ApiException('Transaction not found.', 404, 'not_found');

        $lot = WalletCreditLot::where('entry_id', $entry->id)->first();

        return response()->json(['data' => self::entry($entry, $wallets[$entry->wallet_id]) + [
            'remaining' => $lot?->remaining,
            'related_to' => $entry->related_entry_id === null ? null : WalletLedgerEntry::whereKey($entry->related_entry_id)->value('public_id'),
        ]]);
    }

    /**
     * Wallet summary per unit, for the wallet page, header and dashboard.
     * Pending cashback is reported separately and is never spendable.
     *
     * @return array<string, mixed>
     */
    public static function summary(User $user): array
    {
        $wallets = Wallet::where('owner_type', 'user')->where('owner_id', $user->id)->where('kind', WalletKind::ConsumerCredit)->get()->keyBy(fn (Wallet $w) => $w->unit->value);

        $balances = [];
        foreach (WalletUnit::cases() as $unit) {
            $w = $wallets[$unit->value] ?? null;
            $next = $w === null ? null : WalletCreditLot::where('wallet_id', $w->id)->where('remaining', '>', 0)->whereNotNull('expires_at')->orderBy('expires_at')->first();
            $balances[$unit->value] = [
                'unit' => $unit->value,
                'available' => $w->available ?? 0,
                'available_minor' => $w->available ?? 0,
                'reserved' => $w->reserved ?? 0,
                'total_earned' => $w->total_earned ?? 0,
                'total_earned_minor' => $w->total_earned ?? 0,
                'total_spent' => $w->total_spent ?? 0,
                'total_spent_minor' => $w->total_spent ?? 0,
                'total_expired' => $w->total_expired ?? 0,
                'next_expiry' => $next === null ? null : ['amount' => $next->remaining, 'at' => $next->expires_at?->toIso8601String()],
                'reserved_minor' => $w->reserved ?? 0,
                'pending' => $w->pending ?? 0,
                'pending_cashback_minor' => $w->pending ?? 0,
            ];
        }

        return [
            'balances' => $balances,
            'available_minor' => (int) $balances[WalletUnit::EurCredit->value]['available'],
            'reserved_minor' => (int) $balances[WalletUnit::EurCredit->value]['reserved'],
            'pending_cashback_minor' => (int) $balances[WalletUnit::EurCredit->value]['pending'],
            'total_earned_minor' => (int) $balances[WalletUnit::EurCredit->value]['total_earned'],
            'total_spent_minor' => (int) $balances[WalletUnit::EurCredit->value]['total_spent'],
            'currency' => 'EUR',
            'unit' => 'EUR_REDEMPTION_CREDIT',
            'updated_at' => now()->toIso8601String(),
            'withdrawable' => false,
            'notice' => 'ShopBonus credit and points are not money: they cannot be withdrawn or transferred.',
            'savings_goal' => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function pendingEntry(WalletPendingCredit $p, Wallet $w): array
    {
        return [
            'id' => $p->public_id,
            'type' => 'cashback_pending',
            'ledger_type' => 'pending',
            'amount' => $p->amount,
            'amount_minor' => $p->amount,
            'currency' => $w->unit === WalletUnit::EurCredit ? 'EUR' : null,
            'unit' => $w->unit->value,
            'status' => 'pending',
            'available_after' => null,
            'source' => ['type' => $p->source_type, 'ref' => $p->source_ref],
            'description' => $p->description,
            'title' => 'ShopBonus onderweg',
            'subtitle' => $p->description,
            'merchant_name' => null,
            'sign' => 'credit',
            'expires_at' => null,
            'created_at' => $p->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private static function entry(WalletLedgerEntry $e, Wallet $w): array
    {
        $display = self::displayFor($e);
        $signed = $e->amount;

        return [
            'id' => $e->public_id,
            'type' => match ($e->type) {
                LedgerEntryType::Credit => $e->source_type === 'direct_merchant' ? 'cashback_credit' : ($e->source_type === 'affiliate' ? 'cashback_credit' : $e->type->value),
                LedgerEntryType::Debit => $e->source_type === 'voucher_purchase' || $e->source_type === 'checkout' || str_contains((string) $e->source_type, 'voucher') ? 'voucher_debit' : $e->type->value,
                LedgerEntryType::Reversal => 'reversal',
                LedgerEntryType::Adjustment => 'adjustment',
                default => $e->type->value,
            },
            'ledger_type' => $e->type->value,
            'amount' => $signed,
            'amount_minor' => $signed,
            'currency' => $w->unit === WalletUnit::EurCredit ? 'EUR' : null,
            'unit' => $w->unit->value,
            'status' => 'credited',
            'available_after' => $e->available_after,
            'source' => ['type' => $e->source_type, 'ref' => $e->source_ref],
            'description' => $e->reason,
            'title' => $display['title'],
            'subtitle' => $display['subtitle'],
            'merchant_name' => $display['merchant_name'],
            'sign' => $signed >= 0 ? 'credit' : 'debit',
            'expires_at' => $e->expires_at?->toIso8601String(),
            'created_at' => $e->created_at->toIso8601String(),
        ];
    }

    /** @return array{title: string, subtitle: string|null, merchant_name: string|null} */
    private static function displayFor(WalletLedgerEntry $e): array
    {
        $merchant = null;
        $subtitle = null;

        if ($e->source_type === 'affiliate' && $e->source_ref) {
            $tx = AffiliateTransaction::where('public_id', $e->source_ref)->first();
            if ($tx?->webshop_id) {
                $merchant = Webshop::whereKey($tx->webshop_id)->value('name');
                $subtitle = $merchant ? $merchant.' aankoop' : null;
            }
        }

        if ($e->source_type === 'direct_merchant') {
            $conv = MerchantCommissionConversion::where('external_id', $e->source_ref)->orWhere('public_id', $e->source_ref)->first();
            if ($conv) {
                $merchant = Webshop::whereKey($conv->webshop_id)->value('name');
                $subtitle = $merchant ? $merchant.' aankoop' : null;
            } elseif (is_string($e->reason) && str_contains($e->reason, '—')) {
                $merchant = trim(explode('—', $e->reason, 2)[1] ?? '') ?: null;
                $subtitle = $merchant ? $merchant.' aankoop' : null;
            }
        }

        if (in_array($e->source_type, ['voucher_purchase', 'checkout', 'reservation'], true) || str_starts_with((string) $e->source_type, 'voucher')) {
            $checkout = VoucherCheckout::where('public_id', $e->source_ref)->first();
            $voucher = $checkout?->voucher_id ? Voucher::find($checkout->voucher_id) : null;
            if ($voucher) {
                $merchant = Webshop::whereKey($voucher->webshop_id)->value('name');
                $subtitle = $merchant;
            }
        }

        $title = match (true) {
            $e->type === LedgerEntryType::Credit && in_array($e->source_type, ['affiliate', 'direct_merchant'], true) => 'ShopBonus ontvangen',
            $e->type === LedgerEntryType::Debit => 'Voucher gebruikt',
            $e->type === LedgerEntryType::Reversal => 'Correctie (terugboeking)',
            $e->type === LedgerEntryType::Adjustment => 'Aanpassing',
            $e->type === LedgerEntryType::Expire => 'Tegoed verlopen',
            default => $e->reason ?: 'Walletmutatie',
        };

        return ['title' => $title, 'subtitle' => $subtitle, 'merchant_name' => $merchant];
    }

    /** @return Collection<int, Wallet> */
    private function wallets(User $user, ?WalletUnit $unit): Collection
    {
        return Wallet::where('owner_type', 'user')->where('owner_id', $user->id)->where('kind', WalletKind::ConsumerCredit)
            ->when($unit !== null, fn ($q) => $q->where('unit', $unit))->get()->keyBy('id');
    }
}
