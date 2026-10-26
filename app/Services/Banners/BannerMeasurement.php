<?php

namespace App\Services\Banners;

use App\Enums\WebshopStatus;
use App\Models\BannerCampaign;
use App\Models\BannerInstallation;
use App\Models\BannerVersion;
use App\Models\WebshopOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Banner events and results (Trello #24 §1–2, #25 §4 "Live stats").
 *
 * - Event IDs are client UUIDs: a retried batch is counted once.
 * - A dismissal (X) is stored as such and never counts as a click.
 * - CTR = CTA clicks / viewable impressions.
 * - A click ID is created only with consent; a paid order carrying it
 *   within 30 days is an attributed order (once); all else is unattributed.
 * - No PII: paths are stored without query strings.
 */
class BannerMeasurement
{
    public const TYPES = ['eligible', 'viewable_impression', 'cta_click', 'dismiss'];

    private const STAT_COLUMN = ['eligible' => 'eligible', 'viewable_impression' => 'viewable_impressions', 'cta_click' => 'cta_clicks', 'dismiss' => 'dismissals'];

    /**
     * Published config of live campaigns, for the public embed. Never drafts.
     *
     * @param  array<string, string>  $assignments
     * @return list<array<string, mixed>>
     */
    public function publishedConfig(BannerInstallation $installation, array $assignments = []): array
    {
        if (DB::table('webshops')->where('id', $installation->webshop_id)->value('status') !== WebshopStatus::Approved->value) {
            return [];
        }

        return BannerCampaign::where('webshop_id', $installation->webshop_id)->where('status', 'live')->whereNotNull('published_version_id')->orderBy('id')->get()
            ->map(function (BannerCampaign $c) use ($assignments) {
                $v = app(BannerExperiments::class)->selected($c, $assignments[$c->public_id] ?? null);

                return $v === null ? null : ['campaign' => $c->public_id, 'version' => $v['version'], 'config' => $this->publicConfig($v['config'])];
            })->filter()->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array{accepted: int, duplicates: int, rejected: int, click_ids: array<string, string>}
     */
    public function ingest(BannerInstallation $installation, array $events): array
    {
        $tz = (string) config('shopbonus.business_timezone');
        $campaigns = BannerCampaign::where('webshop_id', $installation->webshop_id)->whereIn('public_id', array_column($events, 'campaign'))->pluck('id', 'public_id');
        $out = ['accepted' => 0, 'duplicates' => 0, 'rejected' => 0, 'click_ids' => []];

        foreach ($events as $e) {
            $campaignId = $campaigns[$e['campaign']] ?? null;
            $at = CarbonImmutable::parse($e['occurred_at'])->utc();
            if ($campaignId === null || $at->isFuture() && $at->diffInMinutes(now()) > 5 || $at->lessThan(now()->subDays(2))) {
                $out['rejected']++;

                continue;
            }

            $experiments = app(BannerExperiments::class);
            $assignment = empty($e['assignment_token']) ? null : $experiments->assignment($campaignId, $e['assignment_token']);
            $hasToken = ! empty($e['assignment_token']);
            $valid = true;
            if ($hasToken) {
                $versionId = $assignment === null ? null : ($assignment->arm === 'variant' ? $assignment->variant_version_id : $assignment->control_version_id);
                $expected = $versionId === null ? 0 : BannerVersion::findOrFail($versionId)->version;
                $valid = $assignment !== null && ! empty($e['consent']) && (int) $e['version'] === $expected
                    && ($versionId !== null || $e['type'] === 'eligible')
                    && $at->greaterThanOrEqualTo(CarbonImmutable::parse($assignment->created_at))
                    && ($assignment->stopped_at === null || $at->lessThanOrEqualTo(CarbonImmutable::parse($assignment->stopped_at)));
            } elseif ($experiments->active($campaignId) !== null || ! BannerVersion::where('campaign_id', $campaignId)->where('version', $e['version'])->exists()) {
                $valid = false;
            }
            if (! $valid) {
                $out['rejected']++;

                continue;
            }
            try {
                DB::transaction(function () use ($e, $installation, $campaignId, $at, $tz, $assignment, &$out) {
                    DB::table('banner_events')->insert([
                        'event_id' => $e['id'], 'webshop_id' => $installation->webshop_id, 'campaign_id' => $campaignId, 'version' => (int) $e['version'],
                        'banner_assignment_id' => $assignment?->id, 'type' => $e['type'], 'device' => $e['device'] ?? null, 'path' => isset($e['path']) ? mb_substr((string) strtok((string) $e['path'], '?#'), 0, 255) : null,
                        'category' => $e['category'] ?? null, 'consent' => (bool) ($e['consent'] ?? false), 'occurred_at' => $at, 'created_at' => now(),
                    ]);
                    $day = $at->setTimezone($tz)->format('Y-m-d');
                    $device = $e['device'] ?? 'unknown';
                    DB::table('banner_daily_stats')->insertOrIgnore(['campaign_id' => $campaignId, 'day' => $day, 'device' => $device]);
                    DB::table('banner_daily_stats')->where('campaign_id', $campaignId)->where('day', $day)->where('device', $device)->increment(self::STAT_COLUMN[$e['type']]);

                    if ($e['type'] === 'cta_click' && ! empty($e['consent'])) {
                        $clickId = 'bc_'.Str::random(24);
                        DB::table('banner_clicks')->insert(['click_id' => $clickId, 'webshop_id' => $installation->webshop_id, 'campaign_id' => $campaignId,
                            'banner_assignment_id' => $assignment?->id, 'expires_at' => now()->addDays((int) config('shopbonus.banners.attribution_days', 30)), 'created_at' => now()]);
                        $out['click_ids'][$e['id']] = $clickId;
                    }
                    $out['accepted']++;
                });
            } catch (UniqueConstraintViolationException) {
                $out['duplicates']++;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function results(BannerCampaign $c, CarbonImmutable $fromDay, CarbonImmutable $toDay, ?string $device): array
    {
        $rows = DB::table('banner_daily_stats')->where('campaign_id', $c->id)->whereBetween('day', [$fromDay->format('Y-m-d'), $toDay->format('Y-m-d')])
            ->when($device !== null, fn ($q) => $q->where('device', $device))
            ->selectRaw('COALESCE(SUM(eligible),0) e, COALESCE(SUM(viewable_impressions),0) v, COALESCE(SUM(cta_clicks),0) k, COALESCE(SUM(dismissals),0) d')->first();
        $viewable = (int) $rows->v;
        $clicks = (int) $rows->k;

        // Attributed: paid orders of this webshop carrying a click ID of this campaign, paid within the click's window. Each order once.
        $tz = (string) config('shopbonus.business_timezone');
        $orders = WebshopOrder::query()->join('banner_clicks', 'banner_clicks.click_id', '=', 'webshop_orders.attribution_click_id')
            ->where('banner_clicks.campaign_id', $c->id)->where('webshop_orders.webshop_id', $c->webshop_id)->whereNotNull('webshop_orders.paid_at')
            ->whereColumn('webshop_orders.paid_at', '>=', 'banner_clicks.created_at')->whereColumn('webshop_orders.paid_at', '<=', 'banner_clicks.expires_at')
            ->whereBetween('webshop_orders.paid_at', [$fromDay->setTimezone($tz)->startOfDay()->utc(), $toDay->setTimezone($tz)->endOfDay()->utc()])
            ->selectRaw('COUNT(DISTINCT webshop_orders.id) n, COALESCE(SUM(webshop_orders.amount_minor - webshop_orders.refunded_minor),0) revenue')->first();

        return [
            'from' => $fromDay->format('Y-m-d'), 'to' => $toDay->format('Y-m-d'), 'device' => $device, 'timezone' => $tz,
            'eligible' => (int) $rows->e, 'viewable_impressions' => $viewable, 'cta_clicks' => $clicks, 'dismissals' => (int) $rows->d,
            'ctr' => $viewable === 0 ? null : round($clicks / $viewable, 4),
            'attributed_orders' => (int) $orders?->getAttribute('n'), 'attributed_revenue_minor' => (int) $orders?->getAttribute('revenue'),
            'definitions' => ['viewable_impression' => '≥50% visible for ≥1 second, deduplicated by the embed', 'ctr' => 'CTA clicks / viewable impressions; closing (X) is a dismissal, never a click',
                'attributed_orders' => 'Paid orders carrying a consented click ID of this banner, within 30 days of the click; all other orders are unattributed'],
        ];
    }

    /**
     * Only what the renderer needs; the schema has already stripped anything unsafe.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function publicConfig(array $config): array
    {
        if (! empty($config['content']['image_asset_id'])) {
            $config['content']['image_url'] = route('api.v1.banners.assets.show', ['id' => $config['content']['image_asset_id']]);
        }
        unset($config['content']['image_asset_id']);

        return $config;
    }
}
