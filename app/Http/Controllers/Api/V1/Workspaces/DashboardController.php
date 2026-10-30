<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\MerchantNotification;
use App\Services\Analytics\MerchantAnalytics;
use App\Services\Funding\MerchantFunding;
use App\Services\Notifications\NotificationInbox;
use App\Services\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Merchant overview (Trello #8): KPIs, offers, notifications and the
 * lifecycle chart for the selected workspace only.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly MerchantAnalytics $analytics) {}

    public function show(Request $request, CurrentWorkspace $current, MerchantFunding $funding): JsonResponse
    {
        $webshop = $current->webshop();
        $filters = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['all', 'active', 'paused', 'draft', 'ended', 'expired', 'scheduled', 'archived'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $status = ($filters['status'] ?? 'all') === 'all' ? null : $filters['status'];
        $offers = $this->analytics->offers(
            $webshop->id,
            (int) ($filters['per_page'] ?? 10),
            (int) ($filters['page'] ?? 1),
            $status,
        );

        return response()->json(['data' => [
            'webshop' => ['id' => $webshop->public_id, 'name' => $webshop->name, 'status' => $webshop->status->value],
            'greeting' => $this->greeting(),
            'kpis' => $this->analytics->kpis($webshop->id),
            'connection_health' => $this->analytics->connectionHealth($webshop->id),
            'recent_activity' => $this->analytics->recentActivity($webshop->id),
            'quick_actions' => $this->analytics->quickActions($webshop->id),
            'empty' => $this->analytics->isEmpty($webshop->id),
            'definitions' => MerchantAnalytics::DEFINITIONS,
            'offers' => $offers['data'],
            'offers_meta' => $offers['meta'],
            'notifications' => [
                'unread' => MerchantNotification::where('webshop_id', $webshop->id)->whereNull('read_at')->count(),
                'latest' => MerchantNotification::where('webshop_id', $webshop->id)->orderByDesc('id')->limit(5)->get()->map(fn ($n) => self::notification($n))->all(),
            ],
            'funding' => ['available_minor' => $funding->wallet($webshop)->available, 'reserved_minor' => $funding->wallet($webshop)->reserved],
            'generated_at' => now()->toIso8601String(),
        ]]);
    }

    public function claims(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $data = $request->validate([
            'days' => ['sometimes', Rule::in(['7', '30', '90', 7, 30, 90])],
            'from' => ['sometimes', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
        ]);
        $tz = (string) config('shopbonus.business_timezone');
        [$from, $to] = isset($data['from'])
            ? [CarbonImmutable::parse($data['from'], $tz), CarbonImmutable::parse($data['to'], $tz)]
            : [CarbonImmutable::now($tz)->subDays((int) ($data['days'] ?? 30) - 1), CarbonImmutable::now($tz)];
        if ($from->diffInDays($to) > 366) {
            throw new ApiException('Choose a range of at most one year.', 422, 'range_too_long');
        }

        return response()->json(['data' => $this->analytics->lifecycleSeries($current->webshop()->id, $from, $to)]);
    }

    public function overview(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $data = $request->validate(['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $tz = (string) config('shopbonus.business_timezone');
        $from = CarbonImmutable::parse($data['from'] ?? CarbonImmutable::now($tz)->subDays(29)->format('Y-m-d'), $tz)->startOfDay();
        $to = CarbonImmutable::parse($data['to'] ?? CarbonImmutable::now($tz)->format('Y-m-d'), $tz)->endOfDay();

        return response()->json(['data' => $this->analytics->overview($current->webshop(), $from->utc(), $to->utc()) + ['timezone' => $tz]]);
    }

    /** Chart drill-down: attributed paid orders for this workspace only (Trello #24 §6). */
    public function attributionOrders(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['sometimes', Rule::in(['click', 'voucher', 'view', 'unattributed'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $tz = (string) config('shopbonus.business_timezone');
        $from = CarbonImmutable::parse($data['from'] ?? CarbonImmutable::now($tz)->subDays(29)->format('Y-m-d'), $tz)->startOfDay();
        $to = CarbonImmutable::parse($data['to'] ?? CarbonImmutable::now($tz)->format('Y-m-d'), $tz)->endOfDay();

        return response()->json($this->analytics->attributionOrders(
            $current->webshop(),
            $from->utc(),
            $to->utc(),
            $data['type'] ?? null,
            (int) ($data['per_page'] ?? 25),
            (int) ($data['page'] ?? 1),
        ) + ['timezone' => $tz, 'generated_at' => now()->toIso8601String()]);
    }

    public function notifications(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $data = $request->validate(['unread' => ['sometimes', 'boolean']]);
        $query = MerchantNotification::where('webshop_id', $current->webshop()->id)->when(filter_var($data['unread'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->whereNull('read_at'));
        $page = $query->orderByDesc('id')->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn ($n) => self::notification($n))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'unread' => MerchantNotification::where('webshop_id', $current->webshop()->id)->whereNull('read_at')->count()],
        ]);
    }

    public function read(Request $request, CurrentWorkspace $current, string $notification): JsonResponse
    {
        $n = MerchantNotification::where('public_id', $notification)->where('webshop_id', $current->webshop()->id)->first() ?? throw new ApiException('Notification not found.', 404, 'not_found');
        if ($n->read_at === null) {
            $n->forceFill(['read_at' => now(), 'read_by' => $current->membership()->user_id])->save();
        }

        app(NotificationInbox::class)->readLegacy($request->user(), [$n->id]);

        return response()->json(['data' => self::notification($n)]);
    }

    public function readAll(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $legacyIds = MerchantNotification::where('webshop_id', $current->webshop()->id)->pluck('id')->all();
        app(NotificationInbox::class)->readLegacy($request->user(), $legacyIds);
        $count = MerchantNotification::where('webshop_id', $current->webshop()->id)->whereNull('read_at')
            ->update(['read_at' => now(), 'read_by' => $current->membership()->user_id, 'updated_at' => now()]);

        return response()->json(['data' => ['marked_read' => $count, 'unread' => 0]]);
    }

    /** @return array<string, mixed> */
    public static function notification(MerchantNotification $n): array
    {
        return ['id' => $n->public_id, 'type' => $n->type, 'severity' => $n->severity, 'title' => $n->title, 'body' => $n->body,
            'subject' => $n->subject_type === null ? null : ['type' => $n->subject_type, 'id' => $n->subject_ref],
            'read' => $n->read_at !== null, 'created_at' => $n->created_at->toIso8601String()];
    }

    private function greeting(): string
    {
        $hour = (int) CarbonImmutable::now((string) config('shopbonus.business_timezone'))->format('G');

        return match (true) {
            $hour < 12 => 'good_morning',
            $hour < 18 => 'good_afternoon',
            default => 'good_evening',
        };
    }
}
