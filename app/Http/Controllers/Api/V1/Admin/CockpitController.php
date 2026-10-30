<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Enums\VoucherStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Admin\Cockpit;
use App\Services\Attribution\NetworkAnalytics;
use App\Services\Audit\AuditLogger;
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
        [$from, $to, $period] = $this->period($request);

        // Day boundaries are stable within a day, so they double as the cache key.
        return response()->json(['data' => $cockpit->summary($from->utc(), $to->utc()) + [
            'period_name' => $period,
            'timezone' => (string) config('shopbonus.business_timezone'),
        ]]);
    }

    /** 100-shop network overview (Trello #24 §6) — aggregates only, no cross-tenant PII. */
    public function network(Request $request, NetworkAnalytics $network): JsonResponse
    {
        [$from, $to, $period] = $this->period($request);

        return response()->json(['data' => $network->platformOverview($from->utc(), $to->utc()) + [
            'period_name' => $period,
            'timezone' => (string) config('shopbonus.business_timezone'),
        ]]);
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
            ->get(['id', 'public_id', 'email', 'first_name'])->keyBy('id');
        $webshops = Webshop::whereIn('id', $page->getCollection()->pluck('webshop_id')->filter()->unique())->get(['id', 'public_id', 'name'])->keyBy('id');
        $ref = fn (?int $id) => $id === null || ! isset($users[$id]) ? null : [
            'id' => $users[$id]->public_id,
            'email' => $this->maskEmail((string) $users[$id]->email),
            'name' => $users[$id]->first_name,
        ];

        return response()->json([
            'data' => $page->getCollection()->map(function (AuditEvent $e) use ($ref, $webshops) {
                $shop = $e->webshop_id !== null && isset($webshops[$e->webshop_id])
                    ? ['id' => $webshops[$e->webshop_id]->public_id, 'name' => $webshops[$e->webshop_id]->name]
                    : null;
                $mapped = $this->mapActivity($e->action, $shop);

                return [
                    'id' => (string) $e->id,
                    'occurred_at' => $e->occurred_at->toIso8601String(),
                    'category' => $e->category->value,
                    'action' => $e->action,
                    'outcome' => $e->outcome->value,
                    'type' => $mapped['type'],
                    'description' => $mapped['description'],
                    'detail' => $mapped['detail'],
                    'href' => $mapped['href'],
                    'actor' => $ref($e->actor_user_id),
                    'real_actor' => $e->real_actor_user_id !== $e->actor_user_id ? $ref($e->real_actor_user_id) : null,
                    'webshop' => $shop,
                    'http_status' => $e->http_status,
                    'http_method' => $e->http_method,
                    'route' => $e->route,
                    'request_id' => $e->request_id,
                ];
            })->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function search(Request $request, Cockpit $cockpit, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        $result = $cockpit->search($data['q'], $data['limit'] ?? 5);
        $audit->recordSafely('admin.cockpit_search', category: AuditCategory::Admin, metadata: [
            'query_length' => mb_strlen($data['q']),
            'group_count' => count($result['groups']),
        ]);

        return response()->json(['data' => $result]);
    }

    public function vouchers(Request $request, Cockpit $cockpit): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(VoucherStatus::class)],
            'id' => ['sometimes', 'string', 'max:26'],
            'issued_on' => ['sometimes', 'date_format:Y-m-d'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $tz = (string) config('shopbonus.business_timezone');
        $from = null;
        $to = null;
        if (! empty($data['issued_on'])) {
            $from = CarbonImmutable::parse($data['issued_on'], $tz)->startOfDay()->utc();
            $to = CarbonImmutable::parse($data['issued_on'], $tz)->endOfDay()->utc();
        } elseif (! empty($data['from']) && ! empty($data['to'])) {
            $from = CarbonImmutable::parse($data['from'], $tz)->startOfDay()->utc();
            $to = CarbonImmutable::parse($data['to'], $tz)->endOfDay()->utc();
        }

        return response()->json($cockpit->vouchers(
            isset($data['status']) ? (is_string($data['status']) ? $data['status'] : $data['status']->value) : null,
            $from,
            $to,
            $data['id'] ?? null,
            $data['per_page'] ?? 25,
        ));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'period' => ['sometimes', Rule::in(['today', '7d', '30d', '90d', 'custom'])],
            'from' => ['required_if:period,custom', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $tz = (string) config('shopbonus.business_timezone');
        $today = CarbonImmutable::now($tz);
        $period = $data['period'] ?? '30d';

        [$from, $to] = match ($period) {
            'today' => [$today->startOfDay(), $today->endOfDay()],
            '7d' => [$today->subDays(6)->startOfDay(), $today->endOfDay()],
            '90d' => [$today->subDays(89)->startOfDay(), $today->endOfDay()],
            'custom' => [CarbonImmutable::parse($data['from'], $tz)->startOfDay(), CarbonImmutable::parse($data['to'], $tz)->endOfDay()],
            default => [$today->subDays(29)->startOfDay(), $today->endOfDay()],
        };

        if ($from->diffInDays($to) > 366) {
            throw new ApiException('Choose a range of at most one year.', 422, 'range_too_long');
        }

        return [$from, $to, $period === 'custom' ? 'custom' : $period];
    }

    /**
     * @param  array{id: string, name: string}|null  $shop
     * @return array{type: string, description: string, detail: string, href: string|null}
     */
    private function mapActivity(string $action, ?array $shop): array
    {
        $name = $shop['name'] ?? null;
        $shopHref = $shop ? '/admin/webshops/'.$shop['id'] : null;

        return match (true) {
            str_starts_with($action, 'workspace.registered'), str_contains($action, 'webshop_created'), str_contains($action, 'workspace.created') => [
                'type' => 'Webshop', 'description' => 'Nieuwe webshopregistratie', 'detail' => $name ?? '—', 'href' => $shopHref,
            ],
            str_contains($action, 'approved') && (str_contains($action, 'workspace') || str_contains($action, 'webshop')) => [
                'type' => 'Webshop', 'description' => 'Webshop goedgekeurd', 'detail' => $name ?? '—', 'href' => $shopHref,
            ],
            str_contains($action, 'rejected') && (str_contains($action, 'workspace') || str_contains($action, 'webshop')) => [
                'type' => 'Webshop', 'description' => 'Webshop afgewezen', 'detail' => $name ?? '—', 'href' => $shopHref,
            ],
            str_contains($action, 'suspended') => [
                'type' => 'Webshop', 'description' => 'Webshop geschorst', 'detail' => $name ?? '—', 'href' => $shopHref,
            ],
            str_contains($action, 'voucher') && str_contains($action, 'claim') => [
                'type' => 'Voucher', 'description' => 'Voucher geclaimd', 'detail' => $name ?? '—', 'href' => '/admin/vouchers',
            ],
            str_contains($action, 'voucher') && str_contains($action, 'redeem') => [
                'type' => 'Voucher', 'description' => 'Voucher ingewisseld', 'detail' => $name ?? '—', 'href' => '/admin/vouchers?status=redeemed',
            ],
            str_contains($action, 'affiliate') && (str_contains($action, 'import') || str_contains($action, 'sync') || str_contains($action, 'transaction')) => [
                'type' => 'Affiliate', 'description' => str_contains($action, 'revers') ? 'Affiliateconversie teruggedraaid' : 'Affiliateconversie geïmporteerd',
                'detail' => $name ?? 'Netwerk', 'href' => '/admin/affiliate',
            ],
            str_contains($action, 'order') && (str_contains($action, 'fail') || str_contains($action, 'sync')) => [
                'type' => 'Sync', 'description' => 'Mislukte ordersynchronisatie', 'detail' => $name ?? '—', 'href' => '/admin/integrations?status=error',
            ],
            str_contains($action, 'wallet') || str_contains($action, 'funding') => [
                'type' => 'Wallet', 'description' => 'Walletgebeurtenis', 'detail' => $name ?? '—', 'href' => '/admin/wallets',
            ],
            str_contains($action, 'support') || str_contains($action, 'review') => [
                'type' => 'Support', 'description' => 'Supportgebeurtenis', 'detail' => $name ?? '—', 'href' => '/admin/support',
            ],
            default => [
                'type' => 'Systeem', 'description' => str_replace(['.', '_'], [' ', ' '], $action), 'detail' => $name ?? '—', 'href' => $shopHref ?? '/admin',
            ],
        };
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return '***';
        }
        $keep = max(1, min(2, strlen($local)));

        return substr($local, 0, $keep).str_repeat('*', max(1, strlen($local) - $keep)).'@'.$domain;
    }
}
