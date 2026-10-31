<?php

namespace App\Services\Enrichment;

use App\Enums\AuditCategory;
use App\Enums\WebshopStatus;
use App\Exceptions\ApiException;
use App\Jobs\ProcessMerchantEnrichment;
use App\Models\MerchantEnrichmentReview;
use App\Models\MerchantEnrichmentRun;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopPublicProfile;
use App\Services\Admin\Diagnostics\ProviderFailure;
use App\Services\Audit\AuditLogger;
use App\Services\Discovery\DiscoveryCache;
use App\Services\Discovery\PublicProfileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class EnrichmentPipeline
{
    public function __construct(
        private readonly WebsiteResearcher $researcher,
        private readonly MerchantProfileAi $profileAi,
        private readonly MerchantCoverGenerator $covers,
        private readonly AuditLogger $audit,
    ) {}

    public function configured(): bool
    {
        return $this->profileAi->configured();
    }

    /** @return list<Webshop> */
    public function eligibleWebshops(int $limit): array
    {
        $staleDays = max(1, (int) config('enrichment.stale_days'));

        $blocked = MerchantEnrichmentRun::query()
            ->select('webshop_id')
            ->where(function ($q) use ($staleDays) {
                $q->whereIn('status', ['queued', 'running', 'awaiting_review'])
                    ->orWhere(fn ($q2) => $q2->where('status', 'published')->where('published_at', '>=', now()->subDays($staleDays)));
            });

        return Webshop::query()
            ->where('status', WebshopStatus::Approved)
            ->whereNotNull('verified_domain')
            ->whereDoesntHave('publicProfile', fn ($q) => $q->where('published', true))
            ->whereNotIn('id', $blocked)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function enqueue(Webshop $webshop, string $trigger = 'scheduled'): MerchantEnrichmentRun
    {
        if (! $this->configured()) {
            throw new ApiException('Merchant enrichment is not configured.', 503, 'enrichment_not_configured');
        }
        if ($webshop->status !== WebshopStatus::Approved || $webshop->verified_domain === null) {
            throw ApiException::conflict('This webshop is not eligible for enrichment.', 'enrichment_not_eligible');
        }

        return DB::transaction(function () use ($webshop, $trigger) {
            $day = now()->utc()->toDateString();
            DB::table('merchant_enrichment_quotas')->upsert([['day' => $day, 'runs' => 0]], ['day'], ['day']);
            $quota = DB::table('merchant_enrichment_quotas')->where('day', $day)->lockForUpdate()->first();
            $limit = max(0, min(10000, (int) config('enrichment.daily_run_limit')));
            if ((int) $quota->runs >= $limit) {
                throw new ApiException('The daily enrichment run limit has been reached.', 429, 'enrichment_daily_limit');
            }

            if (MerchantEnrichmentRun::where('webshop_id', $webshop->id)->whereIn('status', ['queued', 'running'])->exists()) {
                throw ApiException::conflict('An enrichment run is already in progress for this webshop.', 'enrichment_in_progress');
            }

            $sourceUrl = 'https://'.$webshop->verified_domain.'/';
            try {
                $research = $this->researcher->research($sourceUrl, (int) config('enrichment.fetch_max_bytes'));
            } catch (EnrichmentFailure $e) {
                throw new ApiException('The merchant website could not be researched safely.', 422, $e->failureCode);
            }
            $run = MerchantEnrichmentRun::create([
                'webshop_id' => $webshop->id,
                'status' => 'queued',
                'trigger' => $trigger,
                'source_url' => $sourceUrl,
                'research' => $research,
                'research_hash' => $this->researcher->hash($research),
                'text_model' => (string) config('enrichment.text_model'),
                'image_model' => $this->covers->configured() ? (string) config('enrichment.image_model') : null,
                'review_required' => true,
            ]);
            DB::table('merchant_enrichment_quotas')->where('day', $day)->increment('runs');
            $this->audit->record('admin.merchant_enrichment_queued', category: AuditCategory::Admin, resource: $run, webshopId: $webshop->id,
                metadata: ['trigger' => $trigger, 'research_hash' => $run->research_hash]);
            ProcessMerchantEnrichment::dispatch($run->id)->afterCommit();

            return $run;
        }, 3);
    }

    public function execute(int $runId): void
    {
        if (MerchantEnrichmentRun::whereKey($runId)->where('status', 'queued')->update(['status' => 'running', 'updated_at' => now()]) !== 1) {
            return;
        }

        $run = MerchantEnrichmentRun::with('webshop')->findOrFail($runId);
        if (! hash_equals($run->research_hash, $this->researcher->hash($run->research))
            && ! hash_equals($run->research_hash, $this->researcher->legacyHash($run->research))) {
            $this->fail($runId, 'research_integrity_failed');

            return;
        }

        try {
            $result = $this->profileAi->propose($run->research, $run->webshop->name, $run->text_model);
        } catch (ProviderFailure $e) {
            $this->fail($runId, $e->failureCode);

            return;
        } catch (Throwable) {
            $this->fail($runId, 'invalid_provider_output');

            return;
        }

        $coverPath = null;
        $coverMime = null;
        $coverFailure = null;
        if ($run->image_model !== null) {
            try {
                $cover = $this->covers->generate($run->webshop->public_id, $run->webshop->name, $result['proposal'], $run->image_model);
                if ($cover !== null) {
                    $coverPath = $cover['path'];
                    $coverMime = $cover['mime'];
                }
            } catch (ProviderFailure $e) {
                $coverFailure = $e->failureCode;
            }
        }

        DB::transaction(function () use ($run, $result, $coverPath, $coverMime, $coverFailure) {
            $locked = MerchantEnrichmentRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'running') {
                return;
            }

            $reviewRequired = (bool) $result['proposal']['review_required'] || $result['proposal']['risk_level'] !== 'low';
            $locked->forceFill([
                'proposal' => $result['proposal'],
                'review_required' => $reviewRequired,
                'risk_level' => $result['proposal']['risk_level'],
                'cover_path' => $coverPath,
                'cover_mime' => $coverMime,
                'cover_failure_code' => $coverFailure,
                'input_tokens' => $result['input_tokens'],
                'output_tokens' => $result['output_tokens'],
                'status' => 'awaiting_review',
                'finished_at' => now(),
            ])->save();

            $this->audit->record('admin.merchant_enrichment_completed', category: AuditCategory::Admin, resource: $locked, webshopId: $locked->webshop_id,
                metadata: ['review_required' => $reviewRequired, 'risk_level' => $locked->risk_level, 'cover_failure_code' => $coverFailure]);

            if (! $reviewRequired && config('enrichment.auto_publish')) {
                $this->publishLocked($locked);
            }
        });
    }

    public function fail(int $runId, string $code): void
    {
        DB::transaction(function () use ($runId, $code) {
            $run = MerchantEnrichmentRun::whereKey($runId)->lockForUpdate()->first();
            if ($run === null || ! in_array($run->status, ['queued', 'running'], true)) {
                return;
            }
            $run->forceFill(['status' => 'failed', 'failure_code' => $code, 'finished_at' => now()])->save();
            $this->audit->recordSafely('admin.merchant_enrichment_failed', category: AuditCategory::Admin, resource: $run, webshopId: $run->webshop_id, metadata: ['failure_code' => $code]);
        });
    }

    public function approve(MerchantEnrichmentRun $run, User $actor, string $note): MerchantEnrichmentReview
    {
        return DB::transaction(function () use ($run, $actor, $note) {
            $run = MerchantEnrichmentRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! in_array($run->status, ['awaiting_review', 'completed'], true) || $run->proposal === null) {
                throw ApiException::conflict('Only completed enrichment proposals can be approved.', 'enrichment_not_reviewable');
            }
            $this->assertReviewer($run, $actor);
            $review = MerchantEnrichmentReview::create(['run_id' => $run->id, 'reviewed_by' => $actor->id, 'decision' => 'approved', 'note' => $note, 'created_at' => now()]);
            $this->publishLocked($run);
            $this->audit->record('admin.merchant_enrichment_approved', category: AuditCategory::Admin, resource: $run, actorUserId: $actor->id,
                metadata: ['review' => $review->public_id]);

            return $review;
        });
    }

    public function reject(MerchantEnrichmentRun $run, User $actor, string $note): MerchantEnrichmentReview
    {
        return DB::transaction(function () use ($run, $actor, $note) {
            $run = MerchantEnrichmentRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! in_array($run->status, ['awaiting_review', 'completed'], true)) {
                throw ApiException::conflict('Only completed enrichment proposals can be rejected.', 'enrichment_not_reviewable');
            }
            $this->assertReviewer($run, $actor);
            $run->forceFill(['status' => 'rejected'])->save();
            $review = MerchantEnrichmentReview::create(['run_id' => $run->id, 'reviewed_by' => $actor->id, 'decision' => 'rejected', 'note' => $note, 'created_at' => now()]);
            $this->audit->record('admin.merchant_enrichment_rejected', category: AuditCategory::Admin, resource: $run, actorUserId: $actor->id,
                metadata: ['review' => $review->public_id]);

            return $review;
        });
    }

    public function retry(MerchantEnrichmentRun $run, User $actor): MerchantEnrichmentRun
    {
        if (! in_array($run->status, ['failed', 'rejected'], true)) {
            throw ApiException::conflict('Only failed or rejected enrichments can be retried.', 'enrichment_not_retryable');
        }

        $newRun = $this->enqueue($run->webshop()->firstOrFail(), 'retry');

        return $newRun->fresh();
    }

    private function assertReviewer(MerchantEnrichmentRun $run, User $actor): void
    {
        if (MerchantEnrichmentReview::where('run_id', $run->id)->where('reviewed_by', $actor->id)->exists()) {
            throw ApiException::conflict('You have already reviewed this enrichment.', 'enrichment_already_reviewed');
        }
    }

    private function publishLocked(MerchantEnrichmentRun $run): void
    {
        if ($run->status === 'published' || $run->proposal === null) {
            return;
        }

        $webshop = Webshop::findOrFail($run->webshop_id);
        $profile = WebshopPublicProfile::firstOrCreate(
            ['webshop_id' => $webshop->id],
            ['display_name' => $webshop->name, 'slug' => $this->uniqueSlug($webshop->name)]
        );

        $proposal = $run->proposal;
        if (trim((string) $profile->short_description) === '' && isset($proposal['short_description'])) {
            $profile->short_description = $proposal['short_description'];
        }
        if (trim((string) $profile->description) === '' && isset($proposal['description'])) {
            $profile->description = $proposal['description'];
        }
        if (trim((string) $profile->website_url) === '') {
            $profile->website_url = $run->source_url;
        }

        if ($run->cover_path !== null) {
            if ($profile->cover_path !== null && $profile->cover_path !== $run->cover_path) {
                Storage::disk(PublicProfileService::DISK)->delete($profile->cover_path);
            }
            $profile->cover_path = $run->cover_path;
            $profile->cover_mime = $run->cover_mime;
            $profile->cover_url = null;
        }

        $profile->save();

        if (isset($proposal['categories']) && is_array($proposal['categories']) && $proposal['categories'] !== []) {
            $existing = $profile->categories();
            if ($existing === []) {
                DB::table('webshop_public_categories')->insert(array_map(
                    fn (string $c) => ['webshop_id' => $webshop->id, 'category' => $c],
                    array_values(array_unique($proposal['categories']))
                ));
                DiscoveryCache::flush();
            }
        }

        $run->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $this->audit->record('admin.merchant_enrichment_published', category: AuditCategory::Admin, resource: $run, webshopId: $webshop->id);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 60, '') ?: 'webshop';
        for ($i = 1; $i < 50; $i++) {
            $slug = $i === 1 ? $base : "{$base}-{$i}";
            if (! WebshopPublicProfile::where('slug', $slug)->exists()) {
                return $slug;
            }
        }

        return $base.'-'.Str::lower(Str::random(6));
    }
}
