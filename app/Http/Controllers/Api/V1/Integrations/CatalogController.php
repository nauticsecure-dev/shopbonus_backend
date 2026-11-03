<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Jobs\SyncConnectionCatalog;
use App\Models\CatalogSync;
use App\Models\ConnectionCoupon;
use App\Models\ConnectionProduct;
use App\Models\WebshopConnection;
use App\Services\Catalog\CatalogSynchronizer;
use App\Services\Catalog\ConnectionCapabilities;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read-only catalog / native coupon snapshots and the capability report of
 * one workspace connection (Trello #1 §3–4, #32 §5).
 */
class CatalogController extends Controller
{
    public function capabilities(CurrentWorkspace $current, string $connection, ConnectionCapabilities $capabilities): JsonResponse
    {
        return response()->json(['data' => $capabilities->report($this->find($current, $connection))]);
    }

    /**
     * Merchant App Store health panel (FE: GET …/connections/{id}/health).
     * Counts come from ConnectionProduct / ConnectionCoupon snapshots — never invents VERIFIED.
     */
    public function health(CurrentWorkspace $current, string $connection, ConnectionCapabilities $capabilities): JsonResponse
    {
        $c = $this->find($current, $connection);
        $products = ConnectionProduct::where('connection_id', $c->id)->whereNull('removed_at')->count();
        $coupons = ConnectionCoupon::where('connection_id', $c->id)->whereNull('removed_at')->count();
        $eligible = ConnectionCoupon::where('connection_id', $c->id)->whereNull('removed_at')->get()->filter(fn (ConnectionCoupon $row) => $row->isActive())->count();
        $last = CatalogSync::where('connection_id', $c->id)->whereIn('status', ['completed', 'partial'])
            ->where('credential_generation', $c->credential_generation)->latest('id')->first();
        $failed = CatalogSync::where('connection_id', $c->id)->where('status', 'failed')
            ->where('credential_generation', $c->credential_generation)->latest('id')->first();
        $caps = [];
        foreach ($capabilities->report($c) as $row) {
            $caps[$row['capability']] = [
                'state' => $row['status'],
                'reason' => $row['reason'],
                'last_verified_at' => $row['last_verified_at'],
            ];
        }

        return response()->json(['data' => [
            'status' => $c->status->value,
            'capabilities' => $caps,
            'sync' => [
                'healthy' => $failed === null || ($last !== null && $last->id > $failed->id),
                'last_success_at' => $last?->finished_at?->toIso8601String(),
                'lag_seconds' => $last?->finished_at !== null ? max(0, now()->diffInSeconds($last->finished_at)) : null,
                'error' => $failed !== null && ($last === null || $failed->id > $last->id) ? $failed->error_code : null,
            ],
            'products_synced' => $products,
            'coupons_synced' => $coupons,
            'eligible_coupons' => $eligible,
        ]]);
    }

    /**
     * FE alias: POST …/sync/products|coupons → CatalogSynchronizer (CatalogReader path).
     * Runs the pass inline so the App Store can show imported counts without a separate poll.
     */
    public function syncProducts(Request $request, CurrentWorkspace $current, string $connection, CatalogSynchronizer $synchronizer): JsonResponse
    {
        return $this->syncAlias($request, $current, $connection, 'products', $synchronizer);
    }

    public function syncCoupons(Request $request, CurrentWorkspace $current, string $connection, CatalogSynchronizer $synchronizer): JsonResponse
    {
        return $this->syncAlias($request, $current, $connection, 'coupons', $synchronizer);
    }

    private function syncAlias(Request $request, CurrentWorkspace $current, string $connection, string $kind, CatalogSynchronizer $synchronizer): JsonResponse
    {
        $sync = $synchronizer->start($this->find($current, $connection), $kind, $request->user()->id);
        $done = $synchronizer->run($sync->id);

        return response()->json(['data' => [
            'run_id' => $done->public_id,
            'imported' => (int) $done->items,
            'exhausted' => in_array($done->status, ['completed', 'partial'], true),
            'cursor' => null,
            'status' => $done->status,
            'error_code' => $done->error_code,
        ]], 202);
    }

    public function sync(Request $request, CurrentWorkspace $current, string $connection, CatalogSynchronizer $synchronizer): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(CatalogSync::KINDS)]]);
        $sync = $synchronizer->start($this->find($current, $connection), $data['kind'], $request->user()->id);
        SyncConnectionCatalog::dispatch($sync->id);

        return response()->json(['data' => self::presentSync($sync->fresh() ?? $sync)], 202);
    }

    public function syncs(CurrentWorkspace $current, string $connection): JsonResponse
    {
        return response()->json(['data' => CatalogSync::where('connection_id', $this->find($current, $connection)->id)->latest('id')->limit(20)->get()
            ->map(fn (CatalogSync $s) => self::presentSync($s))->all()]);
    }

    public function products(Request $request, CurrentWorkspace $current, string $connection): JsonResponse
    {
        $data = $request->validate(['search' => ['sometimes', 'string', 'max:100'], 'include_removed' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $page = ConnectionProduct::where('connection_id', $this->find($current, $connection)->id)
            ->when(! ($data['include_removed'] ?? false), fn ($q) => $q->whereNull('removed_at'))
            ->when(isset($data['search']), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes($data['search'], '%_\\').'%')->orWhere('sku', $data['search'])))
            ->orderBy('name')->orderBy('id')->paginate(50);

        return response()->json(['data' => collect($page->items())->map(fn (ConnectionProduct $p) => [
            // Canonical + App Store aliases (id / external_product_id / availability / synced_at).
            'id' => $p->external_id, 'external_id' => $p->external_id, 'external_product_id' => $p->external_id,
            'sku' => $p->sku, 'name' => $p->name, 'description' => $p->description, 'url' => $p->url, 'image_url' => $p->image_url,
            'categories' => $p->categories ?? [], 'price_minor' => $p->price_minor, 'regular_price_minor' => $p->regular_price_minor, 'sale_price_minor' => $p->sale_price_minor,
            'currency' => $p->currency, 'type' => $p->product_type, 'status' => $p->status, 'visibility' => $p->visibility,
            'stock_status' => $p->stock_status, 'availability' => $p->stock_status, 'stock_quantity' => $p->stock_quantity,
            'provider_updated_at' => $p->provider_updated_at?->toIso8601String(), 'synced_at' => $p->provider_updated_at?->toIso8601String() ?? $p->updated_at?->toIso8601String(),
            'removed_at' => $p->removed_at?->toIso8601String(),
        ])->all(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function coupons(Request $request, CurrentWorkspace $current, string $connection): JsonResponse
    {
        $data = $request->validate(['include_removed' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $page = ConnectionCoupon::where('connection_id', $this->find($current, $connection)->id)
            ->when(! ($data['include_removed'] ?? false), fn ($q) => $q->whereNull('removed_at'))->orderBy('id')->paginate(50);

        return response()->json(['data' => collect($page->items())->map(fn (ConnectionCoupon $c) => [
            'id' => $c->external_id, 'external_id' => $c->external_id, 'external_coupon_id' => $c->external_id,
            'code' => $c->code, 'discount_type' => $c->discount_type, 'discount_value' => $c->amount ?? ($c->percent_bps !== null ? (string) ($c->percent_bps / 100) : null),
            'amount' => $c->amount, 'amount_minor' => $c->amount_minor,
            'percent_bps' => $c->percent_bps, 'currency' => $c->currency, 'minimum_spend_minor' => $c->minimum_spend_minor, 'maximum_spend_minor' => $c->maximum_spend_minor,
            'usage_limit' => $c->usage_limit, 'usage_limit_per_customer' => $c->usage_limit_per_customer, 'usage_count' => $c->usage_count, 'stackable' => $c->stackable,
            'exclude_sale_items' => $c->exclude_sale_items, 'free_shipping' => $c->free_shipping, 'customer_restricted' => $c->customer_restricted, 'scope' => $c->scope,
            'status' => $c->status, 'eligible_public' => $c->isActive() && ! $c->customer_restricted, 'expires_at' => $c->expires_at?->toIso8601String(), 'active' => $c->isActive(),
            'provider_updated_at' => $c->provider_updated_at?->toIso8601String(), 'synced_at' => $c->provider_updated_at?->toIso8601String() ?? $c->updated_at?->toIso8601String(),
            'removed_at' => $c->removed_at?->toIso8601String(),
        ])->all(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    /** @return array<string, mixed> */
    private static function presentSync(CatalogSync $s): array
    {
        return ['id' => $s->public_id, 'kind' => $s->kind, 'status' => $s->status, 'pages' => $s->pages, 'items' => $s->items, 'removed' => $s->removed,
            'error_code' => $s->error_code, 'started_at' => $s->started_at->toIso8601String(), 'finished_at' => $s->finished_at?->toIso8601String()];
    }

    private function find(CurrentWorkspace $current, string $publicId): WebshopConnection
    {
        $connection = Str::isUlid($publicId)
            ? WebshopConnection::where('webshop_id', $current->webshop()->id)->where('public_id', Str::lower($publicId))->first()
            : null;

        return ($connection ?? throw new NotFoundHttpException)->setRelation('webshop', $current->webshop());
    }
}
