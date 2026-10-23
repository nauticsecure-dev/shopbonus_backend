<?php

namespace App\Services\Answer24;

use App\Http\Resources\OfferResource;
use App\Models\ContactOpportunity;
use App\Models\ContactOpportunityOrder;
use App\Models\Webshop;
use App\Models\WebshopOrder;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Groups eligible orders into Answer24 contact opportunities (Trello #1
 * "One-hour grouping", "Cancellation/refunds").
 *
 * - Same customer + same webshop + window open (one hour from the first
 *   eligible order) → same opportunity. Never across webshops.
 * - An order joins at most one opportunity, ever (DB unique), so replays
 *   and re-evaluations cannot create a second contact for it.
 * - A customer contacted within `recent_contact_days` is not contacted again.
 * - When an order stops being eligible (cancel/refund/consent change) it is
 *   removed; an opportunity with no orders left is cancelled; for one already
 *   sent to Answer24 a cancellation/withdrawal is queued in the outbox.
 */
class ContactPlanner
{
    public function __construct(
        private readonly OrderEligibility $eligibility,
        private readonly Outbox $outbox,
    ) {}

    public function sync(WebshopOrder $order, Webshop $webshop): void
    {
        DB::transaction(function () use ($order, $webshop) {
            $order = WebshopOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $result = $this->eligibility->evaluate($order, $webshop);
            $link = ContactOpportunityOrder::where('order_id', $order->id)->lockForUpdate()->first();

            if (! $result['eligible'] || ! $result['callable']) {
                $wasGrouped = $link?->status === 'active'; // read before remove() changes it
                if ($wasGrouped) {
                    $this->remove($link, $result['reasons'][0] ?? 'not_callable');
                }
                $this->mark($order, $result, $wasGrouped ? 'removed' : ($result['eligible'] ? 'not_callable' : 'blocked'));

                return;
            }

            if ($link !== null) {
                // Already handled once (grouped, or removed earlier): never re-contact for the same order.
                $this->mark($order, $result, $order->contact_status === 'not_evaluated' ? 'grouped' : $order->contact_status);

                return;
            }

            $opportunity = ContactOpportunity::where('webshop_id', $webshop->id)->where('customer_key', $order->customer_key)
                ->where('status', 'pending')->where('window_ends_at', '>', now())->lockForUpdate()->first();

            if ($opportunity === null && $this->recentlyContacted($webshop->id, (string) $order->customer_key)) {
                $this->mark($order, ['reasons' => ['recently_contacted']] + $result, 'blocked', eligible: false);

                return;
            }

            $opportunity ??= ContactOpportunity::create([
                'webshop_id' => $webshop->id,
                'offer_id' => $result['offer']?->id,
                'customer_key' => $order->customer_key,
                'status' => 'pending',
                'lawful_basis' => $result['basis'],
                'window_ends_at' => now()->addMinutes((int) config('shopbonus.answer24.grouping_window_minutes', 60)),
                'idempotency_key' => 'contact:'.$webshop->id.':'.Str::lower((string) Str::ulid()),
                'last_status_at' => now(),
            ]);

            ContactOpportunityOrder::create(['opportunity_id' => $opportunity->id, 'order_id' => $order->id, 'status' => 'active']);
            $this->mark($order, $result, 'grouped');
        });
    }

    /**
     * Closes grouping windows that have ended: re-checks every order right
     * before queueing (Trello: "Recheck eligibility immediately before
     * dispatch"). Returns the number of opportunities queued.
     */
    public function closeWindows(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $queued = 0;

        $ids = ContactOpportunity::where('status', 'pending')->where('window_ends_at', '<=', $now)->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$queued) {
                $opportunity = ContactOpportunity::whereKey($id)->where('status', 'pending')->lockForUpdate()->first();
                if ($opportunity === null) {
                    return;
                }

                $webshop = Webshop::with('businessProfile')->findOrFail($opportunity->webshop_id);
                $orders = [];

                foreach ($opportunity->links()->where('status', 'active')->with('order')->lockForUpdate()->get() as $link) {
                    $result = $this->eligibility->evaluate($link->order, $webshop);

                    if (! $result['eligible'] || ! $result['callable']) {
                        $link->forceFill(['status' => 'removed', 'removed_reason' => $result['reasons'][0] ?? 'not_callable', 'removed_at' => now()])->save();
                        $this->mark($link->order, $result, 'removed');

                        continue;
                    }

                    $orders[] = [$link->order, $result];
                }

                if ($orders === []) {
                    $opportunity->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'no_eligible_orders', 'last_status_at' => now()])->save();

                    return;
                }

                [$first, $firstResult] = $orders[0];
                $opportunity->forceFill(['status' => 'ready', 'ready_at' => now(), 'last_status_at' => now(), 'offer_id' => $firstResult['offer']->id ?? $opportunity->offer_id])->save();

                $this->outbox->record('answer24.contact_requested', 'contact_requested:'.$opportunity->public_id, [
                    'contract' => 'shopbonus-answer24-v1',
                    'opportunity_id' => $opportunity->public_id,
                    'idempotency_key' => $opportunity->idempotency_key,
                    'webshop' => ['id' => $webshop->public_id, 'name' => $webshop->name],
                    'offer' => $firstResult['offer'] === null ? null : [
                        'id' => $firstResult['offer']->public_id,
                        'summary' => $firstResult['offer']->currentVersion?->summary(),
                        'terms' => $firstResult['offer']->currentVersion === null ? null : OfferResource::terms($firstResult['offer']->currentVersion),
                    ],
                    // Minimum data needed for the call (Trello #1 "Answer24 exchange").
                    'customer' => ['first_name' => $first->customer_first_name, 'phone' => PhoneNumber::normalize($first->customer_phone)],
                    'orders' => array_map(fn ($pair) => [
                        'reference' => $pair[0]->reference, 'placed_at' => $pair[0]->placed_at?->toIso8601String(),
                        'amount_minor' => $pair[0]->amount_minor, 'currency' => $pair[0]->currency,
                    ], $orders),
                    'lawful_basis' => ['basis' => $opportunity->lawful_basis, 'evidence_ref' => 'webshop:'.$webshop->public_id.':answer24_settings'],
                ], $webshop->id, 'contact_opportunity', $opportunity->id);

                foreach ($orders as [$order, $result]) {
                    $this->mark($order, $result, 'queued');
                }
                $queued++;
            });
        }

        return $queued;
    }

    private function remove(ContactOpportunityOrder $link, string $reason): void
    {
        $link->forceFill(['status' => 'removed', 'removed_reason' => $reason, 'removed_at' => now()])->save();
        $opportunity = ContactOpportunity::whereKey($link->opportunity_id)->lockForUpdate()->firstOrFail();
        $remaining = $opportunity->links()->where('status', 'active')->count();

        if (in_array($opportunity->status, ContactOpportunity::OPEN, true)) {
            if ($remaining === 0) {
                $opportunity->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason, 'last_status_at' => now()])->save();
                $this->outbox->cancelPending('contact_opportunity', $opportunity->id, 'answer24.contact_requested');
            }

            return;
        }

        if (in_array($opportunity->status, ContactOpportunity::DISPATCHED, true)) {
            // Already with Answer24: tell them to stop (suppression), never silently.
            $order = WebshopOrder::find($link->order_id);
            $type = $remaining === 0 ? 'answer24.contact_cancelled' : 'answer24.order_withdrawn';
            $this->outbox->record($type, $type.':'.$opportunity->public_id.':'.$link->order_id, [
                'contract' => 'shopbonus-answer24-v1',
                'opportunity_id' => $opportunity->public_id,
                'remote_reference' => $opportunity->remote_reference,
                'order_reference' => $order?->reference,
                'reason' => $reason,
            ], $opportunity->webshop_id, 'contact_opportunity', $opportunity->id);

            if ($remaining === 0) {
                $opportunity->forceFill(['status' => 'cancellation_requested', 'cancelled_at' => now(), 'cancel_reason' => $reason, 'last_status_at' => now()])->save();
            }
        }
    }

    private function recentlyContacted(int $webshopId, string $customerKey): bool
    {
        $days = (int) config('shopbonus.answer24.recent_contact_days', 30);

        return ContactOpportunity::where('webshop_id', $webshopId)->where('customer_key', $customerKey)
            ->whereIn('status', ['ready', ...ContactOpportunity::DISPATCHED])
            ->where('created_at', '>=', now()->subDays($days))->exists();
    }

    /**
     * @param  array{reasons: list<string>, eligible?: bool}  $result
     */
    private function mark(WebshopOrder $order, array $result, string $contactStatus, ?bool $eligible = null): void
    {
        $eligible ??= $result['eligible'] ?? false;

        $order->forceFill([
            'eligibility_status' => $eligible ? 'eligible' : 'ineligible',
            'eligibility_reasons' => $result['reasons'] === [] ? null : $result['reasons'],
            'contact_status' => $contactStatus,
        ])->save();
    }
}
