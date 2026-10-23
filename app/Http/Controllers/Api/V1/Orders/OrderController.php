<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Enums\AuditCategory;
use App\Enums\EcommercePlatform;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Integrations\Data\PaymentStatus;
use App\Models\OrderEvent;
use App\Models\WebshopOrder;
use App\Services\Audit\AuditLogger;
use App\Services\Workspaces\CurrentWorkspace;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Live Order Sheet API (Trello #1). Every query is scoped to the resolved
 * workspace; another webshop's orders are never reachable.
 */
class OrderController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $filters = $this->filters($request);
        $paginator = $this->query($current, $filters)->paginate($filters['per_page'] ?? 25)->withQueryString();

        return OrderResource::collection($paginator)
            ->additional(['meta' => ['today' => $this->today($current)]])
            ->response()->setStatusCode(200);
    }

    public function show(CurrentWorkspace $current, string $order): JsonResponse
    {
        $model = Str::isUlid($order)
            ? WebshopOrder::where('webshop_id', $current->webshop()->id)->where('public_id', Str::lower($order))->first()
            : null;

        if ($model === null) {
            throw new NotFoundHttpException;
        }

        $history = OrderEvent::where('order_id', $model->id)->orderBy('id')->get()->map(fn (OrderEvent $e) => [
            'type' => $e->type,
            'from_payment_status' => $e->from_payment_status,
            'to_payment_status' => $e->to_payment_status,
            'summary' => $e->summary,
            'provider_updated_at' => $e->provider_updated_at?->toIso8601String(),
            'recorded_at' => $e->created_at->toIso8601String(),
        ]);

        return OrderResource::make($model)->additional(['history' => $history])->response()->setStatusCode(200);
    }

    public function export(Request $request, CurrentWorkspace $current): StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->query($current, $filters);
        $count = (clone $query)->count();

        $this->audit->record('orders.exported', category: AuditCategory::Order, webshopId: $current->webshop()->id,
            metadata: ['rows' => $count, 'filters' => array_diff_key($filters, ['search' => true])]);

        $filename = 'orders-'.Str::slug($current->webshop()->name).'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['placed_at', 'reference', 'platform', 'first_name', 'email', 'phone', 'amount', 'currency',
                'payment_status', 'ingestion_status', 'eligibility_status', 'contact_status'], escape: '');

            $query->chunkById(500, function ($orders) use ($out) {
                foreach ($orders as $o) {
                    fputcsv($out, array_map([self::class, 'csvSafe'], [
                        $o->placed_at?->toIso8601String(), $o->reference, $o->platform, $o->customer_first_name,
                        $o->customer_email, $o->customer_phone,
                        $o->amount_minor === null ? null : self::decimal($o->amount_minor, (string) $o->currency),
                        $o->currency, $o->payment_status->value, $o->ingestion_status, $o->eligibility_status, $o->contact_status,
                    ]), escape: '');
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * Neutralises spreadsheet formula injection (=, +, -, @, tab, CR).
     */
    public static function csvSafe(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private static function decimal(int $minor, string $currency): string
    {
        $exponent = Money::exponent($currency);
        $sign = $minor < 0 ? '-' : '';
        $digits = str_pad((string) abs($minor), $exponent + 1, '0', STR_PAD_LEFT);

        return $exponent === 0 ? $sign.$digits : $sign.substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'payment_status' => ['sometimes', Rule::enum(PaymentStatus::class)],
            'ingestion_status' => ['sometimes', Rule::in(['complete', 'incomplete'])],
            'platform' => ['sometimes', Rule::enum(EcommercePlatform::class)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'search' => ['sometimes', 'string', 'max:255'],
            'sort' => ['sometimes', Rule::in(['placed_at', '-placed_at', 'amount_minor', '-amount_minor'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<WebshopOrder>
     */
    private function query(CurrentWorkspace $current, array $filters): Builder
    {
        $sort = $filters['sort'] ?? '-placed_at';
        $search = isset($filters['search']) ? trim((string) $filters['search']) : null;

        return WebshopOrder::query()
            ->where('webshop_id', $current->webshop()->id)
            ->when($filters['payment_status'] ?? null, fn ($q, $v) => $q->where('payment_status', $v))
            ->when($filters['ingestion_status'] ?? null, fn ($q, $v) => $q->where('ingestion_status', $v))
            ->when($filters['platform'] ?? null, fn ($q, $v) => $q->where('platform', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('placed_at', '>=', CarbonImmutable::parse($v)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('placed_at', '<=', CarbonImmutable::parse($v)->endOfDay()))
            // Customer data is encrypted: search is by reference/external ID prefix or exact email.
            ->when($search !== null && $search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('reference', 'like', addcslashes((string) $search, '%_\\').'%')
                ->orWhere('external_order_id', $search)
                ->orWhere('customer_email_hash', WebshopOrder::emailHash((string) $search))))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('id', str_starts_with($sort, '-') ? 'desc' : 'asc'); // stable tiebreak in the same direction
    }

    /**
     * Today's counters in the business timezone. Amounts are grouped per
     * currency (never summed across currencies); only paid orders count as revenue.
     *
     * @return array<string, mixed>
     */
    private function today(CurrentWorkspace $current): array
    {
        $tz = (string) config('shopbonus.business_timezone', 'Europe/Amsterdam');
        $start = CarbonImmutable::now($tz)->startOfDay()->utc();
        $base = WebshopOrder::where('webshop_id', $current->webshop()->id)->where('placed_at', '>=', $start);

        return [
            'timezone' => $tz,
            'orders' => (clone $base)->count(),
            'paid_orders' => (clone $base)->where('payment_status', PaymentStatus::Paid)->count(),
            'incomplete_orders' => (clone $base)->where('ingestion_status', 'incomplete')->count(),
            'paid_revenue_minor' => (clone $base)->where('payment_status', PaymentStatus::Paid)
                ->selectRaw('currency, sum(amount_minor) as total')->groupBy('currency')->pluck('total', 'currency')
                ->map(fn ($v) => (int) $v)->all(),
        ];
    }
}
