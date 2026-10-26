<?php

namespace App\Services\Banners;

use App\Enums\AuditCategory;
use App\Enums\UserStatus;
use App\Enums\WebshopStatus;
use App\Enums\WorkspacePermission;
use App\Exceptions\ApiException;
use App\Jobs\GenerateBannerProposals;
use App\Models\BannerAiRequest;
use App\Models\BannerAiResult;
use App\Models\BannerAiReview;
use App\Models\BannerCampaign;
use App\Models\Offer;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopMembership;
use App\Models\WebshopOrder;
use App\Services\Admin\Diagnostics\ProviderFailure;
use App\Services\Audit\AuditLogger;
use App\Services\Funding\MerchantFunding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class BannerAi
{
    public function __construct(private readonly BannerAiProvider $provider, private readonly AuditLogger $audit, private readonly BannerService $banners) {}

    public function allowed(int $webshopId, int $userId, string $kind): bool
    {
        $member = WebshopMembership::where('webshop_id', $webshopId)->where('user_id', $userId)->first();
        $user = User::find($userId);

        return $member !== null && $member->allows(WorkspacePermission::OffersManage)
            && ($kind !== 'insights' || $member->allows(WorkspacePermission::OrdersView))
            && $user?->status === UserStatus::Active && $user->email_verified_at !== null
            && Webshop::find($webshopId)?->status === WebshopStatus::Approved;
    }

    public function request(BannerCampaign $campaign, int $actorId, string $kind, string $key): BannerAiRequest
    {
        if (! $this->allowed($campaign->webshop_id, $actorId, $kind)) {
            throw new ApiException('This action requires current banner and order permissions.', 403, 'banner_ai_forbidden');
        }

        return DB::transaction(function () use ($campaign, $actorId, $kind, $key): BannerAiRequest {
            $day = now()->utc()->toDateString();
            DB::table('banner_ai_quotas')->upsert([['day' => $day, 'requests' => 0]], ['day'], ['day']);
            $quota = DB::table('banner_ai_quotas')->where('day', $day)->lockForUpdate()->first();
            $existing = BannerAiRequest::where('webshop_id', $campaign->webshop_id)->where('request_key', $key)->first();
            if ($existing !== null) {
                if ($existing->requested_by !== $actorId || $existing->campaign_id !== $campaign->id || $existing->kind !== $kind) {
                    throw ApiException::conflict('The request key belongs to another AI request.', 'request_key_conflict');
                }

                return $existing;
            }
            if (! $this->provider->configured()) {
                throw new ApiException('Banner AI is not configured.', 503, 'banner_ai_unavailable');
            }
            if ((int) $quota->requests >= max(0, min(1000, (int) config('banner_ai.daily_request_limit')))) {
                throw new ApiException('The banner AI daily request limit has been reached.', 429, 'banner_ai_limit',
                    headers: ['Retry-After' => (string) max(1, (int) now()->utc()->diffInSeconds(now()->utc()->addDay()->startOfDay()))]);
            }
            $campaign = BannerCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $context = $this->context($campaign, $kind);
            $run = BannerAiRequest::create(['webshop_id' => $campaign->webshop_id, 'campaign_id' => $campaign->id, 'requested_by' => $actorId,
                'request_key' => $key, 'kind' => $kind, 'status' => 'queued', 'revision' => $campaign->draft_revision,
                'source_offer_version_id' => $campaign->offer_id === null ? null : Offer::findOrFail($campaign->offer_id)->current_version_id,
                'model' => config('banner_ai.model'), 'context' => $context, 'source_config' => $campaign->draft_config,
                'context_hash' => $this->hash([$context, $campaign->draft_config])]);
            DB::table('banner_ai_quotas')->where('day', $day)->increment('requests');
            $this->audit->record('banner.ai_requested', category: AuditCategory::Workspace, resource: $run, webshopId: $campaign->webshop_id, actorUserId: $actorId);
            GenerateBannerProposals::dispatch($run->id)->afterCommit();

            return $run;
        });
    }

    public function execute(int $id): void
    {
        if (BannerAiRequest::whereKey($id)->where('status', 'queued')->update(['status' => 'running', 'updated_at' => now()]) !== 1) {
            return;
        }
        $run = BannerAiRequest::findOrFail($id);
        try {
            if (! $this->allowed($run->webshop_id, $run->requested_by, $run->kind)) {
                throw new ProviderFailure('requester_no_longer_authorized');
            }
            if (! hash_equals($run->context_hash, $this->hash([$run->context, $run->source_config]))) {
                throw new ProviderFailure('context_integrity_failed');
            }
            $result = $this->provider->generate($run->context, $run->model);
            foreach ($result['proposals'] as $proposal) {
                app(BannerConfig::class)->validate($this->editable($run, $proposal), $run->webshop_id);
            }
            DB::transaction(function () use ($run, $result) {
                $locked = BannerAiRequest::whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'running') {
                    return;
                }
                BannerAiResult::create(['request_id' => $run->id] + $result + ['created_at' => now()]);
                $locked->forceFill(['status' => 'completed'])->save();
                $this->audit->record('banner.ai_completed', category: AuditCategory::Workspace, resource: $run, webshopId: $run->webshop_id);
            });
        } catch (Throwable $e) {
            $this->fail($id, $e instanceof ProviderFailure ? $e->failureCode : 'invalid_proposal');
        }
    }

    public function fail(int $id, string $code): void
    {
        if (BannerAiRequest::whereKey($id)->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'failure_code' => $code, 'updated_at' => now()]) === 1) {
            $run = BannerAiRequest::findOrFail($id);
            $this->audit->recordSafely('banner.ai_failed', category: AuditCategory::Workspace, resource: $run, webshopId: $run->webshop_id, metadata: ['failure_code' => $code]);
        }
    }

    public function review(BannerCampaign $campaign, BannerAiRequest $run, int $actorId, string $decision, ?int $index, string $note): ?BannerCampaign
    {
        if ($campaign->id !== $run->campaign_id || $campaign->webshop_id !== $run->webshop_id) {
            throw new ApiException('Banner not found.', 404, 'not_found');
        }

        return DB::transaction(function () use ($campaign, $run, $actorId, $decision, $index, $note): ?BannerCampaign {
            $campaign = BannerCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $run = BannerAiRequest::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! $this->allowed($run->webshop_id, $actorId, $run->kind)) {
                throw new ApiException('This action requires current banner and order permissions.', 403, 'banner_ai_forbidden');
            }
            if ($run->status !== 'completed' || BannerAiReview::where('request_id', $run->id)->exists()) {
                throw ApiException::conflict('Only an unreviewed completed request can be reviewed.', 'banner_ai_review_conflict');
            }
            $draft = null;
            if ($decision === 'accept') {
                $currentOfferVersion = $campaign->offer_id === null ? null : Offer::findOrFail($campaign->offer_id)->current_version_id;
                if ($campaign->draft_revision !== $run->revision || $currentOfferVersion !== $run->source_offer_version_id) {
                    throw ApiException::conflict('The source draft changed. Generate a fresh proposal.', 'banner_ai_stale');
                }
                $result = BannerAiResult::where('request_id', $run->id)->firstOrFail();
                $proposal = $result->getAttribute('proposals')[$index];
                $offer = $campaign->offer_id === null ? null : Offer::findOrFail($campaign->offer_id);
                $draft = $this->banners->create(Webshop::findOrFail($run->webshop_id), mb_substr($campaign->name.' — AI test '.($index + 1), 0, 120), $offer, $this->editable($run, $proposal), $actorId);
            }
            BannerAiReview::create(['request_id' => $run->id, 'reviewer_id' => $actorId, 'decision' => $decision, 'proposal' => $index,
                'draft_campaign_id' => $draft?->id, 'note' => $note, 'created_at' => now()]);
            $this->audit->record('banner.ai_reviewed', category: AuditCategory::Workspace, resource: $run, webshopId: $run->webshop_id, actorUserId: $actorId,
                metadata: ['decision' => $decision, 'proposal' => $index, 'draft' => $draft?->public_id]);

            return $draft;
        });
    }

    /** @return array<string, mixed> */
    private function context(BannerCampaign $campaign, string $kind): array
    {
        $base = $campaign->draft_config;
        $evidence = ['E1' => ['template' => $base['template'], 'anchor' => $base['placement']['anchor'], 'style' => array_intersect_key($base['style'], array_flip(['background', 'text', 'font']))]];
        $offer = $campaign->offer_id === null ? null : Offer::with('currentVersion')->find($campaign->offer_id);
        if ($offer?->currentVersion !== null) {
            $evidence['E2'] = app(MerchantFunding::class)->costOf($offer->currentVersion) + ['min_order_minor' => $offer->currentVersion->min_order_minor, 'currency' => 'EUR'];
        }
        if ($kind === 'insights') {
            // Fetch only numeric fields, never customer details, IDs, payloads or URLs.
            $orders = WebshopOrder::where('webshop_id', $campaign->webshop_id)->where('ingestion_status', 'complete')
                ->whereIn('payment_status', ['paid', 'partially_refunded'])->whereNotNull('paid_at')->where('currency', 'EUR')->whereNotNull('amount_minor')
                ->whereBetween('paid_at', [now()->subDays(90), now()])->orderByDesc('paid_at')->orderByDesc('id')->limit(1000)->get(['amount_minor', 'refunded_minor']);
            if ($orders->count() < 10) {
                throw ApiException::conflict('At least ten verified paid EUR orders are needed for insights.', 'banner_ai_insufficient_evidence');
            }
            $evidence['E3'] = ['sample_size' => $orders->count(), 'window_days' => 90, 'currency' => 'EUR',
                'net_revenue_minor' => (int) $orders->sum(fn ($o) => max(0, $o->amount_minor - $o->refunded_minor))];
            $metrics = app(BannerMeasurement::class)->results($campaign, CarbonImmutable::now()->subDays(89), CarbonImmutable::now(), null);
            $evidence['E4'] = array_intersect_key($metrics, array_flip(['viewable_impressions', 'cta_clicks', 'ctr', 'attributed_orders', 'attributed_revenue_minor']));
        }

        return ['kind' => $kind, 'evidence' => $evidence];
    }

    /** @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    public function editable(BannerAiRequest $run, array $proposal): array
    {
        $config = $run->source_config;
        $config['placement']['anchor'] = $proposal['anchor'];
        foreach (['background', 'text', 'font'] as $key) {
            $config['style'][$key] = $proposal[$key];
        }
        // Existing copy, CTA URL, merchant image/logo, offer terms and restrictions survive unchanged.
        if ($run->kind === 'insights') {
            $children = $config['triggers']['all'] ?? [$config['triggers']];
            $children[] = ['type' => 'min_seconds', 'value' => $proposal['delay_seconds']];
            if ($proposal['cart_threshold_minor'] !== null) {
                $children[] = ['type' => 'cart_min_minor', 'value' => $proposal['cart_threshold_minor']];
            }
            $config['triggers'] = ['all' => $children];
        }

        return $config;
    }

    /** @param array<mixed> $value */
    private function hash(array $value): string
    {
        $sort = function (mixed $v) use (&$sort): mixed {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR));
    }
}
