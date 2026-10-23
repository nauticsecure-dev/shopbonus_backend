<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Enums\LedgerEntryType;
use App\Enums\WalletKind;
use App\Enums\WalletUnit;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletCreditLot;
use App\Models\WalletLedgerEntry;
use App\Models\WalletPendingCredit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The consumer's own central wallet (Trello #19 "My Points", #10 §7).
 * Read-only: balances change only through WalletService. There is no
 * transfer, withdrawal or cash-out endpoint.
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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $wallets = $this->wallets($request->user(), isset($data['unit']) ? WalletUnit::from($data['unit']) : null);
        $filter = $data['filter'] ?? 'all';

        if ($filter === 'pending') {
            $page = WalletPendingCredit::whereIn('wallet_id', $wallets->keys())->where('status', 'pending')->orderByDesc('id')->paginate($data['per_page'] ?? 25)->withQueryString();

            return response()->json([
                'data' => collect($page->items())->map(fn (WalletPendingCredit $p) => [
                    'id' => $p->public_id, 'type' => 'pending_credit', 'status' => 'pending', 'amount' => $p->amount, 'unit' => $wallets[$p->wallet_id]->unit->value,
                    'description' => $p->description, 'source' => ['type' => $p->source_type, 'ref' => $p->source_ref], 'spendable' => false, 'created_at' => $p->created_at->toIso8601String(),
                ])->all(),
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            ]);
        }

        $query = WalletLedgerEntry::whereIn('wallet_id', $wallets->keys())->orderByDesc('id');
        if ($filter !== 'all') {
            $query->whereIn('type', self::FILTERS[$filter]);
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
                'pending' => $w->pending ?? 0,
                'reserved' => $w->reserved ?? 0,
                'total_earned' => $w->total_earned ?? 0,
                'total_spent' => $w->total_spent ?? 0,
                'total_expired' => $w->total_expired ?? 0,
                'next_expiry' => $next === null ? null : ['amount' => $next->remaining, 'at' => $next->expires_at?->toIso8601String()],
            ];
        }

        return ['balances' => $balances, 'withdrawable' => false, 'notice' => 'ShopBonus credit and points are not money: they cannot be withdrawn or transferred.'];
    }

    /** @return array<string, mixed> */
    private static function entry(WalletLedgerEntry $e, Wallet $w): array
    {
        return [
            'id' => $e->public_id,
            'type' => $e->type->value,
            'amount' => $e->amount,
            'unit' => $w->unit->value,
            'available_after' => $e->available_after,
            'source' => ['type' => $e->source_type, 'ref' => $e->source_ref],
            'description' => $e->reason,
            'expires_at' => $e->expires_at?->toIso8601String(),
            'created_at' => $e->created_at->toIso8601String(),
        ];
    }

    /** @return Collection<int, Wallet> */
    private function wallets(User $user, ?WalletUnit $unit): Collection
    {
        return Wallet::where('owner_type', 'user')->where('owner_id', $user->id)->where('kind', WalletKind::ConsumerCredit)
            ->when($unit !== null, fn ($q) => $q->where('unit', $unit))->get()->keyBy('id');
    }
}
