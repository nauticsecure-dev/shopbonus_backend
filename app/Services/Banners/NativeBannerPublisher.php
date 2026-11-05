<?php

namespace App\Services\Banners;

use App\Enums\AuditCategory;
use App\Enums\EcommercePlatform;
use App\Enums\WorkspacePermission;
use App\Exceptions\ApiException;
use App\Integrations\Connectors\BigCommerceConnector;
use App\Integrations\Http\BigCommerceWidgets;
use App\Models\AuditEvent;
use App\Models\BannerCampaign;
use App\Models\User;
use App\Models\WebshopConnection;
use App\Models\WebshopMembership;
use App\Services\Audit\AuditLogger;
use App\Services\Onboarding\DomainName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Durable append-only native publication intents. Remote configuration is not browser delivery proof. */
class NativeBannerPublisher
{
    private const RESOURCES = ['template' => 'widget-templates', 'widget' => 'widgets', 'placement' => 'placements'];

    public function __construct(private readonly BigCommerceWidgets $api, private readonly AuditLogger $audit) {}

    /** @return array<string,mixed> */
    public function readiness(EcommercePlatform $platform): array
    {
        return match ($platform) {
            EcommercePlatform::BigCommerce => ['state' => 'implemented_opt_in', 'automatic_publication' => true, 'requires' => ['Content modify scope', 'single verified store', 'existing reviewed theme region', 'live funded campaign', 'HTTPS ShopBonus origin'], 'delivery' => 'not_observed'],
            EcommercePlatform::LightspeedC => ['state' => 'implemented_opt_in', 'automatic_publication' => true, 'requires' => ['LIGHTSPEED_BANNERS_ENABLED', 'API key allowed to manage shop scripts', 'verified shop identity and owned domain', 'live funded campaign', 'HTTPS ShopBonus origin'],
                'delivery' => 'not_observed', 'method' => 'one owned Shop Script (POST /shop/scripts.json) loading /embed/v1.js', 'source' => 'https://developers.lightspeedhq.com/ecom/endpoints/shopscript/'],
            EcommercePlatform::LightspeedE,EcommercePlatform::Ecwid => ['state' => 'native_app_required', 'automatic_publication' => false, 'reason' => 'Requires an installed native app with customize_storefront/customJsUrl; merchant order tokens cannot configure app endpoints.', 'source' => 'https://docs.ecwid.com/develop-apps/app-settings'],
            EcommercePlatform::Squarespace => ['state' => 'manual_installation_required', 'automatic_publication' => false, 'reason' => 'Orders/Commerce credentials do not establish code-injection authority; merchant Code Injection setup is required.', 'source' => 'https://support.squarespace.com/hc/en-us/articles/205815908-Customize-parts-of-your-site-with-code-injection'],
            default => ['state' => 'unsupported', 'automatic_publication' => false, 'reason' => 'No reviewed native publisher in this boundary.'],
        };
    }

    /** @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    public function install(WebshopConnection $connection, array $target, User $actor): array
    {
        $this->authorize($connection, $actor);
        if (! config('integrations.bigcommerce_banners.enabled')) {
            throw ApiException::conflict('Native banner writes are disabled.', 'native_banner_disabled');
        }
        $v = Validator::make($target, ['template_file' => ['required', 'in:pages/home,pages/category,pages/product,pages/brand,pages/page'],
            'region' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{1,100}\z/D'], 'entity_id' => ['nullable', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'], 'sort_order' => ['required', 'integer', 'between:0,1000']]);
        if ($v->fails()) {
            throw new ApiException('Choose a reviewed native page/region target.', 422, 'native_banner_target_invalid');
        }
        $validated = $v->validated();
        $t = ['template_file' => $validated['template_file'], 'region' => $validated['region'], 'entity_id' => $validated['entity_id'] ?? '', 'sort_order' => (int) $validated['sort_order']];
        if ($t['template_file'] === 'pages/home' && $t['entity_id'] !== '') {
            throw new ApiException('Home placements cannot target an entity.', 422, 'native_banner_target_invalid');
        }
        $this->identity($connection);
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
        DomainName::fromInput($origin);
        $regions = $this->api->regions($connection, $t['template_file']);
        if (count(array_filter($regions, fn (string $r) => $r === $t['region'])) !== 1) {
            throw ApiException::conflict('The reviewed native region was not confirmed.', 'native_banner_region_missing');
        }
        $context = ['generation' => $connection->credential_generation, 'store' => $connection->external_shop_ref, 'domain' => $shop->verified_domain, 'public_key' => $installation->public_key,
            'script_url' => $origin.'/embed/v1.js', 'cart_script_url' => $origin.'/embed/native-cart-v1.js', 'target' => $t];
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

            return $this->audit->record('integration.banner_plan', category: AuditCategory::Integration, resource: $connection, actorUserId: $actor->id, webshopId: $connection->webshop_id, metadata: $context);
        });
        foreach (array_keys(self::RESOURCES) as $step) {
            if ($this->observation($plan, $step) !== null) {
                continue;
            }
            $payload = $this->payload($plan, $step);
            DB::transaction(function () use ($connection, $actor, $plan, $step, $payload) {
                $this->current($connection, $actor);
                $this->bound($connection, $plan);
                if ($this->has($plan, 'removing') || $this->has($plan, 'removed')) {
                    throw ApiException::conflict('Native removal has started.', 'native_banner_removing');
                }
                if ($this->has($plan, $step.'_intent')) {
                    throw ApiException::conflict('A native create may have succeeded. Bind its verified UUID; do not create again.', 'native_banner_outcome_unknown');
                }
                $this->record($connection, $actor, $plan, $step.'_intent', ['payload_digest' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))]);
            });
            $remote = $this->api->request($connection, 'POST', self::RESOURCES[$step], data: $payload);
            if ($remote === null) {
                throw ApiException::conflict('Native create has not been confirmed.', 'native_banner_outcome_unknown');
            }
            $this->verify($plan, $step, $remote);
            $read = $this->api->request($connection, 'GET', self::RESOURCES[$step], $remote['uuid']);
            if ($read === null) {
                throw ApiException::conflict('Native create is not yet observable.', 'native_banner_outcome_unknown');
            }
            $this->verify($plan, $step, $read);
            $this->observe($connection, $actor, $plan, $step, $read['uuid']);
        }

        return $this->reconcile($connection, $actor);
    }

    /** Explicit recovery of a timed-out create, never an unverified UUID adoption.
     * @return array<string,mixed>
     */
    public function bind(WebshopConnection $connection, string $step, string $uuid, User $actor): array
    {
        $this->authorize($connection, $actor);
        $this->identity($connection);
        $plan = $this->latestPlan($connection) ?? throw ApiException::conflict('No native publication plan exists.', 'native_banner_plan_missing');
        $this->bound($connection, $plan);
        if (! isset(self::RESOURCES[$step]) || ! Str::isUuid($uuid) || ! $this->has($plan, $step.'_intent') || $this->observation($plan, $step) !== null) {
            throw ApiException::conflict('Recover only an unresolved matching native create.', 'native_banner_bind_invalid');
        }
        $remote = $this->api->request($connection, 'GET', self::RESOURCES[$step], $uuid);
        if ($remote === null) {
            throw ApiException::conflict('The native object was not found.', 'native_banner_remote_missing');
        }
        $this->verify($plan, $step, $remote);
        $this->observe($connection, $actor, $plan, $step, $remote['uuid']);

        return ['plan' => $plan->id, 'bound_step' => $step, 'remote_uuid' => $remote['uuid'], 'next' => 'Run install with the same frozen target to continue.'];
    }

    /** @return array<string,mixed> */
    public function reconcile(WebshopConnection $connection, User $actor): array
    {
        $this->authorize($connection, $actor);
        $this->identity($connection);
        $plan = $this->latestPlan($connection) ?? throw ApiException::conflict('No native publication plan exists.', 'native_banner_plan_missing');
        $this->bound($connection, $plan, allowRotated: true);
        if ($this->has($plan, 'removed')) {
            return ['plan' => $plan->id, 'state' => 'removed', 'storefront_delivery' => 'not_observed'];
        }
        if ($this->has($plan, 'removing')) {
            return ['plan' => $plan->id, 'state' => 'removing', 'storefront_delivery' => 'not_observed'];
        }
        $ids = [];
        foreach (self::RESOURCES as $step => $resource) {
            $id = $this->observation($plan, $step)?->metadata['uuid'] ?? null;
            if ($id === null) {
                return ['plan' => $plan->id, 'state' => 'incomplete_or_uncertain', 'step' => $step, 'storefront_delivery' => 'not_observed'];
            }
            $remote = $this->api->request($connection, 'GET', $resource, $id);
            if ($remote === null) {
                return ['plan' => $plan->id, 'state' => 'remote_missing', 'step' => $step, 'storefront_delivery' => 'not_observed'];
            }
            $this->verify($plan, $step, $remote);
            $ids[$step] = $id;
        }

        return ['plan' => $plan->id, 'state' => 'native_configured', 'remote_ids' => $ids, 'storefront_delivery' => 'not_observed', 'automatic_campaign_updates' => true];
    }

    /** Remove owned remote resources in reverse dependency order; confirmed absence reconciles uncertainty.
     * @return array<string,mixed>
     */
    public function remove(WebshopConnection $connection, User $actor): array
    {
        $this->authorize($connection, $actor);
        $this->identity($connection);
        $plan = $this->latestPlan($connection) ?? throw ApiException::conflict('No native publication plan exists.', 'native_banner_plan_missing');
        $this->bound($connection, $plan, allowRotated: true);
        DB::transaction(function () use ($connection, $actor, $plan) {
            $this->current($connection, $actor, allowRotated: true);
            $this->bound($connection, $plan, allowRotated: true);
            foreach (array_keys(self::RESOURCES) as $step) {
                if ($this->has($plan, $step.'_intent') && $this->observation($plan, $step) === null) {
                    throw ApiException::conflict('Bind uncertain creates before starting removal.', 'native_banner_outcome_unknown');
                }
            }
            if (! $this->has($plan, 'removing')) {
                $this->record($connection, $actor, $plan, 'removing');
            }
        });
        foreach (array_reverse(array_keys(self::RESOURCES)) as $step) {
            $observed = $this->observation($plan, $step);
            $id = $observed?->metadata['uuid'] ?? null;
            if ($id === null) {
                if ($this->has($plan, $step.'_intent')) {
                    throw ApiException::conflict('Bind uncertain created resources before removing them.', 'native_banner_outcome_unknown');
                }

                continue;
            }
            $remote = $this->api->request($connection, 'GET', self::RESOURCES[$step], $id);
            if ($remote !== null) {
                $this->verify($plan, $step, $remote);
                DB::transaction(function () use ($connection, $actor, $plan, $step) {
                    $this->current($connection, $actor, allowRotated: true);
                    $this->bound($connection, $plan, allowRotated: true);
                    if ($this->has($plan, $step.'_delete_intent')) {
                        throw ApiException::conflict('The previous delete remains unconfirmed. Reconcile native absence first.', 'native_banner_outcome_unknown');
                    }
                    $this->record($connection, $actor, $plan, $step.'_delete_intent');
                });
                $this->api->request($connection, 'DELETE', self::RESOURCES[$step], $id);
                if ($this->api->request($connection, 'GET', self::RESOURCES[$step], $id) !== null) {
                    throw ApiException::conflict('Native deletion is not yet confirmed.', 'native_banner_outcome_unknown');
                }
            }
            $this->record($connection, $actor, $plan, $step.'_deleted', ['uuid' => $id]);
        }
        $this->record($connection, $actor, $plan, 'removed');

        return ['plan' => $plan->id, 'state' => 'removed', 'storefront_delivery' => 'not_observed'];
    }

    private function authorize(WebshopConnection $connection, User $actor): void
    {
        $u = User::find($actor->id);
        $m = WebshopMembership::where('webshop_id', $connection->webshop_id)->where('user_id', $actor->id)->first();
        if (! $u?->isActive() || (! $u->isSuperAdmin() && ($m === null || ! $m->allows(WorkspacePermission::IntegrationsManage) || ! $m->allows(WorkspacePermission::SettingsManage)))) {
            throw new ApiException('Current native publication permissions are required.', 403, 'native_banner_forbidden');
        }
        if ($connection->platform !== EcommercePlatform::BigCommerce) {
            throw ApiException::conflict('This provider requires its documented native app or merchant installation.', 'native_banner_unsupported', $this->readiness($connection->platform));
        }
    }

    private function identity(WebshopConnection $connection): void
    {
        app(BigCommerceConnector::class)->verifyStore($connection);
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
        return AuditEvent::where('resource_type', $connection->getMorphClass())->where('resource_id', (string) $connection->id)->where('action', 'integration.banner_plan')->latest('id')->first();
    }

    private function has(AuditEvent $plan, string $suffix): bool
    {
        return $this->events($plan, $suffix)->exists();
    }

    private function observation(AuditEvent $plan, string $step): ?AuditEvent
    {
        return $this->events($plan, $step.'_observed')->latest('id')->first();
    }

    /** @return Builder<AuditEvent> */
    private function events(AuditEvent $plan, string $suffix): Builder
    {
        return AuditEvent::where('action', 'integration.banner_'.$suffix)->where('metadata->plan_id', $plan->id);
    }

    /** @param array<string,mixed> $extra */
    private function record(WebshopConnection $connection, User $actor, AuditEvent $plan, string $suffix, array $extra = []): AuditEvent
    {
        return $this->audit->record('integration.banner_'.$suffix, category: AuditCategory::Integration, resource: $connection, actorUserId: $actor->id, webshopId: $connection->webshop_id, metadata: ['plan_id' => $plan->id] + $extra);
    }

    private function observe(WebshopConnection $connection, User $actor, AuditEvent $plan, string $step, string $uuid): void
    {
        DB::transaction(function () use ($connection, $actor, $plan, $step, $uuid) {
            $this->current($connection, $actor);
            $this->bound($connection, $plan);
            if ($this->has($plan, 'removing') || $this->has($plan, 'removed')) {
                throw ApiException::conflict('Native removal has started.', 'native_banner_removing');
            }
            $existing = $this->observation($plan, $step);
            if ($existing !== null) {
                if (($existing->metadata['uuid'] ?? null) !== $uuid) {
                    throw ApiException::conflict('A different native UUID is already bound.', 'native_banner_remote_mismatch');
                }

                return;
            }
            $this->record($connection, $actor, $plan, $step.'_observed', ['uuid' => $uuid]);
        });
    }

    /** @return array<string,mixed> */
    private function payload(AuditEvent $plan, string $step): array
    {
        $m = $plan->metadata;
        $name = 'ShopBonus '.$plan->id;

        return match ($step) {
            'template' => ['name' => $name, 'template' => self::template($m), 'channel_id' => 1],
            'widget' => ['name' => $name, 'widget_template_uuid' => $this->observation($plan, 'template')?->metadata['uuid'] ?? throw ApiException::conflict('Confirm the native template first.', 'native_banner_incomplete'),
                'widget_configuration' => ['shopbonus_public_key' => $m['public_key']], 'channel_id' => 1],
            'placement' => $m['target'] + ['widget_uuid' => $this->observation($plan, 'widget')?->metadata['uuid'] ?? throw ApiException::conflict('Confirm the native widget first.', 'native_banner_incomplete'), 'status' => 'active', 'channel_id' => 1],
            default => throw new \InvalidArgumentException('Unsupported native publication step.'),
        };
    }

    /**
     * Plans frozen with `cart_script_url` load the BigCommerce cart reader before the renderer (ordered defer),
     * so cart changes drive live banner progress. Older plans keep their exact frozen template.
     *
     * @param  array<string,mixed>  $m
     */
    private static function template(array $m): string
    {
        $e = fn (string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (! isset($m['cart_script_url'])) {
            return '<script async src="'.$e($m['script_url']).'" data-shopbonus-key="{{shopbonus_public_key}}"></script>';
        }

        return '<script defer src="'.$e($m['cart_script_url']).'" data-platform="bigcommerce"></script>'
            .'<script defer src="'.$e($m['script_url']).'" data-shopbonus-key="{{shopbonus_public_key}}"></script>';
    }

    /** @param array<string,mixed> $remote */
    private function verify(AuditEvent $plan, string $step, array $remote): void
    {
        $p = $this->payload($plan, $step);
        $valid = is_string($remote['uuid'] ?? null) && Str::isUuid($remote['uuid']) && ($remote['channel_id'] ?? null) === 1;
        if ($step === 'template') {
            $valid = $valid && ($remote['name'] ?? null) === $p['name'] && ($remote['template'] ?? null) === $p['template'];
        } elseif ($step === 'widget') {
            $valid = $valid && ($remote['name'] ?? null) === $p['name'] && ($remote['widget_template']['uuid'] ?? $remote['widget_template_uuid'] ?? null) === $p['widget_template_uuid']
                && ($remote['widget_configuration']['shopbonus_public_key'] ?? null) === $p['widget_configuration']['shopbonus_public_key']
                && (! isset($remote['template']) || $remote['template'] === '' || $remote['template'] === $this->payload($plan, 'template')['template']);
        } else {
            $valid = $valid && ($remote['widget']['uuid'] ?? $remote['widget_uuid'] ?? null) === $p['widget_uuid'] && ($remote['template_file'] ?? null) === $p['template_file']
                && ($remote['region'] ?? null) === $p['region'] && ($remote['entity_id'] ?? '') === $p['entity_id'] && ($remote['sort_order'] ?? null) === $p['sort_order'] && ($remote['status'] ?? null) === 'active';
        }
        if (! $valid) {
            throw ApiException::conflict('The native object does not match its frozen ownership/configuration.', 'native_banner_remote_mismatch');
        }
    }
}
