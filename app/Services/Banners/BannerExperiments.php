<?php

namespace App\Services\Banners;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\BannerCampaign;
use App\Models\BannerInstallation;
use App\Models\BannerVersion;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BannerExperiments
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, mixed> */
    public function start(BannerCampaign $campaign, ?int $control, int $variant, int $bps, int $actorId): array
    {
        return DB::transaction(function () use ($campaign, $control, $variant, $bps, $actorId): array {
            $c = BannerCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ($c->status !== 'live' || $this->active($c->id) !== null) {
                throw ApiException::conflict('Start experiments on a live banner without another active experiment.', 'banner_experiment_conflict');
            }
            $v = BannerVersion::where('campaign_id', $c->id)->where('version', $variant)->first();
            $a = $control === null ? null : BannerVersion::where('campaign_id', $c->id)->where('version', $control)->first();
            if ($v === null || ($control !== null && ($a === null || $a->id === $v->id))) {
                throw new ApiException('Choose different published versions of this banner, or a holdout control.', 422, 'banner_experiment_versions');
            }
            $id = DB::table('banner_experiments')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'campaign_id' => $c->id,
                'active_campaign_id' => $c->id, 'control_version_id' => $a?->id, 'variant_version_id' => $v->id,
                'variant_bps' => $bps, 'created_by' => $actorId, 'started_at' => now()]);
            $this->audit->record('banner.experiment_started', category: AuditCategory::Workspace, resource: $c, webshopId: $c->webshop_id, actorUserId: $actorId,
                metadata: ['control_version' => $control, 'variant_version' => $variant, 'variant_bps' => $bps]);

            return $this->report($c, $id);
        });
    }

    public function stop(BannerCampaign $campaign, int $actorId): void
    {
        DB::transaction(function () use ($campaign, $actorId) {
            $c = BannerCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $experiment = $this->active($c->id) ?? throw ApiException::conflict('This banner has no active experiment.', 'banner_experiment_missing');
            DB::table('banner_experiments')->where('id', $experiment->id)->update(['stopped_at' => now(), 'active_campaign_id' => null]);
            $this->audit->record('banner.experiment_stopped', category: AuditCategory::Workspace, resource: $c, webshopId: $c->webshop_id, actorUserId: $actorId);
        });
    }

    public function active(int $campaignId): ?object
    {
        return DB::table('banner_experiments')->where('active_campaign_id', $campaignId)->first();
    }

    /** @return list<array<string, mixed>> */
    public function assign(BannerInstallation $installation, string $visitorKey): array
    {
        $out = [];
        $visitorHash = hash_hmac('sha256', 'banner-visitor|'.$installation->webshop_id.'|'.$visitorKey, (string) config('app.key'));
        foreach (BannerCampaign::where('webshop_id', $installation->webshop_id)->where('status', 'live')->get() as $c) {
            $item = DB::transaction(function () use ($c, $visitorHash): ?array {
                $experiment = DB::table('banner_experiments')->where('active_campaign_id', $c->id)->lockForUpdate()->first();
                if ($experiment === null) {
                    return null;
                }
                $token = 'bx_'.hash_hmac('sha256', 'banner-assignment|'.$experiment->public_id.'|'.$visitorHash, (string) config('app.key'));
                $assignment = DB::table('banner_assignments')->where('experiment_id', $experiment->id)->where('visitor_hash', $visitorHash)->first();
                if ($assignment === null) {
                    $arm = random_int(1, 10000) <= $experiment->variant_bps ? 'variant' : 'control';
                    $id = DB::table('banner_assignments')->insertGetId(['experiment_id' => $experiment->id, 'visitor_hash' => $visitorHash,
                        'token_hash' => $this->tokenHash($token), 'arm' => $arm, 'created_at' => now(), 'expires_at' => now()->addDays(30)]);
                    $assignment = DB::table('banner_assignments')->where('id', $id)->first();
                }
                if ($assignment->revoked_at !== null || CarbonImmutable::parse($assignment->expires_at)->isPast()) {
                    return null;
                }
                $versionId = $assignment->arm === 'variant' ? $experiment->variant_version_id : $experiment->control_version_id;

                return ['campaign' => $c->public_id, 'assignment_token' => $token, 'arm' => $assignment->arm,
                    'version' => $versionId === null ? 0 : BannerVersion::findOrFail($versionId)->version, 'holdout' => $versionId === null,
                    'expires_at' => CarbonImmutable::parse($assignment->expires_at)->toIso8601String()];
            });
            if ($item !== null) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @return array{config: array<string, mixed>, version: int}|null */
    public function selected(BannerCampaign $campaign, ?string $token): ?array
    {
        $experiment = $this->active($campaign->id);
        if ($experiment === null) {
            $version = BannerVersion::findOrFail($campaign->published_version_id);

            return ['config' => $version->config, 'version' => $version->version];
        }
        $assignment = $token === null ? null : $this->assignment($campaign->id, $token);
        if ($assignment === null || $assignment->experiment_id !== $experiment->id) {
            return null;
        }
        $versionId = $assignment->arm === 'variant' ? $experiment->variant_version_id : $experiment->control_version_id;
        if ($versionId === null) {
            return null;
        }
        $version = BannerVersion::findOrFail($versionId);

        return ['config' => $version->config, 'version' => $version->version];
    }

    public function assignment(int $campaignId, string $token): ?object
    {
        return DB::table('banner_assignments')->join('banner_experiments', 'banner_experiments.id', '=', 'banner_assignments.experiment_id')
            ->where('banner_assignments.token_hash', $this->tokenHash($token))->where('banner_experiments.campaign_id', $campaignId)
            ->where('banner_assignments.expires_at', '>=', now())->whereNull('banner_assignments.revoked_at')
            ->select('banner_assignments.*', 'banner_experiments.control_version_id', 'banner_experiments.variant_version_id', 'banner_experiments.stopped_at')->first();
    }

    public function orderAssignment(int $webshopId, ?string $token): ?int
    {
        if ($token === null) {
            return null;
        }

        return DB::table('banner_assignments')->join('banner_experiments', 'banner_experiments.id', '=', 'banner_assignments.experiment_id')
            ->join('banner_campaigns', 'banner_campaigns.id', '=', 'banner_experiments.campaign_id')
            ->where('banner_campaigns.webshop_id', $webshopId)->where('banner_assignments.token_hash', $this->tokenHash($token))
            ->value('banner_assignments.id');
    }

    public function revoke(BannerInstallation $installation, string $visitorKey): void
    {
        $visitorHash = hash_hmac('sha256', 'banner-visitor|'.$installation->webshop_id.'|'.$visitorKey, (string) config('app.key'));
        $experiments = DB::table('banner_experiments')->join('banner_campaigns', 'banner_campaigns.id', '=', 'banner_experiments.campaign_id')
            ->where('banner_campaigns.webshop_id', $installation->webshop_id)->select('banner_experiments.id');
        DB::table('banner_assignments')->whereIn('experiment_id', $experiments)->where('visitor_hash', $visitorHash)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function tokenHash(string $token): string
    {
        return hash_hmac('sha256', 'banner-assignment-token|'.$token, (string) config('app.key'));
    }

    /** @return array<string, mixed> */
    public function report(BannerCampaign $campaign, ?int $id = null): array
    {
        $e = DB::table('banner_experiments')->where('campaign_id', $campaign->id)->when($id !== null, fn ($q) => $q->where('id', $id))->orderByDesc('id')->first();
        if ($e === null) {
            return ['experiment' => null];
        }
        $arms = [];
        foreach (['control', 'variant'] as $arm) {
            $assignments = DB::table('banner_assignments')->where('experiment_id', $e->id)->where('arm', $arm)->count();
            $events = DB::table('banner_events')->join('banner_assignments', 'banner_assignments.id', '=', 'banner_events.banner_assignment_id')
                ->where('banner_assignments.experiment_id', $e->id)->where('banner_assignments.arm', $arm)
                ->selectRaw("SUM(CASE WHEN banner_events.type='viewable_impression' THEN 1 ELSE 0 END) impressions, SUM(CASE WHEN banner_events.type='cta_click' THEN 1 ELSE 0 END) clicks")->first();
            $orders = DB::table('webshop_orders')->join('banner_assignments', 'banner_assignments.id', '=', 'webshop_orders.banner_assignment_id')
                ->where('banner_assignments.experiment_id', $e->id)->where('banner_assignments.arm', $arm)->where('webshop_orders.webshop_id', $campaign->webshop_id)
                ->whereIn('webshop_orders.payment_status', ['paid', 'partially_refunded'])->where('webshop_orders.currency', 'EUR')->where('webshop_orders.ingestion_status', 'complete')
                ->whereColumn('webshop_orders.paid_at', '>=', 'banner_assignments.created_at')->whereColumn('webshop_orders.paid_at', '<=', 'banner_assignments.expires_at')
                ->where(fn ($q) => $q->whereNull('banner_assignments.revoked_at')->orWhereColumn('webshop_orders.paid_at', '<=', 'banner_assignments.revoked_at'))
                ->selectRaw('COUNT(*) orders, COUNT(DISTINCT banner_assignments.id) converted, COALESCE(SUM(webshop_orders.amount_minor - webshop_orders.refunded_minor),0) revenue')->first();
            $arms[$arm] = ['assigned_visitors' => $assignments, 'viewable_impressions' => (int) $events->impressions, 'cta_clicks' => (int) $events->clicks,
                'verified_paid_orders' => (int) $orders->orders, 'converted_visitors' => (int) $orders->converted,
                'net_revenue_minor' => (int) $orders->revenue, 'conversion_rate' => $assignments === 0 ? null : round((int) $orders->converted / $assignments, 4)];
        }

        return ['experiment' => $e->public_id, 'active' => $e->stopped_at === null, 'holdout' => $e->control_version_id === null, 'variant_bps' => $e->variant_bps,
            'arms' => $arms, 'currency' => 'EUR', 'causal_uplift' => null,
            'definitions' => ['orders' => 'Only signed-provider order data carrying a valid assignment and confirmed paid inside its 30-day window. Redirects and clicks are not payments.',
                'limitations' => 'Consented assigned visitors only; provider/storefront must propagate assignment_token. Missing tokens remain unattributed. No statistical significance or uplift claimed.']];
    }
}
