<?php

namespace App\Services\Answer24;

use App\Models\ContactOpportunity;
use App\Models\OutboxMessage;
use App\Models\WebshopOrder;
use Illuminate\Support\Facades\DB;

/**
 * Delivers pending outbox messages to Answer24. Does nothing while
 * ANSWER24_DISPATCH_ENABLED is false (the default).
 *
 * - Each message is claimed with a conditional update (pending → sending),
 *   so two workers never send the same message.
 * - A contact request is re-checked right before sending: only a still
 *   `ready` opportunity is sent, with only its still-active orders.
 * - Dispatch/cancellation race: if the opportunity was cancelled while the
 *   request was in flight, a cancellation is queued immediately after.
 * - Failures back off exponentially; permanent errors or too many attempts
 *   end in `failed`. A message stuck in `sending` is reclaimed.
 */
class OutboxDispatcher
{
    private const STUCK_MINUTES = 10;

    public function __construct(private readonly Answer24Client $client, private readonly Outbox $outbox) {}

    /**
     * @return array{sent: int, failed: int, retried: int, skipped: int}
     */
    public function dispatch(int $limit = 50): array
    {
        $totals = ['sent' => 0, 'failed' => 0, 'retried' => 0, 'skipped' => 0];

        if (! config('shopbonus.answer24.dispatch_enabled')) {
            return $totals;
        }

        OutboxMessage::where('status', 'sending')->where('updated_at', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->update(['status' => 'pending', 'updated_at' => now()]);

        $ids = OutboxMessage::where('target', 'answer24')->where('status', 'pending')->where('next_attempt_at', '<=', now())
            ->orderBy('id')->limit($limit)->pluck('id');

        foreach ($ids as $id) {
            if (OutboxMessage::whereKey($id)->where('status', 'pending')->update(['status' => 'sending', 'updated_at' => now()]) !== 1) {
                continue; // another worker took it
            }

            $message = OutboxMessage::findOrFail($id);
            $totals[$this->deliver($message)]++;
        }

        return $totals;
    }

    /** @return 'sent'|'failed'|'retried'|'skipped' */
    private function deliver(OutboxMessage $message): string
    {
        if ($message->type === 'answer24.contact_requested' && ! $this->refreshContactRequest($message)) {
            $message->forceFill(['status' => 'cancelled', 'last_error' => 'opportunity_no_longer_ready'])->save();

            return 'skipped';
        }

        $result = $this->client->send($message);
        $attempts = $message->attempts + 1;

        if ($result['ok']) {
            DB::transaction(function () use ($message, $attempts, $result) {
                $message->forceFill(['status' => 'sent', 'sent_at' => now(), 'attempts' => $attempts, 'last_error' => null])->save();
                if ($message->type === 'answer24.contact_requested') {
                    $this->markDispatched($message, $result['reference']);
                }
            });

            return 'sent';
        }

        $max = (int) config('shopbonus.answer24.dispatch_max_attempts', 10);
        $final = ! $result['retryable'] || $attempts >= $max;
        $message->forceFill([
            'status' => $final ? 'failed' : 'pending',
            'attempts' => $attempts,
            'last_error' => $result['error'],
            'next_attempt_at' => $final ? null : now()->addMinutes(min(2 ** $attempts, 360)),
        ])->save();

        return $final ? 'failed' : 'retried';
    }

    /**
     * Keeps only orders that are still active in the opportunity.
     */
    private function refreshContactRequest(OutboxMessage $message): bool
    {
        $opportunity = ContactOpportunity::find($message->subject_id);
        if ($opportunity === null || $opportunity->status !== 'ready') {
            return false;
        }

        $active = WebshopOrder::whereIn('id', $opportunity->links()->where('status', 'active')->pluck('order_id'))->pluck('reference')->all();
        $payload = $message->payload;
        $payload['orders'] = array_values(array_filter($payload['orders'] ?? [], fn ($o) => in_array($o['reference'], $active, true)));

        if ($payload['orders'] === []) {
            return false;
        }

        $message->forceFill(['payload' => $payload])->save();

        return true;
    }

    private function markDispatched(OutboxMessage $message, ?string $reference): void
    {
        $opportunity = ContactOpportunity::whereKey($message->subject_id)->lockForUpdate()->firstOrFail();
        $sent = array_column($message->payload['orders'] ?? [], 'reference');

        if ($opportunity->status === 'ready') {
            $opportunity->forceFill(['status' => 'dispatched', 'dispatched_at' => now(), 'last_status_at' => now(), 'remote_reference' => $reference])->save();
            $orderIds = $opportunity->links()->where('status', 'active')->pluck('order_id');
            WebshopOrder::whereIn('id', $orderIds)->update(['contact_status' => 'dispatched', 'updated_at' => now()]);

            // Orders withdrawn while the request was in flight.
            $stillActive = WebshopOrder::whereIn('id', $orderIds)->pluck('reference')->all();
            foreach (array_diff($sent, $stillActive) as $withdrawn) {
                $this->queueStop($opportunity, 'answer24.order_withdrawn', (string) $withdrawn);
            }

            return;
        }

        // Cancelled while in flight: Answer24 has it now, so tell them to stop.
        $opportunity->forceFill(['status' => 'cancellation_requested', 'dispatched_at' => now(), 'remote_reference' => $reference, 'last_status_at' => now()])->save();
        $this->queueStop($opportunity, 'answer24.contact_cancelled', null);
    }

    private function queueStop(ContactOpportunity $opportunity, string $type, ?string $orderReference): void
    {
        $this->outbox->record($type, $type.':'.$opportunity->public_id.':'.($orderReference ?? 'all'), [
            'contract' => 'shopbonus-answer24-v1',
            'opportunity_id' => $opportunity->public_id,
            'remote_reference' => $opportunity->remote_reference,
            'order_reference' => $orderReference,
            'reason' => $opportunity->cancel_reason ?? 'no_longer_eligible',
        ], $opportunity->webshop_id, 'contact_opportunity', $opportunity->id);
    }
}
