<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\PlatformSettingVersion;
use App\Services\Admin\PlatformSettings;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationCatalogue;
use App\Services\Notifications\NotificationPolicy;
use App\Services\Notifications\NotificationTemplateAi;
use App\Services\Notifications\NotificationTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class NotificationController extends Controller
{
    public function templates(Request $r, NotificationTemplates $t): JsonResponse
    {
        $data = $r->validate(['page' => ['sometimes', 'integer', 'between:1,10000'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = PlatformSettingVersion::whereIn('setting_id', DB::table('platform_settings')->where('key', 'like', 'notifications.template.%')->select('id'))->orderByDesc('id')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $page->getCollection()->map(fn ($v) => $t->present($v))->all(), 'meta' => ['current_page' => $page->currentPage(), 'total' => $page->total()]]);
    }

    public function show(string $template, NotificationTemplates $t): JsonResponse
    {
        return response()->json(['data' => $t->present($t->find($template))]);
    }

    public function generate(Request $r, NotificationTemplateAi $ai): JsonResponse
    {
        $data = $r->validate(['event_type' => ['required', Rule::in(array_keys(NotificationCatalogue::EVENTS))]]);
        $key = $r->header('Idempotency-Key');
        Validator::make(['idempotency_key' => $key], ['idempotency_key' => ['required', 'uuid']])->validate();

        return response()->json(['data' => $ai->generate($r->user(), $data['event_type'], strtolower($key))], 201);
    }

    public function store(Request $r, NotificationTemplates $t, PlatformSettings $s): JsonResponse
    {
        $d = $r->validate(['event_type' => ['required', Rule::in(array_keys(NotificationCatalogue::EVENTS))], 'locale' => ['required', Rule::in(['nl', 'en'])], 'value' => ['required', 'array'], 'reason' => ['required', 'string', 'between:5,500']]);

        return response()->json(['data' => $t->present($s->draft($r->user(), NotificationTemplates::key($d['event_type'], $d['locale']), $d['value'], $d['reason']))], 201);
    }

    public function update(Request $r, string $template, NotificationTemplates $t, PlatformSettings $s): JsonResponse
    {
        $d = $r->validate(['value' => ['required', 'array'], 'expected_checksum' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'between:5,500']]);
        $v = $t->find($template);
        $next = DB::transaction(function () use ($r, $d, $v, $s) {
            $setting = DB::table('platform_settings')->where('id', $v->setting_id)->lockForUpdate()->first();
            if (! hash_equals($d['expected_checksum'], $v->checksum) || (int) $setting->last_version !== $v->version) {
                throw ApiException::conflict('Template changed. Refresh before editing.', 'notification_template_stale');
            }

            return $s->draft($r->user(), $setting->key, $d['value'], $d['reason']);
        });

        return response()->json(['data' => $t->present($next)], 201);
    }

    public function test(Request $r, string $template, NotificationTemplates $t): JsonResponse
    {
        return response()->json(['data' => $t->test($t->find($template), $r->user())]);
    }

    public function publish(Request $r, string $template, NotificationTemplates $t, PlatformSettings $s): JsonResponse
    {
        $note = $r->validate(['note' => ['required', 'string', 'between:5,500']])['note'];
        $v = DB::transaction(function () use ($r, $template, $t, $s, $note) {
            $v = $t->find($template);
            if ($v->status === 'draft') {
                $v = $s->submit($v, $r->user());
            }

            return $s->publish($v, $r->user(), $note);
        });

        return response()->json(['data' => $t->present($v)]);
    }

    public function rollback(Request $r, string $template, NotificationTemplates $t, PlatformSettings $s): JsonResponse
    {
        $reason = $r->validate(['reason' => ['required', 'string', 'between:5,500']])['reason'];

        return response()->json(['data' => $t->present($s->rollback($t->find($template), $r->user(), $reason))], 201);
    }

    public function events(): JsonResponse
    {
        return response()->json(['data' => collect(NotificationCatalogue::EVENTS)->map(fn ($d, $type) => ['event_type' => $type, 'audience' => $d[0],
            'mandatory_in_app' => ! NotificationCatalogue::optionalInApp($type), 'mandatory_email' => $d[1], 'allowed_variables' => $type === 'cashback_credited' ? ['amount_formatted'] : [],
            'locales' => ['nl', 'en'], 'reminder_days' => $type === 'voucher_expiring' ? app(NotificationPolicy::class)->reminderDays() : []])->values()->all(),
            'failures' => NotificationEvent::where('status', 'failed')->orderByDesc('id')->limit(50)->get()->map(fn ($e) => ['id' => $e->public_id, 'event_type' => $e->event_type, 'attempts' => $e->attempts, 'error_code' => $e->error_code])->all()]);
    }

    public function deliveries(Request $r): JsonResponse
    {
        $d = $r->validate(['page' => ['sometimes', 'integer', 'between:1,10000'], 'status' => ['sometimes', Rule::in(['pending', 'retry', 'sending', 'sent', 'skipped', 'failed', 'uncertain', 'confirmed_sent'])], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = NotificationDelivery::when($d['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->orderByDesc('id')->paginate($d['per_page'] ?? 25);

        return response()->json(['data' => $page->getCollection()->map(fn ($v) => $this->delivery($v))->all(), 'meta' => ['current_page' => $page->currentPage(), 'total' => $page->total()],
            'operations' => ['failed_events' => DB::table('notification_events')->where('status', 'failed')->count(), 'locale_fallbacks' => DB::table('notification_template_fallbacks')->sum('occurrences')]]);
    }

    public function retry(Request $r, string $delivery): JsonResponse
    {
        $d = $r->validate(['note' => ['required', 'string', 'between:10,500'], 'duplicate_risk_acknowledged' => ['sometimes', 'boolean']]);
        $v = DB::transaction(function () use ($r, $delivery, $d) {
            $v = NotificationDelivery::where('public_id', strtolower($delivery))->lockForUpdate()->first() ?? throw new ApiException('Delivery not found.', 404, 'not_found');
            if (! in_array($v->status, ['failed', 'uncertain'], true) || $v->attempts >= 5) {
                throw ApiException::conflict('Delivery cannot be retried.', 'notification_delivery_not_retryable');
            }
            if ($v->status === 'uncertain' && ! ($d['duplicate_risk_acknowledged'] ?? false)) {
                throw ApiException::conflict('Review the uncertain transport result before retrying.', 'notification_duplicate_risk');
            }
            $v->forceFill(['status' => 'retry', 'available_at' => now(), 'error_code' => null])->save();
            app(AuditLogger::class)->record('notifications.delivery_retry_authorized', category: AuditCategory::Admin, resource: $v, actorUserId: $r->user()->id, metadata: $d);

            return $v;
        });

        return response()->json(['data' => $this->delivery($v)]);
    }

    public function resolveDelivery(Request $r, string $delivery): JsonResponse
    {
        $d = $r->validate(['note' => ['required', 'string', 'between:10,500'], 'outcome' => ['required', Rule::in(['confirmed_sent', 'do_not_resend'])]]);
        $v = DB::transaction(function () use ($r, $delivery, $d) {
            $v = NotificationDelivery::where('public_id', strtolower($delivery))->lockForUpdate()->first() ?? throw new ApiException('Delivery not found.', 404, 'not_found');
            if ($v->status !== 'uncertain') {
                throw ApiException::conflict('Only uncertain deliveries require resolution.', 'notification_delivery_not_uncertain');
            }
            $v->forceFill(['status' => $d['outcome'] === 'confirmed_sent' ? 'confirmed_sent' : 'skipped', 'error_code' => 'human_reviewed'])->save();
            app(AuditLogger::class)->record('notifications.delivery_reviewed', category: AuditCategory::Admin, resource: $v, actorUserId: $r->user()->id, metadata: $d);

            return $v;
        });

        return response()->json(['data' => $this->delivery($v)]);
    }

    /** @return array<string, mixed> */
    private function delivery(NotificationDelivery $d): array
    {
        return ['id' => $d->public_id, 'notification_id' => $d->notification_id, 'channel' => $d->channel, 'status' => $d->status, 'attempts' => $d->attempts, 'error_code' => $d->error_code, 'sent_at' => $d->sent_at?->toIso8601String()];
    }

    public function retryEvent(Request $r, string $event): JsonResponse
    {
        $note = $r->validate(['note' => ['required', 'string', 'between:10,500']])['note'];
        $e = DB::transaction(function () use ($r, $event, $note) {
            $e = NotificationEvent::where('public_id', strtolower($event))->lockForUpdate()->first() ?? throw new ApiException('Notification event not found.', 404, 'not_found');
            if ($e->status !== 'failed' || $e->attempts >= 5) {
                throw ApiException::conflict('Event cannot be retried.', 'notification_event_not_retryable');
            }
            $e->forceFill(['status' => 'pending', 'available_at' => now(), 'error_code' => null])->save();
            app(AuditLogger::class)->record('notifications.event_retry_authorized', category: AuditCategory::Admin, resource: $e, actorUserId: $r->user()->id, metadata: ['note' => $note]);

            return $e;
        });

        return response()->json(['data' => ['id' => $e->public_id, 'status' => $e->status]]);
    }

    public function settings(NotificationPolicy $p): JsonResponse
    {
        return response()->json(['data' => $p->current(), 'email_delivery_available' => $p->emailEnabled()]);
    }

    public function updateSettings(Request $r, PlatformSettings $s): JsonResponse
    {
        $d = $r->validate(['value' => ['required', 'array'], 'reason' => ['required', 'string', 'between:5,500']]);
        $v = $s->draft($r->user(), 'notifications.policy', $d['value'], $d['reason']);

        return response()->json(['data' => ['id' => $v->public_id, 'status' => $v->status, 'value' => $v->value], 'publication' => 'Use existing platform-settings submit and independent publish endpoints.'], 201);
    }
}
