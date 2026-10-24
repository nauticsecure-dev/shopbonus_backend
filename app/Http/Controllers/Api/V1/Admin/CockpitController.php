<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Admin\Cockpit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Super Admin cockpit overview and platform activity (Trello #21 "Cockpit overview").
 */
class CockpitController extends Controller
{
    public function show(Request $request, Cockpit $cockpit): JsonResponse
    {
        $data = $request->validate([
            'period' => ['sometimes', Rule::in(['today', '7d', '30d', 'custom'])],
            'from' => ['required_if:period,custom', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $tz = (string) config('shopbonus.business_timezone');
        $today = CarbonImmutable::now($tz);

        [$from, $to] = match ($data['period'] ?? '30d') {
            'today' => [$today->startOfDay(), $today->endOfDay()],
            '7d' => [$today->subDays(6)->startOfDay(), $today->endOfDay()],
            'custom' => [CarbonImmutable::parse($data['from'], $tz)->startOfDay(), CarbonImmutable::parse($data['to'], $tz)->endOfDay()],
            default => [$today->subDays(29)->startOfDay(), $today->endOfDay()],
        };

        if ($from->diffInDays($to) > 366) {
            throw new ApiException('Choose a range of at most one year.', 422, 'range_too_long');
        }

        // Day boundaries are stable within a day, so they double as the cache key.
        return response()->json(['data' => $cockpit->summary($from->utc(), $to->utc()) + ['period_name' => $data['period'] ?? '30d', 'timezone' => $tz]]);
    }

    /**
     * Recent platform activity from the audit log (newest first, paginated).
     */
    public function activity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['sometimes', Rule::enum(AuditCategory::class)],
            'outcome' => ['sometimes', Rule::enum(AuditOutcome::class)],
            'action' => ['sometimes', 'string', 'max:64'],
            'webshop_id' => ['sometimes', 'string', 'max:26'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = AuditEvent::query()
            ->when($data['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($data['outcome'] ?? null, fn ($q, $o) => $q->where('outcome', $o))
            ->when($data['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', addcslashes($a, '%_\\').'%'))
            ->when($data['webshop_id'] ?? null, fn ($q, $w) => $q->where('webshop_id', Webshop::where('public_id', $w)->value('id') ?? 0))
            ->orderByDesc('id')->paginate($data['per_page'] ?? 25);

        $users = User::whereIn('id', $page->getCollection()->flatMap(fn (AuditEvent $e) => [$e->actor_user_id, $e->real_actor_user_id])->filter()->unique())
            ->get(['id', 'public_id', 'email'])->keyBy('id');
        $webshops = Webshop::whereIn('id', $page->getCollection()->pluck('webshop_id')->filter()->unique())->get(['id', 'public_id', 'name'])->keyBy('id');
        $ref = fn (?int $id) => $id === null || ! isset($users[$id]) ? null : ['id' => $users[$id]->public_id, 'email' => $users[$id]->email];

        return response()->json([
            'data' => $page->getCollection()->map(fn (AuditEvent $e) => [
                'occurred_at' => $e->occurred_at->toIso8601String(),
                'category' => $e->category->value,
                'action' => $e->action,
                'outcome' => $e->outcome->value,
                'actor' => $ref($e->actor_user_id),
                'real_actor' => $e->real_actor_user_id !== $e->actor_user_id ? $ref($e->real_actor_user_id) : null,
                'webshop' => $e->webshop_id !== null && isset($webshops[$e->webshop_id]) ? ['id' => $webshops[$e->webshop_id]->public_id, 'name' => $webshops[$e->webshop_id]->name] : null,
                'http_status' => $e->http_status,
                'http_method' => $e->http_method,
                'route' => $e->route,
                'request_id' => $e->request_id,
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }
}
