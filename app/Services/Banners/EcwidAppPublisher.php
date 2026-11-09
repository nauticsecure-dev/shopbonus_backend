<?php

namespace App\Services\Banners;

use App\Enums\AuditCategory;
use App\Enums\EcommercePlatform;
use App\Enums\WorkspacePermission;
use App\Exceptions\ApiException;
use App\Integrations\Connectors\EcwidConnector;
use App\Integrations\Connectors\LightspeedEConnector;
use App\Integrations\Http\EcwidAppStorage;
use App\Models\AuditEvent;
use App\Models\BannerCampaign;
use App\Models\User;
use App\Models\WebshopConnection;
use App\Models\WebshopMembership;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Ecwid / Lightspeed E-Series native banner delivery. The registered ShopBonus Ecwid app's customJsUrl
 * (/embed/ecwid-app-v1.js) runs on every storefront that installed it and reads this store's app-storage
 * `public` config via Ecwid.getAppPublicConfig. Publication = one frozen config value written with an
 * intent first and read back; POST overwrites, so a lost response is resolved by read-back, never guessed.
 * Removal deletes it and confirms absence. A configured value is not proof of browser delivery.
 */
class EcwidAppPublisher
{
    public const SCHEMA = 'shopbonus.ecwid-app.v1';

    public function __construct(private readonly EcwidAppStorage $api, private readonly AuditLogger $audit) {}

    /** @return array<string,mixed> */
    public function install(WebshopConnection $connection, User $actor): array
    {
        $this->authorize($connection, $actor);
        if (! config('integrations.ecwid_banners.enabled')) {
            throw ApiException::conflict('Native banner writes are disabled.', 'native_banner_disabled');
        }
        if (! is_string(config('integrations.ecwid_banners.client_id')) || ! preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', config('integrations.ecwid_banners.client_id'))) {
            throw ApiException::conflict('Register the ShopBonus Ecwid app and configure its client ID first.', 'native_banner_app_unconfigured');
        }
        $this->connector($connection)->testConnection($connection); // verified store identity, owned domain, API plan
        $shop = $connection->webshop;
        if (! $shop->status->isCommerciallyActive() || ! BannerCampaign::where('webshop_id', $shop->id)->where('status', 'live')->exists()) {
            throw ApiException::conflict('Publish an eligible funded campaign before installing native delivery.', 'native_banner_no_live_campaign');
        }
        $installation = app(BannerService::class)->installation($shop);
        if (! $installation->allows('https://'.$shop->verified_domain)) {
            throw ApiException::conflict('Allow the verified storefront origin first.', 'native_banner_origin_unavailable');
        }
        $origin = self::origin();
        $context = ['generation' => $connection->credential_generation, 'store' => $connection->external_shop_ref, 'domain' => $shop->verified_domain,
            'public_key' => $installation->public_key, 'value' => self::value($origin, $installation->public_key)];
        $context['digest'] = hash('sha256', json_encode($context, JSON_THROW_ON_ERROR));

        $plan = DB::transaction(function () use ($connection, $context, $actor) {
            $this->current($connection, $actor);
            $existing = $this->latestPlan($connection);
            if ($existing !== null && ! $this->has($existing, 'removed')) {
                if (($existing->metadata['digest'] ?? null) !== $context['digest']) {
                    throw ApiException::conflict('Remove or reconcile the previous frozen installation before changing it.', 'native_banner_plan_changed');
                }
                if ($this->has($existing, 'removing')) {
                    throw ApiException::conflict('Finish removal before installing another native publication.', 'native_banner_removing');
                }

                return $existing;
            }

            return $this->audit->record('integration.banner_ecwid_plan', category: AuditCategory::Integration, resource: $connection, actorUserId: $actor->id, webshopId: $connection->webshop_id, metadata: $context);
        });
        if (! $this->has($plan, 'config_observed')) {
            $remote = $this->api->get($connection);
            // Never overwrite a value this plan did not write (e.g. another or earlier configuration).
            if ($remote !== null && $remote !== $plan->metadata['value']) {
                throw ApiException::conflict('The app public config holds a different value. Reconcile or remove it first.', 'native_banner_remote_mismatch');
            }
            if ($remote !== $plan->metadata['value']) {
                DB::transaction(function () use ($connection, $actor, $plan) {
                    $this->current($connection, $actor);
                    $this->bound($connection, $plan);
                    $this->record($connection, $actor, $plan, 'config_intent');
                });
                $this->api->put($connection, $plan->metadata['value']);
            }
            if ($this->api->get($connection) !== $plan->metadata['value']) {
                throw ApiException::conflict('The app public config write is not yet observable. Reconcile.', 'native_banner_outcome_unknown');
            }
            DB::transaction(function () use ($connection, $actor, $plan) {
                $this->current($connection, $actor);
                $this->bound($connection, $plan);
                if ($this->has($plan, 'removing')) {
                    throw ApiException::conflict('Native removal has started.', 'native_banner_removing');
                }
                if (! $this->has($plan, 'config_observed')) {
                    $this->record($connection, $actor, $plan, 'config_observed');
                }
            });
        }

        return $this->reconcile($connection, $actor);
    }

    /** @return array<string,mixed> */
    public function reconcile(WebshopConnection $connection, User $actor): array
    {
        $this->authorize($connection, $actor);
        $plan = $this->latestPlan($connection) ?? throw ApiException::conflict('No native publication plan exists.', 'native_banner_plan_missing');
        $this->bound($connection, $plan, allowRotated: true);
        if ($this->has($plan, 'removed') || $this->has($plan, 'removing')) {
            return ['plan' => $plan->id, 'state' => $this->has($plan, 'removed') ? 'removed' : 'removing', 'storefront_delivery' => 'not_observed'];
        }
        $remote = $this->api->get($connection);
        if ($remote === null) {
            return ['plan' => $plan->id, 'state' => $this->has($plan, 'config_observed') ? 'remote_missing' : 'incomplete_or_uncertain', 'step' => 'config', 'storefront_delivery' => 'not_observed'];
        }
        if ($remote !== $plan->metadata['value']) {
            throw ApiException::conflict('The app public config does not match its frozen ownership/configuration.', 'native_banner_remote_mismatch');
        }

        return ['plan' => $plan->id, 'state' => $this->has($plan, 'config_observed') ? 'native_configured' : 'incomplete_or_uncertain', 'storefront_delivery' => 'not_observed', 'automatic_campaign_updates' => true];
    }

    /** @return array<string,mixed> */
    public function remove(WebshopConnection $connection, User $actor): array
    {
        $this->authorize($connection, $actor);
        $plan = $this->latestPlan($connection) ?? throw ApiException::conflict('No native publication plan exists.', 'native_banner_plan_missing');
        $this->bound($connection, $plan, allowRotated: true);
        if ($this->has($plan, 'removed')) {
            return ['plan' => $plan->id, 'state' => 'removed', 'storefront_delivery' => 'not_observed'];
        }
        DB::transaction(function () use ($connection, $actor, $plan) {
            $this->current($connection, $actor, allowRotated: true);
            if (! $this->has($plan, 'removing')) {
                $this->record($connection, $actor, $plan, 'removing');
            }
        });
        $remote = $this->api->get($connection);
        if ($remote !== null) {
            if ($remote !== $plan->metadata['value']) {
                throw ApiException::conflict('The app public config is not owned by this plan; it was not deleted.', 'native_banner_remote_mismatch');
            }
            $this->record($connection, $actor, $plan, 'config_delete_intent');
            $this->api->delete($connection);
            if ($this->api->get($connection) !== null) {
                throw ApiException::conflict('Native deletion is not yet confirmed.', 'native_banner_outcome_unknown');
            }
            $this->record($connection, $actor, $plan, 'config_deleted');
        }
        $this->record($connection, $actor, $plan, 'removed');

        return ['plan' => $plan->id, 'state' => 'removed', 'storefront_delivery' => 'not_observed'];
    }

    /** Exact JSON string the storefront app script validates. */
    public static function value(string $origin, string $publicKey): string
    {
        return json_encode(['schema' => self::SCHEMA, 'embed' => $origin.'/embed/v1.js', 'public_key' => $publicKey], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function origin(): string
    {
        $origin = rtrim((string) config('app.url'), '/');
        $parts = parse_url($origin);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['path'])) {
            throw ApiException::conflict('Configure a public HTTPS application origin first.', 'native_banner_app_origin_invalid');
        }

        return $origin;
    }

    private function connector(WebshopConnection $connection): EcwidConnector
    {
        return $connection->platform === EcommercePlatform::LightspeedE ? app(LightspeedEConnector::class) : app(EcwidConnector::class);
    }

    private function authorize(WebshopConnection $connection, User $actor): void
    {
        $u = User::find($actor->id);
        $m = WebshopMembership::where('webshop_id', $connection->webshop_id)->where('user_id', $actor->id)->first();
        if (! $u?->isActive() || (! $u->isSuperAdmin() && ($m === null || ! $m->allows(WorkspacePermission::IntegrationsManage) || ! $m->allows(WorkspacePermission::SettingsManage)))) {
            throw new ApiException('Current native publication permissions are required.', 403, 'native_banner_forbidden');
        }
        if (! in_array($connection->platform, [EcommercePlatform::Ecwid, EcommercePlatform::LightspeedE], true)) {
            throw new \LogicException('EcwidAppPublisher only serves Ecwid / Lightspeed E-Series connections.');
        }
    }

    private function current(WebshopConnection $snapshot, User $actor, bool $allowRotated = false): WebshopConnection
    {
        $c = WebshopConnection::with('webshop')->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        $this->authorize($c, $actor);
        if (! $c->status->receivesEvents() || $c->paused_at !== null || ! $c->webshop->isOwnershipVerified()
            || $c->external_shop_ref !== $snapshot->external_shop_ref || $c->webshop->verified_domain !== $snapshot->webshop->verified_domain
            || (! $allowRotated && ! $snapshot->hasSameCredentialAuthority($c))) {
            throw ApiException::conflict('Native publication authority changed.', 'connection_changed');
        }

        return $c;
    }

    private function bound(WebshopConnection $connection, AuditEvent $plan, bool $allowRotated = false): void
    {
        $m = $plan->metadata;
        if ((! $allowRotated && $m['generation'] !== $connection->credential_generation) || $m['store'] !== $connection->external_shop_ref || $m['domain'] !== $connection->webshop->verified_domain
            || $m['public_key'] !== app(BannerService::class)->installation($connection->webshop)->public_key) {
            throw ApiException::conflict('The frozen native publication binding changed.', 'native_banner_plan_changed');
        }
    }

    private function latestPlan(WebshopConnection $connection): ?AuditEvent
    {
        return AuditEvent::where('resource_type', $connection->getMorphClass())->where('resource_id', (string) $connection->id)->where('action', 'integration.banner_ecwid_plan')->latest('id')->first();
    }

    private function has(AuditEvent $plan, string $suffix): bool
    {
        return $this->events($plan, $suffix)->exists();
    }

    /** @return Builder<AuditEvent> */
    private function events(AuditEvent $plan, string $suffix): Builder
    {
        return AuditEvent::where('action', 'integration.banner_ecwid_'.$suffix)->where('metadata->plan_id', $plan->id);
    }

    /** @param array<string,mixed> $extra */
    private function record(WebshopConnection $connection, User $actor, AuditEvent $plan, string $suffix, array $extra = []): AuditEvent
    {
        return $this->audit->record('integration.banner_ecwid_'.$suffix, category: AuditCategory::Integration, resource: $connection, actorUserId: $actor->id, webshopId: $connection->webshop_id, metadata: ['plan_id' => $plan->id] + $extra);
    }
}
