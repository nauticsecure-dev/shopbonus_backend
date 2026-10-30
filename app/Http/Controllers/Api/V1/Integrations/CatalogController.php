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
            'external_id' => $p->external_id, 'sku' => $p->sku, 'name' => $p->name, 'description' => $p->description, 'url' => $p->url, 'image_url' => $p->image_url,
            'categories' => $p->categories ?? [], 'price_minor' => $p->price_minor, 'regular_price_minor' => $p->regular_price_minor, 'sale_price_minor' => $p->sale_price_minor,
            'currency' => $p->currency, 'type' => $p->product_type, 'status' => $p->status, 'visibility' => $p->visibility, 'stock_status' => $p->stock_status,
            'stock_quantity' => $p->stock_quantity, 'provider_updated_at' => $p->provider_updated_at?->toIso8601String(), 'removed_at' => $p->removed_at?->toIso8601String(),
        ])->all(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function coupons(Request $request, CurrentWorkspace $current, string $connection): JsonResponse
    {
        $data = $request->validate(['include_removed' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $page = ConnectionCoupon::where('connection_id', $this->find($current, $connection)->id)
            ->when(! ($data['include_removed'] ?? false), fn ($q) => $q->whereNull('removed_at'))->orderBy('id')->paginate(50);

        return response()->json(['data' => collect($page->items())->map(fn (ConnectionCoupon $c) => [
            'external_id' => $c->external_id, 'code' => $c->code, 'discount_type' => $c->discount_type, 'amount' => $c->amount, 'amount_minor' => $c->amount_minor,
            'percent_bps' => $c->percent_bps, 'currency' => $c->currency, 'minimum_spend_minor' => $c->minimum_spend_minor, 'maximum_spend_minor' => $c->maximum_spend_minor,
            'usage_limit' => $c->usage_limit, 'usage_limit_per_customer' => $c->usage_limit_per_customer, 'usage_count' => $c->usage_count, 'stackable' => $c->stackable,
            'exclude_sale_items' => $c->exclude_sale_items, 'free_shipping' => $c->free_shipping, 'customer_restricted' => $c->customer_restricted, 'scope' => $c->scope,
            'status' => $c->status, 'expires_at' => $c->expires_at?->toIso8601String(), 'active' => $c->isActive(),
            'provider_updated_at' => $c->provider_updated_at?->toIso8601String(), 'removed_at' => $c->removed_at?->toIso8601String(),
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
