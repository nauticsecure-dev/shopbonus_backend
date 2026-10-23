<?php

namespace App\Services\Wallet;

use App\Enums\AuditCategory;
use App\Enums\LedgerEntryType;
use App\Enums\WalletKind;
use App\Enums\WalletUnit;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletCreditLot;
use App\Models\WalletLedgerEntry;
use App\Models\WalletPendingCredit;
use App\Models\WalletReconciliationFlag;
use App\Models\WalletReservation;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of wallets (Trello #19 "Balance integrity", #23).
 *
 * Every operation runs in a transaction holding the wallet row lock, checks
 * its idempotency key first (a retry returns the original result), writes
 * exactly one append-only ledger entry per balance change and updates the
 * cached balances in the same transaction. Balances can never go below
 * zero: the service refuses, and the unsigned columns refuse too.
 *
 * Credits are lots with an expiry; reserving/spending takes the
 * oldest-expiring lot first. Pending credit is tracked apart and is never
 * spendable. There is deliberately no transfer or cash-out operation.
 */
class WalletService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function consumerWallet(User $user, WalletUnit $unit = WalletUnit::EurCredit): Wallet
    {
        return $this->walletFor('user', $user->id, WalletKind::ConsumerCredit, $unit);
    }

    public function walletFor(string $ownerType, int $ownerId, WalletKind $kind, WalletUnit $unit): Wallet
    {
        $find = fn () => Wallet::where('owner_type', $ownerType)->where('owner_id', $ownerId)->where('kind', $kind)->where('unit', $unit)->first();

        try {
            return $find() ?? Wallet::create(['owner_type' => $ownerType, 'owner_id' => $ownerId, 'kind' => $kind, 'unit' => $unit]);
        } catch (UniqueConstraintViolationException) {
            return $find() ?? throw new \RuntimeException('Wallet creation race could not be resolved.');
        }
    }

    // ------------------------------------------------------------------ credits

    /**
     * @param  array{source_type?: string, source_ref?: string, reason?: string, origin?: string, actor_user_id?: int|null}  $meta
     */
    public function credit(Wallet $wallet, int $amount, string $key, ?CarbonImmutable $expiresAt = null, array $meta = [], bool $neverExpires = false): WalletLedgerEntry
    {
        $this->assertPositive($amount);
        $expiresAt ??= $neverExpires ? null : CarbonImmutable::now()->addMonths((int) config('shopbonus.wallet.credit_validity_months', 12));

        return $this->locked($wallet, $key, function (Wallet $w) use ($amount, $key, $expiresAt, $meta) {
            return $this->postCredit($w, LedgerEntryType::Credit, $amount, $key, $expiresAt, $meta);
        });
    }

    /**
     * @param  array{source_type?: string, source_ref?: string, description?: string}  $meta
     */
    public function addPending(Wallet $wallet, int $amount, string $key, array $meta = []): WalletPendingCredit
    {
        $this->assertPositive($amount);

        return DB::transaction(function () use ($wallet, $amount, $key, $meta) {
            $w = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            if (($existing = WalletPendingCredit::where('wallet_id', $w->id)->where('idempotency_key', $key)->first()) !== null) {
                return $existing;
            }

            $w->forceFill(['pending' => $w->pending + $amount])->save();

            return WalletPendingCredit::create(['wallet_id' => $w->id, 'amount' => $amount, 'status' => 'pending', 'idempotency_key' => $key,
                'source_type' => $meta['source_type'] ?? null, 'source_ref' => $meta['source_ref'] ?? null, 'description' => $meta['description'] ?? null]);
        });
    }

    /**
     * Turns a pending credit into exactly one posted credit.
     *
     * @param  array{reason?: string, origin?: string, actor_user_id?: int|null}  $meta
     */
    public function confirmPending(WalletPendingCredit $pending, ?CarbonImmutable $expiresAt = null, array $meta = []): WalletLedgerEntry
    {
        return DB::transaction(function () use ($pending, $expiresAt, $meta) {
            $w = Wallet::whereKey($pending->wallet_id)->lockForUpdate()->firstOrFail();
            $p = WalletPendingCredit::whereKey($pending->id)->lockForUpdate()->firstOrFail();

            if ($p->status === 'confirmed') {
                return WalletLedgerEntry::findOrFail($p->credit_entry_id);
            }
            if ($p->status !== 'pending') {
                throw ApiException::conflict('This pending credit was cancelled.', 'pending_credit_cancelled');
            }

            $expiresAt ??= CarbonImmutable::now()->addMonths((int) config('shopbonus.wallet.credit_validity_months', 12));
            $w->forceFill(['pending' => $w->pending - $p->amount])->save();
            $entry = $this->postCredit($w, LedgerEntryType::Credit, $p->amount, 'pending:'.$p->public_id, $expiresAt,
                ['source_type' => $p->source_type, 'source_ref' => $p->source_ref] + $meta);
            $p->forceFill(['status' => 'confirmed', 'credit_entry_id' => $entry->id, 'settled_at' => now()])->save();

            return $entry;
        });
    }

    public function cancelPending(WalletPendingCredit $pending): WalletPendingCredit
    {
        return DB::transaction(function () use ($pending) {
            $w = Wallet::whereKey($pending->wallet_id)->lockForUpdate()->firstOrFail();
            $p = WalletPendingCredit::whereKey($pending->id)->lockForUpdate()->firstOrFail();

            if ($p->status === 'pending') {
                $w->forceFill(['pending' => $w->pending - $p->amount])->save();
                $p->forceFill(['status' => 'cancelled', 'settled_at' => now()])->save();
            }

            return $p;
        });
    }

    // ------------------------------------------------------------------ spending

    /**
     * Holds value for a checkout. Idempotent per key.
     *
     * @param  array{source_type?: string, source_ref?: string}  $meta
     */
    public function reserve(Wallet $wallet, int $amount, string $key, int $ttlMinutes = 30, array $meta = []): WalletReservation
    {
        $this->assertPositive($amount);

        return DB::transaction(function () use ($wallet, $amount, $key, $ttlMinutes, $meta) {
            $w = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            if (($existing = WalletReservation::where('wallet_id', $w->id)->where('idempotency_key', $key)->first()) !== null) {
                return $existing;
            }

            $this->assertAvailable($w, $amount);
            $allocations = $this->takeFromLots($w, $amount);
            $reservation = WalletReservation::create(['wallet_id' => $w->id, 'amount' => $amount, 'status' => 'active', 'idempotency_key' => $key,
                'allocations' => $allocations, 'expires_at' => now()->addMinutes($ttlMinutes),
                'source_type' => $meta['source_type'] ?? null, 'source_ref' => $meta['source_ref'] ?? null]);
            $this->post($w, LedgerEntryType::Reserve, -$amount, $amount, 'reserve:'.$reservation->public_id,
                ['source_type' => $meta['source_type'] ?? 'reservation', 'source_ref' => $meta['source_ref'] ?? $reservation->public_id]);

            return $reservation;
        });
    }

    /** Debits a reservation (e.g. after verified payment). Idempotent. */
    public function capture(WalletReservation $reservation): WalletLedgerEntry
    {
        return DB::transaction(function () use ($reservation) {
            $w = Wallet::whereKey($reservation->wallet_id)->lockForUpdate()->firstOrFail();
            $r = WalletReservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $key = 'capture:'.$r->public_id;

            if ($r->status === 'captured') {
                return WalletLedgerEntry::where('wallet_id', $w->id)->where('idempotency_key', $key)->firstOrFail();
            }
            if ($r->status !== 'active') {
                throw ApiException::conflict('This reservation was released.', 'reservation_released');
            }

            $entry = $this->post($w, LedgerEntryType::Debit, 0, -$r->amount, $key, ['source_type' => $r->source_type ?? 'reservation', 'source_ref' => $r->source_ref ?? $r->public_id]);
            $w->forceFill(['total_spent' => $w->total_spent + $r->amount])->save();
            $r->forceFill(['status' => 'captured', 'settled_at' => now()])->save();

            return $entry;
        });
    }

    /** Gives reserved value back (failed payment, timeout). Idempotent. */
    public function release(WalletReservation $reservation, string $reason = 'released'): WalletReservation
    {
        return DB::transaction(function () use ($reservation, $reason) {
            $w = Wallet::whereKey($reservation->wallet_id)->lockForUpdate()->firstOrFail();
            $r = WalletReservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($r->status === 'released') {
                return $r;
            }
            if ($r->status === 'captured') {
                throw ApiException::conflict('This reservation was already captured.', 'reservation_captured');
            }

            foreach ($r->allocations as $allocation) {
                WalletCreditLot::whereKey($allocation['lot_id'])->increment('remaining', $allocation['amount']);
            }
            $this->post($w, LedgerEntryType::Release, $r->amount, -$r->amount, 'release:'.$r->public_id,
                ['source_type' => $r->source_type ?? 'reservation', 'source_ref' => $r->source_ref ?? $r->public_id, 'reason' => $reason]);
            $r->forceFill(['status' => 'released', 'settled_at' => now()])->save();

            return $r;
        });
    }

    /**
     * Immediate spend (reserve + capture atomically). Idempotent per key.
     *
     * @param  array{source_type?: string, source_ref?: string}  $meta
     */
    public function debit(Wallet $wallet, int $amount, string $key, array $meta = []): WalletLedgerEntry
    {
        return DB::transaction(fn () => $this->capture($this->reserve($wallet, $amount, 'debit:'.$key, 1, $meta)));
    }

    // ------------------------------------------------------------------ corrections

    /**
     * Compensating correction by an authorised actor. A negative adjustment
     * can never take more than is available.
     */
    public function adjust(Wallet $wallet, int $amount, string $key, string $reason, ?int $actorUserId, ?CarbonImmutable $expiresAt = null): WalletLedgerEntry
    {
        if ($amount === 0 || trim($reason) === '') {
            throw new ApiException('An adjustment needs a non-zero amount and a reason.', 422, 'invalid_adjustment');
        }

        return $this->locked($wallet, $key, function (Wallet $w) use ($amount, $key, $reason, $actorUserId, $expiresAt) {
            $meta = ['reason' => $reason, 'origin' => 'admin', 'actor_user_id' => $actorUserId, 'source_type' => 'adjustment'];

            if ($amount > 0) {
                $entry = $this->postCredit($w, LedgerEntryType::Adjustment, $amount, $key,
                    $expiresAt ?? CarbonImmutable::now()->addMonths((int) config('shopbonus.wallet.credit_validity_months', 12)), $meta, countAsEarned: false);
            } else {
                $this->assertAvailable($w, -$amount);
                $this->takeFromLots($w, -$amount);
                $entry = $this->post($w, LedgerEntryType::Adjustment, $amount, 0, $key, $meta);
            }

            $this->audit->record('wallet.adjusted', category: AuditCategory::Financial, resource: $w, actorUserId: $actorUserId,
                metadata: ['amount' => $amount, 'unit' => $w->unit->value, 'entry' => $entry->public_id]);

            return $entry;
        });
    }

    /**
     * Reverses a credit, but only its still-unspent part: a reversal never
     * reclaims value already spent and never makes a balance negative. The
     * part that could not be reversed is flagged for controlled
     * reconciliation (Trello #19 "Reversed after credit").
     *
     * @return array{entry: WalletLedgerEntry|null, unrecovered: int}
     */
    public function reverse(WalletLedgerEntry $credit, string $key, string $reason, ?int $actorUserId = null): array
    {
        return DB::transaction(function () use ($credit, $key, $reason, $actorUserId) {
            $w = Wallet::whereKey($credit->wallet_id)->lockForUpdate()->firstOrFail();
            if (($existing = WalletLedgerEntry::where('wallet_id', $w->id)->where('idempotency_key', $key)->first()) !== null) {
                return ['entry' => $existing, 'unrecovered' => (int) (WalletReconciliationFlag::where('wallet_id', $w->id)->where('details->key', $key)->value('amount') ?? 0)];
            }

            $lot = WalletCreditLot::where('entry_id', $credit->id)->lockForUpdate()->first();
            $take = min($lot->remaining ?? 0, $credit->amount);
            $entry = null;

            if ($take > 0) {
                $lot?->forceFill(['remaining' => $lot->remaining - $take])->save();
                $entry = $this->post($w, LedgerEntryType::Reversal, -$take, 0, $key,
                    ['reason' => $reason, 'origin' => $actorUserId === null ? 'system' : 'admin', 'actor_user_id' => $actorUserId, 'source_type' => 'reversal', 'source_ref' => $credit->public_id], related: $credit->id);
            }

            $unrecovered = $credit->amount - $take;
            if ($unrecovered > 0) {
                WalletReconciliationFlag::create(['wallet_id' => $w->id, 'reason' => 'reversal_exceeds_unspent', 'amount' => $unrecovered,
                    'details' => ['credit' => $credit->public_id, 'key' => $key, 'reason' => $reason]]);
            }

            $this->audit->record('wallet.reversed', category: AuditCategory::Financial, resource: $w, actorUserId: $actorUserId,
                metadata: ['credit' => $credit->public_id, 'reversed' => $take, 'unrecovered' => $unrecovered]);

            return ['entry' => $entry, 'unrecovered' => $unrecovered];
        });
    }

    // ------------------------------------------------------------------ housekeeping

    /**
     * Expires lots past their date and releases reservations past their
     * hold time. Returns [expired lots, released reservations].
     *
     * @return array{0: int, 1: int}
     */
    public function expire(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $lots = 0;
        $reservations = 0;

        WalletReservation::where('status', 'active')->where('expires_at', '<=', $now)->orderBy('id')->each(function (WalletReservation $r) use (&$reservations) {
            if ($this->release($r, 'reservation_expired')->status === 'released') {
                $reservations++;
            }
        });

        WalletCreditLot::where('remaining', '>', 0)->whereNotNull('expires_at')->where('expires_at', '<=', $now)->orderBy('id')->each(function (WalletCreditLot $lot) use (&$lots) {
            DB::transaction(function () use ($lot, &$lots) {
                $w = Wallet::whereKey($lot->wallet_id)->lockForUpdate()->firstOrFail();
                $l = WalletCreditLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
                if ($l->remaining === 0) {
                    return;
                }
                $amount = $l->remaining;
                // A lot can expire again after a reservation on it is released: number each expiry.
                $nth = WalletLedgerEntry::where('wallet_id', $w->id)->where('type', LedgerEntryType::Expire)->where('related_entry_id', $l->entry_id)->count() + 1;
                $l->forceFill(['remaining' => 0])->save();
                $this->post($w, LedgerEntryType::Expire, -$amount, 0, 'expire:lot:'.$l->id.':'.$nth, ['source_type' => 'expiry', 'reason' => 'credit_expired'], related: $l->entry_id);
                $w->forceFill(['total_expired' => $w->total_expired + $amount])->save();
                $lots++;
            });
        });

        return [$lots, $reservations];
    }

    /**
     * Proves the cached balances against the ledger and lots. Returns the
     * IDs of wallets that do not match (each also flagged).
     *
     * @return list<int>
     */
    public function reconcile(): array
    {
        $bad = [];

        Wallet::orderBy('id')->each(function (Wallet $w) use (&$bad) {
            $sums = WalletLedgerEntry::where('wallet_id', $w->id)->selectRaw('COALESCE(SUM(available_delta),0) a, COALESCE(SUM(reserved_delta),0) r')->first();
            $lots = (int) WalletCreditLot::where('wallet_id', $w->id)->sum('remaining');
            $pending = (int) WalletPendingCredit::where('wallet_id', $w->id)->where('status', 'pending')->sum('amount');
            $reserved = (int) WalletReservation::where('wallet_id', $w->id)->where('status', 'active')->sum('amount');

            $checks = ['available' => [(int) $sums->getAttribute('a'), $w->available], 'reserved' => [(int) $sums->getAttribute('r'), $w->reserved],
                'lots' => [$lots, $w->available], 'pending' => [$pending, $w->pending], 'active_reservations' => [$reserved, $w->reserved]];
            $mismatch = array_filter($checks, fn ($pair) => $pair[0] !== $pair[1]);

            if ($mismatch !== []) {
                $bad[] = $w->id;
                WalletReconciliationFlag::create(['wallet_id' => $w->id, 'reason' => 'balance_mismatch', 'details' => $mismatch]);
                report(new \RuntimeException("Wallet {$w->public_id} does not reconcile: ".json_encode($mismatch)));
            }
        });

        return $bad;
    }

    // ------------------------------------------------------------------ internals

    /**
     * Runs $fn under the wallet lock unless an entry with $key already exists.
     *
     * @param  callable(Wallet): WalletLedgerEntry  $fn
     */
    private function locked(Wallet $wallet, string $key, callable $fn): WalletLedgerEntry
    {
        return DB::transaction(function () use ($wallet, $key, $fn) {
            $w = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();

            return WalletLedgerEntry::where('wallet_id', $w->id)->where('idempotency_key', $key)->first() ?? $fn($w);
        });
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function postCredit(Wallet $w, LedgerEntryType $type, int $amount, string $key, ?CarbonImmutable $expiresAt, array $meta, bool $countAsEarned = true): WalletLedgerEntry
    {
        $entry = $this->post($w, $type, $amount, 0, $key, $meta, expiresAt: $expiresAt);
        WalletCreditLot::create(['wallet_id' => $w->id, 'entry_id' => $entry->id, 'amount' => $amount, 'remaining' => $amount, 'expires_at' => $expiresAt]);
        if ($countAsEarned) {
            $w->forceFill(['total_earned' => $w->total_earned + $amount])->save();
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function post(Wallet $w, LedgerEntryType $type, int $availableDelta, int $reservedDelta, string $key, array $meta, ?CarbonImmutable $expiresAt = null, ?int $related = null): WalletLedgerEntry
    {
        $available = $w->available + $availableDelta;
        $reserved = $w->reserved + $reservedDelta;

        if ($available < 0 || $reserved < 0) {
            throw ApiException::conflict('Not enough wallet balance.', 'insufficient_funds');
        }

        $entry = WalletLedgerEntry::create([
            'wallet_id' => $w->id, 'type' => $type, 'amount' => $availableDelta + $reservedDelta,
            'available_delta' => $availableDelta, 'reserved_delta' => $reservedDelta, 'available_after' => $available, 'reserved_after' => $reserved,
            'source_type' => $meta['source_type'] ?? null, 'source_ref' => $meta['source_ref'] ?? null, 'reason' => $meta['reason'] ?? null,
            'origin' => $meta['origin'] ?? 'system', 'actor_user_id' => $meta['actor_user_id'] ?? null,
            'idempotency_key' => $key, 'related_entry_id' => $related, 'expires_at' => $expiresAt,
        ]);
        $w->forceFill(['available' => $available, 'reserved' => $reserved])->save();

        return $entry;
    }

    /**
     * Takes $amount from the lots, oldest expiry first (no expiry last).
     *
     * @return list<array{lot_id: int, amount: int}>
     */
    private function takeFromLots(Wallet $w, int $amount): array
    {
        $allocations = [];
        $lots = WalletCreditLot::where('wallet_id', $w->id)->where('remaining', '>', 0)
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get();

        foreach ($lots as $lot) {
            if ($amount === 0) {
                break;
            }
            $take = min($lot->remaining, $amount);
            $lot->forceFill(['remaining' => $lot->remaining - $take])->save();
            $allocations[] = ['lot_id' => $lot->id, 'amount' => $take];
            $amount -= $take;
        }

        if ($amount > 0) {
            throw new \RuntimeException("Wallet {$w->public_id}: lots do not cover the available balance.");
        }

        return $allocations;
    }

    private function assertAvailable(Wallet $w, int $amount): void
    {
        if ($w->available < $amount) {
            throw ApiException::conflict('Not enough wallet balance.', 'insufficient_funds', ['available' => $w->available, 'required' => $amount, 'unit' => $w->unit->value]);
        }
    }

    private function assertPositive(int $amount): void
    {
        if ($amount <= 0) {
            throw new ApiException('Amounts must be positive whole numbers in the wallet unit.', 422, 'invalid_amount');
        }
    }
}
