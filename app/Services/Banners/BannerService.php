<?php

namespace App\Services\Banners;

use App\Enums\AuditCategory;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Exceptions\ApiException;
use App\Models\BannerCampaign;
use App\Models\BannerInstallation;
use App\Models\BannerVersion;
use App\Models\Offer;
use App\Models\Webshop;
use App\Services\Audit\AuditLogger;
use App\Services\Funding\MerchantFunding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Banner campaign lifecycle (Trello #25 §2): private drafts with optimistic
 * locking, immutable published versions, schedule/pause/resume/rollback.
 * Publishing a banner for a sponsored reward needs the reward to be funded.
 */
class BannerService
{
    public function __construct(private readonly BannerConfig $schema, private readonly MerchantFunding $funding, private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function create(Webshop $webshop, string $name, ?Offer $offer, array $config, int $actorId): BannerCampaign
    {
        $config = $this->schema->validate($config, $webshop->id);
        $c = BannerCampaign::create(['webshop_id' => $webshop->id, 'offer_id' => $offer?->id, 'name' => $name, 'status' => 'draft', 'draft_config' => $config, 'created_by' => $actorId]);
        $this->audit->record('banner.created', category: AuditCategory::Workspace, resource: $c, webshopId: $webshop->id, actorUserId: $actorId);

        return $c;
    }

    /**
     * Saves the private draft. $revision must be the revision the editor started from.
     *
     * @param  array<string, mixed>  $config
     */
    public function saveDraft(BannerCampaign $campaign, array $config, int $revision, int $actorId, ?string $name = null): BannerCampaign
    {
        $config = $this->schema->validate($config, $campaign->webshop_id);

        return DB::transaction(function () use ($campaign, $config, $revision, $actorId, $name) {
            $updated = BannerCampaign::whereKey($campaign->id)->where('draft_revision', $revision)->update([
                'draft_config' => json_encode($config), 'draft_revision' => $revision + 1, 'name' => $name ?? $campaign->name, 'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                throw ApiException::conflict('Someone else saved this banner in the meantime. Reload to continue.', 'draft_revision_conflict',
                    ['current_revision' => BannerCampaign::whereKey($campaign->id)->value('draft_revision')]);
            }
            $this->audit->record('banner.draft_saved', category: AuditCategory::Workspace, resource: $campaign, webshopId: $campaign->webshop_id, actorUserId: $actorId);

            return $campaign->refresh();
        });
    }

    public function duplicate(BannerCampaign $campaign, int $actorId): BannerCampaign
    {
        $copy = BannerCampaign::create(['webshop_id' => $campaign->webshop_id, 'offer_id' => $campaign->offer_id, 'name' => mb_substr($campaign->name.' (copy)', 0, 120),
            'status' => 'draft', 'draft_config' => $campaign->draft_config, 'created_by' => $actorId]);
        $this->audit->record('banner.duplicated', category: AuditCategory::Workspace, resource: $copy, webshopId: $campaign->webshop_id, actorUserId: $actorId);

        return $copy;
    }

    public function publish(BannerCampaign $campaign, ?int $actorId): BannerCampaign
    {
        $config = $this->schema->validate($campaign->draft_config, $campaign->webshop_id);
        $this->assertPublishable($campaign);

        return DB::transaction(function () use ($campaign, $config, $actorId) {
            $c = BannerCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $version = $this->newVersion($c, $config, $actorId);
            $c->forceFill(['status' => 'live', 'published_version_id' => $version->id, 'scheduled_at' => null])->save();
            $this->audit->record('banner.published', category: AuditCategory::Workspace, resource: $c, webshopId: $c->webshop_id, actorUserId: $actorId, metadata: ['version' => $version->version]);

            return $c;
        });
    }

    /** Schedule in shop-local time; checked again at publish. */
    public function schedule(BannerCampaign $campaign, CarbonImmutable $at, int $actorId): BannerCampaign
    {
        if ($at->isPast()) {
            throw ValidationException::withMessages(['publish_at' => 'Choose a moment in the future.']);
        }
        $this->schema->validate($campaign->draft_config, $campaign->webshop_id);
        $this->assertPublishable($campaign);
        $campaign->forceFill(['status' => $campaign->published_version_id === null ? 'scheduled' : $campaign->status, 'scheduled_at' => $at->utc()])->save();
        $this->audit->record('banner.scheduled', category: AuditCategory::Workspace, resource: $campaign, webshopId: $campaign->webshop_id, actorUserId: $actorId, metadata: ['at' => $at->toIso8601String()]);

        return $campaign;
    }

    /** Scheduled command. Returns the number published. */
    public function publishDue(?CarbonImmutable $now = null): int
    {
        $n = 0;
        BannerCampaign::whereNotNull('scheduled_at')->where('scheduled_at', '<=', $now ?? CarbonImmutable::now())->orderBy('id')->each(function (BannerCampaign $c) use (&$n) {
            try {
                $this->publish($c, null);
                $n++;
            } catch (ApiException|ValidationException $e) {
                $c->forceFill(['scheduled_at' => null, 'status' => $c->published_version_id === null ? 'draft' : $c->status])->save();
                $this->audit->recordSafely('banner.schedule_failed', category: AuditCategory::Workspace, resource: $c, webshopId: $c->webshop_id, metadata: ['reason' => $e->getMessage()]);
            }
        });

        return $n;
    }

    public function pause(BannerCampaign $campaign, int $actorId): BannerCampaign
    {
        if ($campaign->status !== 'live') {
            throw ApiException::conflict('Only a live banner can be paused.', 'banner_not_live');
        }
        $campaign->forceFill(['status' => 'paused'])->save();
        $this->audit->record('banner.paused', category: AuditCategory::Workspace, resource: $campaign, webshopId: $campaign->webshop_id, actorUserId: $actorId);

        return $campaign;
    }

    public function resume(BannerCampaign $campaign, int $actorId): BannerCampaign
    {
        if ($campaign->status !== 'paused' || $campaign->published_version_id === null) {
            throw ApiException::conflict('Only a paused, published banner can be resumed.', 'banner_not_paused');
        }
        $this->assertPublishable($campaign);
        $campaign->forceFill(['status' => 'live'])->save();
        $this->audit->record('banner.resumed', category: AuditCategory::Workspace, resource: $campaign, webshopId: $campaign->webshop_id, actorUserId: $actorId);

        return $campaign;
    }

    /** Re-publishes an earlier version as a new version (history stays immutable). */
    public function rollback(BannerCampaign $campaign, int $toVersion, int $actorId): BannerCampaign
    {
        $old = BannerVersion::where('campaign_id', $campaign->id)->where('version', $toVersion)->first() ?? throw new ApiException('Version not found.', 404, 'not_found');

        return DB::transaction(function () use ($campaign, $old, $actorId) {
            $c = BannerCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $version = $this->newVersion($c, $old->config, $actorId);
            $c->forceFill(['status' => 'live', 'published_version_id' => $version->id, 'draft_config' => $old->config, 'draft_revision' => $c->draft_revision + 1])->save();
            $this->audit->record('banner.rolled_back', category: AuditCategory::Workspace, resource: $c, webshopId: $c->webshop_id, actorUserId: $actorId,
                metadata: ['to' => $old->version, 'as' => $version->version]);

            return $c;
        });
    }

    public function installation(Webshop $webshop): BannerInstallation
    {
        $domain = $webshop->verified_domain;

        return BannerInstallation::firstOrCreate(['webshop_id' => $webshop->id], [
            'public_key' => 'pk_'.Str::lower(Str::random(24)),
            'allowed_origins' => $domain === null ? [] : ['https://'.$domain, 'https://www.'.$domain],
        ]);
    }

    /**
     * Only the merchant's verified domain and its subdomains (B03c) may embed.
     *
     * @param  list<string>  $origins
     */
    public function setOrigins(Webshop $webshop, array $origins, int $actorId): BannerInstallation
    {
        $domain = $webshop->verified_domain ?? throw ApiException::conflict('Verify the webshop domain first.', 'domain_not_verified');
        $clean = [];
        foreach ($origins as $i => $origin) {
            $origin = rtrim(strtolower(trim($origin)), '/');
            $host = parse_url($origin, PHP_URL_HOST);
            if (! str_starts_with($origin, 'https://') || ! is_string($host) || parse_url($origin, PHP_URL_PATH) !== null
                || ($host !== $domain && ! str_ends_with($host, '.'.$domain))) {
                throw ValidationException::withMessages(["origins.{$i}" => "Use https origins on {$domain} or its subdomains."]);
            }
            $clean[] = $origin;
        }
        $installation = $this->installation($webshop);
        $installation->forceFill(['allowed_origins' => array_values(array_unique($clean))])->save();
        $this->audit->record('banner.origins_updated', category: AuditCategory::Workspace, webshopId: $webshop->id, actorUserId: $actorId, metadata: ['origins' => $clean]);

        return $installation;
    }

    private function assertPublishable(BannerCampaign $campaign): void
    {
        $offer = $campaign->offer_id === null ? null : Offer::with('currentVersion')->find($campaign->offer_id);
        if ($offer !== null && $offer->status !== OfferStatus::Active) {
            throw ApiException::conflict('The linked offer is not active.', 'offer_not_active');
        }
        if ($offer !== null && $offer->acquisition === OfferAcquisition::MerchantReward && $offer->currentVersion !== null && ! $this->funding->canFund($campaign->webshop_id, $offer->currentVersion)) {
            throw ApiException::conflict('Top up the reward funding before publishing this banner.', 'funding_insufficient',
                ['required_minor' => $this->funding->costOf($offer->currentVersion)['total_minor']]);
        }
    }

    /** @param array<string, mixed> $config */
    private function newVersion(BannerCampaign $c, array $config, ?int $actorId): BannerVersion
    {
        $next = (int) BannerVersion::where('campaign_id', $c->id)->max('version') + 1;

        return BannerVersion::create(['campaign_id' => $c->id, 'version' => $next, 'config' => $config, 'published_by' => $actorId]);
    }
}
