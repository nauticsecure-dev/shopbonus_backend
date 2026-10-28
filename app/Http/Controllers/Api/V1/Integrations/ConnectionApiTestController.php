<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Enums\ConnectionStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Integrations\ConnectorRegistry;
use App\Models\WebshopConnection;
use App\Services\Audit\AuditLogger;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ConnectionApiTestController extends Controller
{
    public function __invoke(Request $request, CurrentWorkspace $current, string $connection, ConnectorRegistry $registry, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['order_id' => ['nullable', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D']]);
        $model = Str::isUlid($connection) ? WebshopConnection::where('webshop_id', $current->webshop()->id)->where('public_id', strtolower($connection))->first() : null;
        abort_if($model === null, 404);
        if ($model->status === ConnectionStatus::Disconnected) {
            throw ApiException::conflict('This connection is disconnected. Create a new one.', 'connection_disconnected');
        }
        $fingerprint = $model->getRawOriginal('credentials');
        $connector = $registry->for($model->platform);
        $summary = null;
        try {
            if (isset($data['order_id'])) {
                $summary = $connector->normalizeOrder($connector->fetchOrder($model, $data['order_id']))->summary();
                $result = ['ok' => true, 'code' => 'api_order_and_refunds_read_observed'];
            } else {
                $result = $connector->testConnection($model);
            }
        } catch (ApiException $e) {
            $audit->recordSafely('integration.api_test_failed', outcome: AuditOutcome::Failure, category: AuditCategory::Integration,
                resource: $model, webshopId: $model->webshop_id, metadata: ['code' => $e->errorCode], httpStatus: $e->status);
            throw $e;
        }
        $fresh = $model->fresh();
        if ($fresh === null || $fresh->status === ConnectionStatus::Disconnected || $fresh->getRawOriginal('credentials') !== $fingerprint) {
            throw ApiException::conflict('The connection changed during the API test. Retry with its current configuration.', 'connection_changed');
        }
        $audit->record('integration.api_tested', category: AuditCategory::Integration, resource: $model, webshopId: $model->webshop_id,
            metadata: ['code' => $result['code'], 'order_observed' => $summary !== null]);

        return response()->json(['data' => ['connection' => $model->public_id, 'platform' => $model->platform->value,
            'result' => $result, 'order_summary' => $summary, 'state_changed' => false, 'production_verified' => false]], 200, ['Cache-Control' => 'no-store']);
    }
}
