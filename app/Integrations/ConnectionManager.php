<?php

namespace App\Integrations;

use App\Enums\AuditCategory;
use App\Enums\ConnectionStatus;
use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Models\WebshopConnection;
use App\Models\WebshopMembership;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Creates, re-keys and disconnects webshop connections (Trello #1
 * "ConnectionManager"). Saving credentials never makes a connection
 * `connected`; only a verified real order does (ProcessWebhookEvent).
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
        $this->assertNotDisconnected($connection);
        $connector = $this->registry->for($connection->platform);
        $data = Validator::make($input, $connector->connectRules())->validate();

        $connection->credentials = $connector->connect($connection, $data);
        $connection->save();

        $this->audit->record('integration.credentials_replaced', category: AuditCategory::Integration,
            resource: $connection, webshopId: $connection->webshop_id);

        return $this->shownOnce($connector->generatedSecrets(), $connection);
    }

    public function disconnect(WebshopConnection $connection): WebshopConnection
    {
        $this->assertNotDisconnected($connection);
        $this->registry->for($connection->platform)->disconnect($connection);

        $connection->forceFill([
            'status' => ConnectionStatus::Disconnected,
            'credentials' => null,
            'disconnected_at' => now(),
        ])->save();

        $this->audit->record('integration.disconnected', category: AuditCategory::Integration,
            resource: $connection, webshopId: $connection->webshop_id);

        return $connection;
    }

    /**
     * "Verify Connection" checklist (Trello #1): what has actually been
     * observed, not what the merchant has configured.
     *
     * @return array<string, mixed>
     */
    public function checklist(WebshopConnection $connection): array
    {
        $lastOrder = $connection->events()->whereNotNull('result')->where('result->kind', 'order')->latest('id')->first();
        $result = $lastOrder->result ?? [];

        $items = [
            'credentials_configured' => $connection->credentials !== null,
            'event_received' => $connection->last_event_at !== null,
            'order_received' => $lastOrder !== null,
            'mandatory_fields_present' => (bool) ($result['complete'] ?? false),
            'phone_available' => (bool) ($result['has_phone'] ?? false),
            'webshop_ownership_verified' => $connection->webshop->isOwnershipVerified(),
        ];

        return [
            'status' => $connection->status->value,
            'checks' => $items,
            'missing_fields' => $result['missing_fields'] ?? [],
            'limitations' => $connection->limitations ?? [],
            'next_action' => match (true) {
                ! $items['event_received'] => 'configure_webhook',
                ! $items['order_received'] => 'place_test_order',
                ! $items['mandatory_fields_present'] => 'additional_integration_required',
                ! $items['webshop_ownership_verified'] => 'verify_ownership',
                default => null,
            },
        ];
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
