<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactOpportunity;
use App\Models\ContactOpportunityEvent;
use App\Models\OutboxMessage;
use App\Models\WebhookEvent;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Admin\OperationalRetries;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OperationsController extends Controller
{
    public function connections(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'webshop_id' => ['sometimes', 'ulid']]);
        $today = CarbonImmutable::now(config('shopbonus.business_timezone', 'Europe/Amsterdam'))->startOfDay()->utc();
        $page = WebshopConnection::with('webshop:id,public_id,name')->when($data['webshop_id'] ?? null,
            fn ($q, $id) => $q->whereHas('webshop', fn ($s) => $s->where('public_id', Str::lower($id))))->orderByDesc('id')->paginate($data['per_page'] ?? 25);
        $rows = $page->getCollection()->map(function (WebshopConnection $c) use ($today) {
            $success = $c->events()->where('status', 'processed')->max('processed_at');

            return ['id' => $c->public_id, 'webshop' => ['id' => $c->webshop->public_id, 'name' => $c->webshop->name],
                'platform' => $c->platform->value, 'status' => $c->status->value, 'last_event_at' => $c->last_event_at?->toIso8601String(),
                'last_successful_event_at' => $success === null ? null : CarbonImmutable::parse($success, 'UTC')->toIso8601String(),
                'orders_today' => WebshopOrder::where('connection_id', $c->id)->where('created_at', '>=', $today)->count(),
                'failed_events' => $c->events()->where('status', 'failed')->count(),
                'incomplete_orders' => WebshopOrder::where('connection_id', $c->id)->where('ingestion_status', 'incomplete')->count(),
                'oldest_pending_event_at' => $c->events()->where('status', 'verified')->min('received_at'),
                'auth_error' => in_array($c->last_error_code, ['invalid_signature', 'unauthorized', 'authentication_failed', 'http_401', 'http_403'], true) ? $c->last_error_code : null,
                'last_error_code' => $c->last_error_code, 'limitations' => $c->limitations,
                'required_action' => in_array($c->status->value, ['error', 'setup_required', 'limited', 'disconnected'], true) ? 'review_connection_setup' : null];
        });

        return response()->json(['data' => $rows->all(), 'meta' => ['current_page' => $page->currentPage(), 'total' => $page->total(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()],
            'definitions' => ['orders_today' => 'Orders first received since the start of the current business-timezone day.',
                'delay' => 'oldest_pending_event_at is the start of the unprocessed verified-event backlog; null means none.',
                'auth_error' => 'Latest recorded connection authentication error, not a count of all rejected inbound requests.']]);
    }

    public function answer24(): JsonResponse
    {
        $states = ContactOpportunity::selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status')->map(fn ($v) => (int) $v)->all();
        $reasons = [];
        WebshopOrder::whereNotNull('eligibility_reasons')->select(['id', 'eligibility_reasons'])->orderBy('id')->chunkById(500, function ($orders) use (&$reasons) {
            foreach ($orders as $order) {
                foreach ($order->eligibility_reasons ?? [] as $reason) {
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                }
            }
        });

        return response()->json(['data' => ['dispatch_enabled' => (bool) config('shopbonus.answer24.dispatch_enabled'),
            'eligible_paid_orders' => WebshopOrder::where('payment_status', 'paid')->where('eligibility_status', 'eligible')->count(),
            'contacts_by_status' => $states, 'eligibility_failures' => $reasons,
            'failed_dispatches' => OutboxMessage::where('target', 'answer24')->where('status', 'failed')->count(),
            'failed_contact_results' => ContactOpportunityEvent::where('status', 'failed')->count(),
            'failed_messages' => OutboxMessage::where('target', 'answer24')->where('status', 'failed')->orderByDesc('id')->limit(100)->get()->map(fn (OutboxMessage $m) => [
                'id' => $m->public_id, 'type' => $m->type, 'status' => $m->status, 'attempts' => $m->attempts, 'last_error' => $m->last_error])->all()],
            'definitions' => ['eligibility_failures' => 'Current stored order eligibility reasons, counted per order/reason; may overlap.',
                'failed_contact_results' => 'Authenticated Answer24 contact results reporting failure; rejected unsigned requests are security audit events.',
                'provider_verification' => 'NOT VERIFIED; dispatch remains controlled by configuration.']]);
    }

    public function events(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = WebhookEvent::where('status', 'failed')->with('connection:id,public_id')->orderByDesc('id')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $page->getCollection()->map(fn (WebhookEvent $e) => [
            'id' => $e->public_id, 'connection_id' => $e->connection->public_id, 'status' => $e->status->value,
            'error_code' => $e->error_code, 'received_at' => $e->received_at->toIso8601String(), 'payload_retained' => $e->payload_pruned_at === null])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'total' => $page->total(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()]]);
    }

    public function retryWebhook(Request $request, string $event, OperationalRetries $retries): JsonResponse
    {
        $reason = $this->reason($request);
        $model = WebhookEvent::where('public_id', Str::lower($event))->first() ?? throw new NotFoundHttpException;
        $retries->webhook($model, $reason);

        return response()->json(['data' => ['id' => $model->public_id, 'status' => $model->fresh()?->status->value]], 202);
    }

    public function retryOutbox(Request $request, string $message, OperationalRetries $retries): JsonResponse
    {
        $reason = $this->reason($request);
        $model = OutboxMessage::where('public_id', Str::lower($message))->first() ?? throw new NotFoundHttpException;
        $retries->outbox($model, $reason);

        return response()->json(['data' => ['id' => $model->public_id, 'status' => 'pending']], 202);
    }

    private function reason(Request $request): string
    {
        return $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
    }
}
