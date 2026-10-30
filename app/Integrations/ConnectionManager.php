<?php

namespace App\Integrations;

use App\Enums\AuditCategory;
use App\Enums\ConnectionStatus;
use App\Enums\EcommercePlatform;
use App\Enums\WebhookEventStatus;
use App\Exceptions\ApiException;
use App\Models\WebshopConnection;
use App\Models\WebshopMembership;
use App\Models\WebshopOrder;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Creates, re-keys and disconnects webshop connections (Trello #1
 * "ConnectionManager"). Saving credentials never makes a connection
 * `connected`; all seven native evidence gates must pass.
 */
class ConnectionManager
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: WebshopConnection, 1: array<string, string>} connection and secrets to show once
     */
    public function create(WebshopMembership $actor, EcommercePlatform $platform, array $input): array
    {
        $connector = $this->registry->for($platform);
        $data = Validator::make($input, $connector->connectRules())->validate();
        $webshop = $actor->webshop;

        return DB::transaction(function () use ($actor, $webshop, $platform, $connector, $data) {
            $exists = WebshopConnection::where('webshop_id', $webshop->id)->where('platform', $platform)
                ->where('status', '!=', ConnectionStatus::Disconnected)->lockForUpdate()->exists();

            if ($exists) {
                throw ApiException::conflict('This webshop already has a connection for this platform.', 'connection_exists');
            }

            $connection = new WebshopConnection([
                'platform' => $platform,
                'method' => $connector->method(),
                'status' => ConnectionStatus::SetupRequired,
                'webhook_token' => Str::random(40),
            ]);
            $connection->webshop_id = $webshop->id;
            $connection->created_by = $actor->user_id;
            $connection->credentials = $connector->connect($connection, $data);
            $connection->save();
            $connection->setRelation('webshop', $webshop);
            app(ShopwareAppRegistration::class)->attach($connection);

            $this->audit->record('integration.connection_created', category: AuditCategory::Integration,
                resource: $connection, webshopId: $webshop->id, metadata: ['platform' => $platform->value]);

            return [$connection, $this->shownOnce($connector->generatedSecrets(), $connection)];
        });
    }

    /**
     * Re-runs the connector's credential setup: generates a new secret
     * (the old one stops working immediately) or stores merchant-supplied ones.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string> secrets to show once
     */
    public function replaceCredentials(WebshopConnection $connection, array $input): array
    {
        $connector = $this->registry->for($connection->platform);
        $data = Validator::make($input, $connector->connectRules())->validate();

        return DB::transaction(function () use ($connection, $connector, $data) {
            $current = WebshopConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $this->assertNotDisconnected($current);
            $originalStore = $current->external_shop_ref;
            $current->credentials = $connector->connect($current, $data);
            if ($originalStore !== null && $current->external_shop_ref !== $originalStore
                && WebshopOrder::where('webshop_id', $current->webshop_id)->where('platform', $current->platform->value)->exists()) {
                throw ApiException::conflict('Changing the provider store after orders exist requires a reviewed migration.', 'provider_store_change_requires_review');
            }
            $current->forceFill([
                'credential_generation' => $current->credential_generation + 1,
                'status' => ConnectionStatus::SetupRequired, 'limitations' => null,
                'connected_at' => null, 'last_event_at' => null, 'last_verified_order_at' => null,
                'last_error_code' => null, 'last_error_at' => null,
            ])->save();
            app(ShopwareAppRegistration::class)->attach($current);
            $this->audit->record('integration.credentials_replaced', category: AuditCategory::Integration,
                resource: $current, webshopId: $current->webshop_id);
            $connection->refresh();

            return $this->shownOnce($connector->generatedSecrets(), $current);
        });
    }

    public function disconnect(WebshopConnection $connection): WebshopConnection
    {
        return DB::transaction(function () use ($connection) {
            $current = WebshopConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $this->assertNotDisconnected($current);
            $this->registry->for($current->platform)->disconnect($current);
            $current->forceFill([
                'credential_generation' => $current->credential_generation + 1,
                'status' => ConnectionStatus::Disconnected, 'credentials' => null,
                'disconnected_at' => now(), 'connected_at' => null,
                'last_event_at' => null, 'last_verified_order_at' => null,
            ])->save();
            $this->audit->record('integration.disconnected', category: AuditCategory::Integration,
                resource: $current, webshopId: $current->webshop_id);

            return $connection->refresh();
        });
    }

    /**
     * "Verify Connection" checklist (Trello #1): what has actually been
     * observed, not what the merchant has configured.
     *
     * @return array<string, mixed>
     */
    public function checklist(WebshopConnection $connection): array
    {
        return DB::transaction(function () use ($connection) {
            $connection = WebshopConnection::with('webshop')->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $currentEvents = $connection->events()->where('credential_generation', $connection->credential_generation);
            $lastOrder = (clone $currentEvents)->where('status', WebhookEventStatus::Processed)->whereNotNull('result')->where('result->kind', 'order')->latest('id')->first();
            $result = $lastOrder->result ?? [];

            $items = [
                'credentials_configured' => $connection->credentials !== null,
                'event_received' => $currentEvents->exists(),
                'order_received' => $lastOrder !== null,
                'mandatory_fields_present' => (bool) ($result['complete'] ?? false),
                'phone_available' => (bool) ($result['has_phone'] ?? false),
                'webshop_ownership_verified' => $connection->webshop->isOwnershipVerified(),
            ];

            $gates = app(ConnectionReadiness::class)->gates($connection);

            return [
                'status' => $connection->status->value,
                'quality_gates' => $gates,
                'missing_gates' => array_keys(array_filter($gates, fn ($passed) => ! $passed)),
                'checks' => $items,
                'missing_fields' => $result['missing_fields'] ?? [],
                'limitations' => $connection->limitations ?? [],
                'next_action' => match (true) {
                    ! $items['event_received'] => 'configure_webhook',
                    ! $items['order_received'] => 'place_test_order',
                    ! $items['mandatory_fields_present'] => 'additional_integration_required',
                    ! $items['webshop_ownership_verified'] => 'verify_ownership',
                    in_array(false, $gates, true) => 'complete_connection_verification',
                    default => null,
                },
            ];
        });
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function shownOnce(array $keys, WebshopConnection $connection): array
    {
        return array_filter(array_combine($keys, array_map(fn (string $k) => (string) $connection->credential($k), $keys)));
    }

    private function assertNotDisconnected(WebshopConnection $connection): void
    {
        if ($connection->status === ConnectionStatus::Disconnected) {
            throw ApiException::conflict('This connection is disconnected. Create a new one.', 'connection_disconnected');
        }
    }
}
