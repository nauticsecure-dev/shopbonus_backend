<?php

namespace App\Http\Resources;

use App\Integrations\CapabilityMatrix;
use App\Integrations\ConnectorRegistry;
use App\Models\WebshopConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A webshop connection. Credentials are never included; generated secrets
 * are returned only by create/replace-credentials, under `secrets`.
 *
 * @mixin WebshopConnection
 */
class ConnectionResource extends JsonResource
{
    /** @var array<string, string> */
    private array $secrets = [];

    private bool $withSetup = false;

    /**
     * @param  array<string, string>  $secrets
     */
    public function withSecrets(array $secrets): static
    {
        $this->secrets = $secrets;

        return $this;
    }

    public function withSetup(): static
    {
        $this->withSetup = true;

        return $this;
    }

    public static function webhookUrl(WebshopConnection $connection): string
    {
        return rtrim((string) config('app.url'), '/')."/api/v1/webhooks/{$connection->platform->value}/{$connection->webhook_token}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WebshopConnection $c */
        $c = $this->resource;

        $data = [
            'id' => $c->public_id,
            'platform' => $c->platform->value,
            'method' => $c->method->value,
            'status' => $c->status->value,
            'paused_at' => $c->paused_at?->toIso8601String(),
            'external_shop_ref' => $c->external_shop_ref,
            'limitations' => $c->limitations ?? [],
            'last_event_at' => $c->last_event_at?->toIso8601String(),
            'last_verified_order_at' => $c->last_verified_order_at?->toIso8601String(),
            'connected_at' => $c->connected_at?->toIso8601String(),
            'disconnected_at' => $c->disconnected_at?->toIso8601String(),
            'last_error' => $c->last_error_code === null ? null : ['code' => $c->last_error_code, 'at' => $c->last_error_at?->toIso8601String()],
            'created_at' => $c->created_at->toIso8601String(),
        ];

        if ($this->withSetup && $c->status->receivesEvents()) {
            $data['setup'] = app(ConnectorRegistry::class)->for($c->platform)->setupInstructions($c, self::webhookUrl($c));
            // Provider instructions are English source text; localize each step for the request locale.
            if (is_array($data['setup']['steps'] ?? null)) {
                $data['setup']['steps'] = array_map(fn ($step) => is_string($step) ? __($step) : $step, $data['setup']['steps']);
            }
            if (is_string($data['setup']['native_notice'] ?? null)) {
                $data['setup']['native_notice'] = __($data['setup']['native_notice']);
            }
            $data['capabilities'] = app(CapabilityMatrix::class)->forConnection($c);
        }

        if ($this->secrets !== []) {
            $data['secrets'] = $this->secrets;
            $data['secrets_notice'] = __('Shown only once. Store it in your shop settings now; it cannot be retrieved later.');
        }

        return $data;
    }
}
