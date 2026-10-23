<?php

namespace App\Services\Answer24;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\ContactOpportunity;
use App\Models\ContactOpportunityEvent;
use App\Models\WebshopOrder;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies verified Answer24 contact callbacks (Trello #1 "Answer24
 * exchange": accepted, rejected, cancelled, attempted, completed, failed,
 * with timestamp and remote reference) without creating duplicate tasks.
 *
 * - Every callback is recorded in contact_opportunity_events.
 * - The same remote event twice is a duplicate (same answer, nothing changes).
 * - A callback older than the last applied one is stale: recorded, not applied.
 * - Transitions follow TRANSITIONS; anything else is 409.
 */
class ContactResults
{
    public const STATUSES = ['accepted', 'rejected', 'cancelled', 'attempted', 'completed', 'failed'];

    /** @var array<string, list<string>> from → allowed to */
    private const TRANSITIONS = [
        'dispatched' => ['accepted', 'rejected', 'attempted', 'completed', 'failed', 'cancelled'],
        'accepted' => ['attempted', 'completed', 'failed', 'cancelled'],
        'attempted' => ['attempted', 'completed', 'failed', 'cancelled'],
        'cancellation_requested' => ['cancelled'],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{status: string, occurred_at: string, remote_reference?: string|null, reason?: string|null}  $data
     * @return array{opportunity: ContactOpportunity, outcome: string}
     */
    public function apply(ContactOpportunity $opportunity, array $data, string $remoteEventId): array
    {
        return DB::transaction(function () use ($opportunity, $data, $remoteEventId) {
            $opp = ContactOpportunity::whereKey($opportunity->id)->lockForUpdate()->firstOrFail();

            $previous = ContactOpportunityEvent::where('opportunity_id', $opp->id)->where('remote_event_id', $remoteEventId)->first();
            if ($previous !== null) {
                return ['opportunity' => $opp, 'outcome' => 'duplicate'];
            }

            $status = $data['status'];
            $occurredAt = CarbonImmutable::parse($data['occurred_at'])->utc();
            $reference = $data['remote_reference'] ?? null;

            if ($reference !== null && $opp->remote_reference !== null && $reference !== $opp->remote_reference) {
                throw ApiException::conflict('The remote reference does not match this contact request.', 'remote_reference_mismatch');
            }

            $lastApplied = ContactOpportunityEvent::where('opportunity_id', $opp->id)->where('outcome', 'applied')->max('occurred_at');
            $stale = $lastApplied !== null && $occurredAt->lessThan(CarbonImmutable::parse($lastApplied));

            if (! $stale && ! in_array($status, self::TRANSITIONS[$opp->status] ?? [], true)) {
                throw ApiException::conflict('This result cannot be applied in the current state.', 'invalid_transition', ['status' => $opp->status, 'result' => $status]);
            }

            $outcome = $stale ? 'stale' : 'applied';
            ContactOpportunityEvent::create([
                'opportunity_id' => $opp->id, 'status' => $status, 'outcome' => $outcome, 'remote_reference' => $reference,
                'remote_event_id' => $remoteEventId, 'reason' => isset($data['reason']) ? mb_substr($data['reason'], 0, 255) : null, 'occurred_at' => $occurredAt,
            ]);

            if (! $stale) {
                $opp->forceFill(['status' => $status, 'remote_reference' => $opp->remote_reference ?? $reference, 'last_status_at' => now()]
                    + ($status === 'cancelled' ? ['cancelled_at' => $opp->cancelled_at ?? now(), 'cancel_reason' => $opp->cancel_reason ?? 'answer24_cancelled'] : []))->save();

                // Order Sheet "Answer24 status" (Trello #1).
                WebshopOrder::whereIn('id', $opp->links()->where('status', 'active')->pluck('order_id'))
                    ->update(['contact_status' => $status, 'updated_at' => now()]);
            }

            $this->audit->record('answer24.contact_result', category: AuditCategory::Integration, resource: $opp, webshopId: $opp->webshop_id,
                metadata: ['result' => $status, 'outcome' => $outcome]);

            return ['opportunity' => $opp, 'outcome' => $outcome];
        });
    }
}
