<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Bounded JSON export, at most 500 events per page and a 31-day interval; never exports raw metadata/PII payloads. */
class AuditExportController extends Controller
{
    public function export(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500']]);
        $from = CarbonImmutable::parse($data['from'], 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($data['to'], 'UTC')->endOfDay();
        if ($from->diffInDays($to) > 31) {
            throw new ApiException('Audit exports are limited to 31 days per request.', 422, 'audit_export_range_exceeded');
        }
        $audit->record('admin.audit_exported', category: AuditCategory::Admin, metadata: ['from' => $data['from'], 'to' => $data['to']]);
        $page = AuditEvent::whereBetween('occurred_at', [$from, $to])->orderBy('id')->paginate($data['per_page'] ?? 100);
        $seals = DB::table('audit_event_seals')->whereIn('audit_event_id', $page->getCollection()->modelKeys())->get()->keyBy('audit_event_id');

        return response()->json(['data' => $page->getCollection()->map(function (AuditEvent $e) use ($seals) {
            $seal = $seals->get($e->id);

            return ['id' => $seal?->public_id, 'occurred_at' => $e->occurred_at->toIso8601String(), 'request_id' => $e->request_id,
                'category' => $e->category->value, 'action' => $e->action, 'outcome' => $e->outcome->value,
                'actor_id' => $e->actor_user_id === null ? null : User::find($e->actor_user_id)?->public_id,
                'real_actor_id' => $e->real_actor_user_id === null ? null : User::find($e->real_actor_user_id)?->public_id,
                'webshop_id' => $e->webshop_id === null ? null : Webshop::find($e->webshop_id)?->public_id,
                'route' => $e->route, 'method' => $e->http_method, 'http_status' => $e->http_status,
                'integrity' => $seal === null ? null : ['previous_hash' => $seal->previous_hash, 'event_hash' => $seal->event_hash, 'chain_hash' => $seal->chain_hash]];
        })->all(), 'meta' => ['current_page' => $page->currentPage(), 'total' => $page->total(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()],
            'integrity' => ['algorithm' => 'sha256-chain-v1', 'head' => DB::table('audit_chain_heads')->where('id', 1)->value('last_hash'),
                'verification_command' => 'php artisan shopbonus:audit-verify', 'projection' => 'Safe projection; verify raw event digests on the server. Retain exported heads off-host for independent anchoring.']]);
    }
}
