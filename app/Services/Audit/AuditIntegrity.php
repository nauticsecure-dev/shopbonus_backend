<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use App\Models\AuditEventSeal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The head lock serializes chain appends inside the same transaction as the audit event. */
class AuditIntegrity
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** @param callable(): AuditEvent $create */
    public function record(callable $create): AuditEvent
    {
        return DB::transaction(function () use ($create) {
            $this->lockHead();
            $event = $create();
            $this->append($event->fresh());

            return $event;
        }, 3);
    }

    /** Historical rows are sealed as observed at migration time, not retrospectively authenticated. */
    public function backfill(): void
    {
        DB::transaction(function () {
            $this->lockHead();
            AuditEvent::whereNotIn('id', DB::table('audit_event_seals')->select('audit_event_id'))->orderBy('id')->each(fn (AuditEvent $event) => $this->append($event));
        });
    }

    private function lockHead(): void
    {
        // A no-op upsert takes an exclusive lock immediately. INSERT IGNORE followed
        // by FOR UPDATE upgrades shared duplicate-key locks and deadlocks concurrent writers.
        DB::table('audit_chain_heads')->upsert([['id' => 1, 'last_hash' => self::GENESIS, 'entry_count' => 0]], ['id'], ['id']);
        DB::table('audit_chain_heads')->where('id', 1)->lockForUpdate()->first();
    }

    private function append(AuditEvent $event): void
    {
        $head = DB::table('audit_chain_heads')->where('id', 1)->first();
        $eventHash = $this->eventHash($event);
        $hash = hash('sha256', $head->last_hash.'|'.$eventHash);
        AuditEventSeal::create(['public_id' => Str::lower((string) Str::ulid()), 'audit_event_id' => $event->id,
            'previous_hash' => $head->last_hash, 'event_hash' => $eventHash, 'chain_hash' => $hash]);
        DB::table('audit_chain_heads')->where('id', 1)->update(['last_hash' => $hash, 'entry_count' => $head->entry_count + 1]);
    }

    public function eventHash(AuditEvent $event): string
    {
        $data = $event->getRawOriginal();
        ksort($data);
        foreach ($data as $key => $value) {
            // Canonicalize driver-specific JSON whitespace/key order and integer representations.
            $data[$key] = $key === 'metadata' && $value !== null ? $this->canonical(json_decode($value, true, flags: JSON_THROW_ON_ERROR)) : ($value === null ? null : (string) $value);
        }

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonical($item), $value);
    }

    /** @return array{valid: bool, entries: int, head: string, error: string|null} */
    public function verify(): array
    {
        return DB::transaction(function () {
            $this->lockHead();
            $previous = self::GENESIS;
            $count = 0;
            $error = null;
            foreach (DB::table('audit_event_seals')->orderBy('id')->cursor() as $seal) {
                $event = AuditEvent::find($seal->audit_event_id);
                if ($event === null || $seal->previous_hash !== $previous || $seal->event_hash !== $this->eventHash($event)
                    || $seal->chain_hash !== hash('sha256', $previous.'|'.$seal->event_hash)) {
                    $error = 'Audit content or chain mismatch.';
                    break;
                }
                $previous = $seal->chain_hash;
                $count++;
            }
            $head = DB::table('audit_chain_heads')->where('id', 1)->first();
            if ($error === null && ($head->last_hash !== $previous || (int) $head->entry_count !== $count || AuditEvent::count() !== $count)) {
                $error = 'Audit entry count or head mismatch.';
            }

            return ['valid' => $error === null, 'entries' => $count, 'head' => $previous, 'error' => $error];
        });
    }
}
