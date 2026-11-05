<?php

namespace App\Services\Banners;

use App\Enums\AuditCategory;
use App\Enums\EcommercePlatform;
use App\Enums\WorkspacePermission;
use App\Exceptions\ApiException;
use App\Integrations\Connectors\LightspeedCConnector;
use App\Integrations\Http\LightspeedScripts;
use App\Models\AuditEvent;
use App\Models\BannerCampaign;
use App\Models\User;
use App\Models\WebshopConnection;
use App\Models\WebshopMembership;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lightspeed C-Series native banner delivery via one owned Shop Script (Trello #31/#32).
 * Same discipline as the BigCommerce publisher: a frozen plan, an intent before every
 * remote write, read-back verification, explicit binding of uncertain creates and
 * confirmed removal. A configured script is not proof of browser delivery.
 */
class LightspeedScriptPublisher
{
    public function __construct(private readonly LightspeedScripts $api, private readonly AuditLogger $audit) {}

    /** @return array<string,mixed> */
    public function install(WebshopConnection $connection, User $actor): array
    {
        $this->authorize($connection, $actor);
        if (! config('integrations.lightspeed_banners.enabled')) {
            throw ApiException::conflict('Native banner writes are disabled.', 'native_banner_disabled');
        }
        app(LightspeedCConnector::class)->testConnection($connection); // verified shop identity and owned domain
        $shop = $connection->webshop;
        if (! $shop->status->isCommerciallyActive() || ! BannerCampaign::where('webshop_id', $shop->id)->where('status', 'live')->exists()) {
            throw ApiException::conflict('Publish an eligible funded campaign before installing native delivery.', 'native_banner_no_live_campaign');
        }
        $installation = app(BannerService::class)->installation($shop);
        if (! $installation->allows('https://'.$shop->verified_domain)) {
            throw ApiException::conflict('Allow the verified storefront origin first.', 'native_banner_origin_unavailable');
        }
        $origin = rtrim((string) config('app.url'), '/');
        $parts = parse_url($origin);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['path'])) {
            throw ApiException::conflict('Configure a public HTTPS application origin first.', 'native_banner_app_origin_invalid');
        }
        $context = ['generation' => $connection->credential_generation, 'store' => $connection->external_shop_ref, 'domain' => $shop->verified_domain,
            'public_key' => $installation->public_key, 'script' => ['body' => self::body($origin.'/embed/v1.js', $installation->public_key), 'location' => 'body']];
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

            return $this->audit->record('integration.banner_ls_plan', category: AuditCategory::Integration, resource: $connection, actorUserId: $actor->id, webshopId: $connection->webshop_id, metadata: $context);
        });
        if ($this->observation($plan) === null) {
            DB::transaction(function () use ($connection, $actor, $plan) {
                $this->current($connection, $actor);
                $this->bound($connection, $plan);
                if ($this->has($plan, 'script_intent')) {
                    throw ApiException::conflict('A shop script create may have succeeded. Bind its verified ID; do not create again.', 'native_banner_outcome_unknown');
                }
                $this->record($connection, $actor, $plan, 'script_intent');
            });
            $created = $this->api->request($connection, 'POST', script: $plan->metadata['script']);
            $id = $created['id'] ?? null;
            // The documented create response does not promise an ID: without one the outcome stays uncertain.
            if (! is_int($id) || $id < 1) {
                throw ApiException::conflict('The shop script create was not confirmed with an ID. Find it in Lightspeed and bind it.', 'native_banner_outcome_unknown');
            }
            $this->verify($plan, $this->api->request($connection, 'GET', $id) ?? throw ApiException::conflict('The shop script is not yet observable.', 'native_banner_outcome_unknown'));
            $this->observe($connection, $actor, $plan, $id);
        }

        return $this->reconcile($connection, $actor);
    }

    /** Explicit recovery of an uncertain create: only a script matching the frozen plan is adopted.
     * @return array<string,mixed>
     */
    public function bind(WebshopConnection $connection, int $id, User $actor): array
    {
        $this->authorize($connection, $actor);
        $plan = $this->latestPlan($connection) ?? throw ApiException::conflict('No native publication plan exists.', 'native_banner_plan_missing');
        $this->bound($connection, $plan);
        if ($id < 1 || ! $this->has($plan, 'script_intent') || $this->observation($plan) !== null) {
            throw ApiException::conflict('Recover only an unresolved matching native create.', 'native_banner_bind_invalid');
        }
        $this->verify($plan, $this->api->request($connection, 'GET', $id) ?? throw ApiException::conflict('The shop script was not found.', 'native_banner_remote_missing'));
        $this->observe($connection, $actor, $plan, $id);

        return ['plan' => $plan->id, 'bound_step' => 'script', 'remote_id' => $id];
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
        $id = $this->observation($plan)?->metadata['script_id'] ?? null;
        if ($id === null) {
            return ['plan' => $plan->id, 'state' => 'incomplete_or_uncertain', 'step' => 'script', 'storefront_delivery' => 'not_observed'];
        }
        $remote = $this->api->request($connection, 'GET', $id);
        if ($remote === null) {
            return ['plan' => $plan->id, 'state' => 'remote_missing', 'step' => 'script', 'storefront_delivery' => 'not_observed'];
        }
        $this->verify($plan, $remote);

        return ['plan' => $plan->id, 'state' => 'native_configured', 'remote_ids' => ['script' => $id], 'storefront_delivery' => 'not_observed', 'automatic_campaign_updates' => true];
    }

    /** @return array<string,mixed> */
    public function remove(WebshopConnection $connection, User $actor): array
    {
        $this->authorize($connection, $actor);
        $plan = $this->latestPlan($connection) ?? throw ApiException::conflict('No native publication plan exists.', 'native_banner_plan_missing');
        $this->bound($connection, $plan, allowRotated: true);
        DB::transaction(function () use ($connection, $actor, $plan) {
            $this->current($connection, $actor, allowRotated: true);
            if ($this->has($plan, 'script_intent') && $this->observation($plan) === null) {
                throw ApiException::conflict('Bind the uncertain shop script before starting removal.', 'native_banner_outcome_unknown');
            }
            if (! $this->has($plan, 'removing')) {
                $this->record($connection, $actor, $plan, 'removing');
            }
        });
        $id = $this->observation($plan)?->metadata['script_id'] ?? null;
        if ($id !== null && ($remote = $this->api->request($connection, 'GET', $id)) !== null) {
            $this->verify($plan, $remote);
            DB::transaction(function () use ($connection, $actor, $plan) {
                $this->current($connection, $actor, allowRotated: true);
                if ($this->has($plan, 'script_delete_intent')) {
                    throw ApiException::conflict('The previous delete remains unconfirmed. Reconcile native absence first.', 'native_banner_outcome_unknown');
                }
                $this->record($connection, $actor, $plan, 'script_delete_intent');
            });
            $this->api->request($connection, 'DELETE', $id);
            if ($this->api->request($connection, 'GET', $id) !== null) {
                throw ApiException::conflict('Native deletion is not yet confirmed.', 'native_banner_outcome_unknown');
            }
        }
        if ($id !== null) {
            $this->record($connection, $actor, $plan, 'script_deleted', ['script_id' => $id]);
        }
        $this->record($connection, $actor, $plan, 'removed');

        return ['plan' => $plan->id, 'state' => 'removed', 'storefront_delivery' => 'not_observed'];
    }

    /** Inline loader: Shop Scripts cannot set attributes, and the embed reads its key from its own tag. */
    public static function body(string $scriptUrl, string $publicKey): string
    {
        return '(function(d){var s=d.createElement("script");s.async=true;s.src='.json_encode($scriptUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).';'
            .'s.setAttribute("data-shopbonus-key",'.json_encode($publicKey, JSON_HEX_TAG).');(d.head||d.body).appendChild(s);})(document);';
    }

    private function authorize(WebshopConnection $connection, User $actor): void
    {
        $u = User::find($actor->id);
        $m = WebshopMembership::where('webshop_id', $connection->webshop_id)->where('user_id', $actor->id)->first();
        if (! $u?->isActive() || (! $u->isSuperAdmin() && ($m === null || ! $m->allows(WorkspacePermission::IntegrationsManage) || ! $m->allows(WorkspacePermission::SettingsManage)))) {
            throw new ApiException('Current native publication permissions are required.', 403, 'native_banner_forbidden');
        }
        if ($connection->platform !== EcommercePlatform::LightspeedC) {
            throw new \LogicException('LightspeedScriptPublisher only serves Lightspeed C-Series connections.');
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

    /** @param array<string,mixed> $remote */
    private function verify(AuditEvent $plan, array $remote): void
    {
        $script = $plan->metadata['script'];
        if (($remote['body'] ?? null) !== $script['body'] || ($remote['location'] ?? null) !== $script['location'] || ! in_array($remote['url'] ?? null, [null, ''], true)) {
            throw ApiException::conflict('The shop script does not match its frozen ownership/configuration.', 'native_banner_remote_mismatch');
        }
    }

    private function observe(WebshopConnection $connection, User $actor, AuditEvent $plan, int $id): void
    {
        DB::transaction(function () use ($connection, $actor, $plan, $id) {
            $this->current($connection, $actor);
            $this->bound($connection, $plan);
            if ($this->has($plan, 'removing') || $this->has($plan, 'removed')) {
                throw ApiException::conflict('Native removal has started.', 'native_banner_removing');
            }
            $existing = $this->observation($plan);
            if ($existing !== null && ($existing->metadata['script_id'] ?? null) !== $id) {
                throw ApiException::conflict('A different shop script is already bound.', 'native_banner_remote_mismatch');
            }
            if ($existing === null) {
                $this->record($connection, $actor, $plan, 'script_observed', ['script_id' => $id]);
            }
        });
    }

    private function latestPlan(WebshopConnection $connection): ?AuditEvent
    {
        return AuditEvent::where('resource_type', $connection->getMorphClass())->where('resource_id', (string) $connection->id)->where('action', 'integration.banner_ls_plan')->latest('id')->first();
    }

    private function has(AuditEvent $plan, string $suffix): bool
    {
        return $this->events($plan, $suffix)->exists();
    }

    private function observation(AuditEvent $plan): ?AuditEvent
    {
        return $this->events($plan, 'script_observed')->latest('id')->first();
    }

    /** @return Builder<AuditEvent> */
    private function events(AuditEvent $plan, string $suffix): Builder
    {
        return AuditEvent::where('action', 'integration.banner_ls_'.$suffix)->where('metadata->plan_id', $plan->id);
    }

    /** @param array<string,mixed> $extra */
    private function record(WebshopConnection $connection, User $actor, AuditEvent $plan, string $suffix, array $extra = []): AuditEvent
    {
        return $this->audit->record('integration.banner_ls_'.$suffix, category: AuditCategory::Integration, resource: $connection, actorUserId: $actor->id, webshopId: $connection->webshop_id, metadata: ['plan_id' => $plan->id] + $extra);
    }
}
