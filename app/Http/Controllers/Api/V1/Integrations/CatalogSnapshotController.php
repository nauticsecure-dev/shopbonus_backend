<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Enums\AuditCategory;
use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Integrations\ConnectorRegistry;
use App\Integrations\Connectors\OpenCartConnector;
use App\Integrations\Exceptions\InvalidWebhook;
use App\Jobs\SyncConnectionCatalog;
use App\Models\CatalogSnapshotPage;
use App\Models\WebshopConnection;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\CatalogSynchronizer;
use App\Support\ExactJson;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Signed catalog snapshot pages pushed by the ShopBonus OpenCart extension worker (D-110 push design).
 * Same URL token, HMAC signature and timestamp tolerance as the order webhook; topic `catalog.snapshot`.
 * A snapshot counts only when pages 1..N all arrived and page N is marked last; it then starts the
 * normal catalog sync, which reads it through OpenCartConnector::catalogPage().
 */
class CatalogSnapshotController extends Controller
{
    private const MAX_ITEMS = 100;

    private const MAX_PAGES = 500;

    public function __construct(private readonly ConnectorRegistry $registry, private readonly AuditLogger $audit) {}

    public function receive(Request $request, string $platform, string $token): JsonResponse
    {
        $connection = $platform === EcommercePlatform::OpenCart->value ? WebshopConnection::where('webhook_token', $token)->first() : null;
        if ($connection === null || $connection->platform !== EcommercePlatform::OpenCart || ! $connection->status->receivesEvents()) {
            throw new NotFoundHttpException;
        }
        $connector = $this->registry->for($connection->platform);
        if (! $connector instanceof OpenCartConnector) {
            throw new NotFoundHttpException;
        }
        try {
            $connector->verifySignature($connection, $request);
            if ($request->header('X-Shopbonus-Topic') !== 'catalog.snapshot') {
                throw new InvalidWebhook('invalid_event_headers');
            }
        } catch (InvalidWebhook $e) {
            throw new ApiException('The webhook could not be verified.', 401, 'webhook_rejected', ['reason' => $e->reason]);
        }
        $page = $this->parse($request->getContent());

        return DB::transaction(function () use ($connection, $page) {
            $current = WebshopConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            if (! $current->status->receivesEvents()) {
                throw new NotFoundHttpException;
            }
            if ($current->paused_at !== null) {
                throw new ApiException('This integration is paused. Retry after it resumes.', 503, 'connection_paused', headers: ['Retry-After' => '60']);
            }
            if (! $connection->hasSameCredentialAuthority($current)) {
                throw ApiException::conflict('The connection changed while accepting the event. Retry with its current configuration.', 'connection_changed');
            }
            $key = ['connection_id' => $current->id, 'kind' => $page['kind'], 'snapshot_id' => $page['snapshot_id'], 'page' => $page['page']];
            $existing = CatalogSnapshotPage::where($key)->first();
            if ($existing !== null) {
                // Redelivery of the same page is fine; different content under the same identity is not.
                if ($existing->items !== $page['items'] || $existing->last !== $page['last'] || $existing->currency !== $page['currency']) {
                    throw ApiException::conflict('This snapshot page was already received with different content.', 'catalog_snapshot_conflict');
                }

                return response()->json(['data' => ['received' => true, 'duplicate' => true]]);
            }
            CatalogSnapshotPage::create($key + ['credential_generation' => $current->credential_generation, 'last' => $page['last'],
                'currency' => $page['currency'], 'items' => $page['items'], 'received_at' => now()]);
            $current->forceFill(['last_event_at' => now()])->save();

            $complete = CatalogSnapshotPage::latestComplete($current->id, $current->credential_generation, $page['kind']) === $page['snapshot_id'];
            if ($complete) {
                // Older snapshots of this kind are no longer needed once a newer one is complete.
                CatalogSnapshotPage::where('connection_id', $current->id)->where('kind', $page['kind'])->where('snapshot_id', '!=', $page['snapshot_id'])->delete();
                $this->audit->record('integration.catalog_snapshot_received', category: AuditCategory::Integration, resource: $current, webshopId: $current->webshop_id,
                    metadata: ['kind' => $page['kind'], 'snapshot' => $page['snapshot_id'], 'pages' => $page['page']]);
                try {
                    SyncConnectionCatalog::dispatch(app(CatalogSynchronizer::class)->start($current, $page['kind'], null)->id);
                } catch (ApiException $e) {
                    // A sync of this kind is already running; it or the next snapshot picks this one up.
                    report($e);
                }
            }

            return response()->json(['data' => ['received' => true, 'complete' => $complete]], 202);
        });
    }

    /**
     * @return array{kind: string, snapshot_id: string, page: int, last: bool, currency: string, items: list<array<string,mixed>>}
     */
    private function parse(string $body): array
    {
        try {
            $p = strlen($body) <= 2_000_000 ? ExactJson::decode($body) : null;
        } catch (\JsonException) {
            $p = null;
        }
        $items = is_array($p) ? ($p['items'] ?? null) : null;
        $valid = is_array($p) && ($p['schema'] ?? null) === 'shopbonus.catalog.v1'
            && in_array($p['kind'] ?? null, ['products', 'coupons'], true)
            && is_string($p['snapshot_id'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/D', $p['snapshot_id'])
            && is_int($p['page'] ?? null) && $p['page'] >= 1 && $p['page'] <= self::MAX_PAGES
            && is_bool($p['last'] ?? null) && Money::isCurrency($p['currency'] ?? null)
            && is_array($items) && array_is_list($items) && count($items) <= self::MAX_ITEMS
            && array_filter($items, fn ($row) => ! is_array($row) || array_is_list($row)) === [];
        if (! $valid) {
            throw new ApiException('The catalog snapshot page is invalid.', 422, 'catalog_snapshot_invalid');
        }

        return ['kind' => $p['kind'], 'snapshot_id' => $p['snapshot_id'], 'page' => $p['page'], 'last' => $p['last'], 'currency' => $p['currency'], 'items' => $items];
    }
}
