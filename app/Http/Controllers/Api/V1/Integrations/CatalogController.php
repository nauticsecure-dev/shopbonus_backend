<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Integrations\CapabilityMatrix;
use App\Integrations\ConnectorRegistry;
use App\Models\CatalogCoupon;
use App\Models\CatalogProduct;
use App\Models\WebshopConnection;
use App\Services\Catalog\CatalogSync;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CatalogController extends Controller
{
    public function __construct(
        private readonly CatalogSync $sync,
        private readonly CapabilityMatrix $capabilities,
        private readonly ConnectorRegistry $registry,
    ) {}

    public function capabilities(CurrentWorkspace $current, string $connection): JsonResponse
    {
        $model = $this->find($current, $connection);

        return response()->json([
            'data' => [
                'platform' => $model->platform->value,
                'status' => $model->status->value,
                'capabilities' => $this->capabilities->forConnection($model),
            ],
        ]);
    }

    public function products(CurrentWorkspace $current, string $connection): JsonResponse
    {
        $model = $this->find($current, $connection);
        $rows = CatalogProduct::where('connection_id', $model->id)->orderByDesc('synced_at')->limit(200)->get()
            ->map(fn (CatalogProduct $p) => [
                'id' => $p->public_id,
                'external_product_id' => $p->external_product_id,
                'sku' => $p->sku,
                'name' => $p->name,
                'description' => $p->description,
                'product_url' => $p->product_url,
                'image_url' => $p->image_url,
                'category' => $p->category,
                'price_minor' => $p->price_minor,
                'currency' => $p->currency,
                'availability' => $p->availability,
                'updated_at' => $p->provider_updated_at?->toIso8601String(),
                'synced_at' => $p->synced_at->toIso8601String(),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function coupons(CurrentWorkspace $current, string $connection): JsonResponse
    {
        $model = $this->find($current, $connection);
        $rows = CatalogCoupon::where('connection_id', $model->id)->orderByDesc('synced_at')->limit(200)->get()
            ->map(fn (CatalogCoupon $c) => [
                'id' => $c->public_id,
                'external_coupon_id' => $c->external_coupon_id,
                'code' => $c->code,
                'discount_type' => $c->discount_type,
                'discount_value' => $c->discount_value,
                'minimum_spend' => $c->minimum_spend,
                'excluded_categories' => $c->excluded_categories,
                'start_at' => $c->start_at?->toIso8601String(),
                'end_at' => $c->end_at?->toIso8601String(),
                'usage_limits' => $c->usage_limits,
                'stacking_rules' => $c->stacking_rules,
                'status' => $c->status,
                'eligible_public' => $c->eligible_public,
                'synced_at' => $c->synced_at->toIso8601String(),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function syncProducts(Request $request, CurrentWorkspace $current, string $connection): JsonResponse
    {
        $data = $request->validate(['cursor' => ['sometimes', 'nullable', 'string', 'max:64']]);
        $result = $this->sync->syncProducts($this->find($current, $connection), $data['cursor'] ?? null);

        return response()->json(['data' => [
            'run_id' => $result['run']->public_id,
            'imported' => $result['imported'],
            'exhausted' => $result['exhausted'],
            'cursor' => $result['cursor'],
        ]], 202);
    }

    public function syncCoupons(Request $request, CurrentWorkspace $current, string $connection): JsonResponse
    {
        $data = $request->validate(['cursor' => ['sometimes', 'nullable', 'string', 'max:64']]);
        $result = $this->sync->syncCoupons($this->find($current, $connection), $data['cursor'] ?? null);

        return response()->json(['data' => [
            'run_id' => $result['run']->public_id,
            'imported' => $result['imported'],
            'exhausted' => $result['exhausted'],
            'cursor' => $result['cursor'],
        ]], 202);
    }

    public function health(CurrentWorkspace $current, string $connection): JsonResponse
    {
        $model = $this->find($current, $connection);
        $connector = $this->registry->for($model->platform);
        $health = method_exists($connector, 'getSyncHealth')
            ? $connector->getSyncHealth($model)
            : ['healthy' => false, 'last_success_at' => null, 'lag_seconds' => null, 'error' => 'unsupported'];

        return response()->json(['data' => [
            'status' => $model->status->value,
            'capabilities' => $this->capabilities->forConnection($model),
            'sync' => [
                'healthy' => (bool) ($health['healthy'] ?? false),
                'last_success_at' => isset($health['last_success_at']) && $health['last_success_at']
                    ? (is_string($health['last_success_at']) ? $health['last_success_at'] : $health['last_success_at']->toIso8601String())
                    : null,
                'lag_seconds' => $health['lag_seconds'] ?? null,
                'error' => $health['error'] ?? null,
            ],
            'products_synced' => CatalogProduct::where('connection_id', $model->id)->count(),
            'coupons_synced' => CatalogCoupon::where('connection_id', $model->id)->count(),
            'eligible_coupons' => CatalogCoupon::where('connection_id', $model->id)->where('eligible_public', true)->count(),
        ]]);
    }

    private function find(CurrentWorkspace $current, string $publicId): WebshopConnection
    {
        $connection = Str::isUlid($publicId)
            ? WebshopConnection::where('webshop_id', $current->webshop()->id)->where('public_id', Str::lower($publicId))->first()
            : null;

        return ($connection ?? throw new NotFoundHttpException)->setRelation('webshop', $current->webshop());
    }
}
