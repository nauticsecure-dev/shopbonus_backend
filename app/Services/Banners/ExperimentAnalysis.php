<?php

namespace App\Services\Banners;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\BannerCampaign;
use App\Models\BannerExperimentProtocol;
use App\Models\BannerExperimentReadout;
use App\Models\IntegrationOrderRecovery;
use App\Models\IntegrationSyncRun;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** One pre-registered fixed-horizon primary outcome, with no automatic rollout or financial writes. */
class ExperimentAnalysis
{
    public const METRIC = 'eur_captured_purchase_30d';

    public function __construct(private readonly ExperimentMeasurementProof $proofs, private readonly AuditLogger $audit) {}

    /** @param array<string,mixed> $input */
    public function register(BannerCampaign $campaign, array $input, User $actor): BannerExperimentProtocol
    {
        $this->authorize($actor);
        $v = Validator::make($input, ['control_version' => ['present', 'nullable', 'integer', 'min:1'], 'variant_version' => ['required', 'integer', 'min:1'],
            'variant_bps' => ['required', 'integer', 'between:1000,9000'], 'enrollment_days' => ['required', 'integer', 'between:1,30'],
            'min_visitors_per_arm' => ['required', 'integer', 'between:1000,10000000'], 'minimum_effect_bps' => ['required', 'integer', 'between:1,1000'],
            'ingestion_grace_hours' => ['required', 'integer', 'between:24,168'], 'review_reference' => ['required', 'string', 'max:200'],
            'measurement_evidence_ids' => ['required', 'array', 'min:1', 'max:50'], 'measurement_evidence_ids.*' => ['required', 'ulid', 'distinct']]);
        if ($v->fails()) {
            throw new ApiException('A complete reviewed experiment protocol is required.', 422, 'experiment_protocol_invalid');
        }
        $data = $v->validated();
        $measurement = $this->proofs->collect($campaign, $data['measurement_evidence_ids']);
        $protocol = ['version' => 1, 'metric' => self::METRIC, 'control_version' => $data['control_version'] === null ? null : (int) $data['control_version'],
            'variant_version' => (int) $data['variant_version'], 'variant_bps' => (int) $data['variant_bps'], 'enrollment_days' => (int) $data['enrollment_days'],
            'followup_days' => 30, 'min_visitors_per_arm' => (int) $data['min_visitors_per_arm'], 'minimum_effect_bps' => (int) $data['minimum_effect_bps'],
            'ingestion_grace_hours' => (int) $data['ingestion_grace_hours'], 'alpha' => 0.05, 'srm_alpha' => 0.001,
            'method' => 'fixed_horizon_two_proportion_z_wald95', 'review_reference' => $data['review_reference']];

        return DB::transaction(function () use ($campaign, $actor, $protocol, $measurement) {
            $c = BannerCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if (DB::table('banner_experiments')->where('active_campaign_id', $c->id)->exists()) {
                throw ApiException::conflict('Register the protocol before launching the study.', 'experiment_already_started');
            }
            $old = BannerExperimentProtocol::where('active_campaign_id', $c->id)->first();
            $old?->forceFill(['active_campaign_id' => null])->save();
            $plan = BannerExperimentProtocol::create(['campaign_id' => $c->id, 'active_campaign_id' => $c->id, 'created_by' => $actor->id,
                'protocol' => $protocol, 'measurement' => $measurement, 'protocol_hash' => $this->hash($protocol, $measurement)]);
            $this->audit->record('banner.protocol_registered', category: AuditCategory::Admin, resource: $plan, actorUserId: $actor->id, webshopId: $c->webshop_id,
                metadata: ['protocol_hash' => $plan->protocol_hash, 'metric' => self::METRIC]);

            return $plan;
        });
    }

    /** Called under the existing campaign launch lock. Legacy launches remain explicitly exploratory. */
    public function forLaunch(BannerCampaign $campaign, ?int $control, int $variant, int $bps): ?BannerExperimentProtocol
    {
        $plan = BannerExperimentProtocol::where('active_campaign_id', $campaign->id)->lockForUpdate()->first();
        $others = DB::table('banner_experiments')->join('banner_campaigns', 'banner_campaigns.id', '=', 'banner_experiments.campaign_id')
            ->where('banner_campaigns.webshop_id', $campaign->webshop_id)->whereNotNull('banner_experiments.active_campaign_id')
            ->where('banner_experiments.campaign_id', '!=', $campaign->id)->select('banner_experiments.*')->get();
        foreach ($others as $other) {
            $otherPlan = $other->analysis_protocol_id === null ? null : BannerExperimentProtocol::find($other->analysis_protocol_id);
            if ($plan !== null || ($otherPlan !== null && now()->lessThan($this->window($other, $otherPlan)['followup']))) {
                throw ApiException::conflict('The registered single-study bridge cannot share an overlapping workspace experiment.', 'experiment_workspace_interference');
            }
        }

        if ($plan === null) {
            return null;
        }
        $this->authorize(User::findOrFail($plan->created_by));
        $p = $plan->protocol;
        if ($p['control_version'] !== $control || $p['variant_version'] !== $variant || $p['variant_bps'] !== $bps) {
            throw ApiException::conflict('Launch versions/allocation must match the registered protocol.', 'experiment_protocol_mismatch');
        }
        $current = $this->proofs->collect($campaign, array_column($plan->measurement, 'evidence'));
        if ($current !== $plan->measurement || $this->hash($p, $current) !== $plan->protocol_hash) {
            throw ApiException::conflict('Measurement or the registered protocol has changed.', 'experiment_measurement_unverified');
        }
        $plan->forceFill(['active_campaign_id' => null])->save();

        return $plan;
    }

    public function canEnroll(object $experiment): bool
    {
        if (($experiment->analysis_protocol_id ?? null) === null) {
            return true;
        }
        $plan = BannerExperimentProtocol::find($experiment->analysis_protocol_id);

        return $plan !== null && now()->lessThan(CarbonImmutable::parse($experiment->started_at)->addDays($plan->protocol['enrollment_days']));
    }

    /** @return array<string,mixed> */
    public function report(object $experiment): array
    {
        if (($experiment->analysis_protocol_id ?? null) === null) {
            return $this->blocked(['analysis_plan_missing', 'measurement_unverified']);
        }
        $readout = BannerExperimentReadout::where('experiment_id', $experiment->id)->first();
        if ($readout !== null) {
            return $readout->result + ['readout' => $readout->public_id, 'as_of' => $readout->created_at->toIso8601String()];
        }
        $plan = BannerExperimentProtocol::find($experiment->analysis_protocol_id);
        if ($plan === null) {
            return $this->blocked(['analysis_plan_missing']);
        }
        $end = $this->window($experiment, $plan);
        $blocks = [];
        if (now()->lessThan($end['readout'])) {
            $blocks[] = 'followup_or_ingestion_window_incomplete';
        }
        if ($experiment->stopped_at !== null && CarbonImmutable::parse($experiment->stopped_at)->lessThan($end['followup'])) {
            $blocks[] = 'experiment_stopped_early';
        }

        return $this->blocked($blocks === [] ? ['readout_not_finalized'] : $blocks) + ['protocol' => $plan->public_id, 'readout_ready_at' => $end['readout']->toIso8601String()];
    }

    /** @param list<string> $closingEvidenceIds */
    public function finalize(int $experimentId, User $actor, array $closingEvidenceIds = []): BannerExperimentReadout
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($experimentId, $actor, $closingEvidenceIds) {
            $e = DB::table('banner_experiments')->where('id', $experimentId)->lockForUpdate()->firstOrFail();
            $existing = BannerExperimentReadout::where('experiment_id', $e->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            $plan = BannerExperimentProtocol::find($e->analysis_protocol_id);
            if ($plan === null || $plan->campaign_id !== $e->campaign_id || $plan->created_at->greaterThan(CarbonImmutable::parse($e->started_at))) {
                throw ApiException::conflict('No protocol was pinned before this launch.', 'experiment_protocol_missing');
            }
            $campaign = BannerCampaign::findOrFail($e->campaign_id);
            $context = $this->proofs->collect($campaign, array_column($plan->measurement, 'evidence'), false);
            if ($context !== $plan->measurement || $this->hash($plan->protocol, $context) !== $plan->protocol_hash) {
                throw ApiException::conflict('Measurement/source bindings changed during this study.', 'experiment_measurement_unverified');
            }
            $window = $this->window($e, $plan);
            if (now()->lessThan($window['readout']) || ($e->stopped_at !== null && CarbonImmutable::parse($e->stopped_at)->lessThan($window['followup']))) {
                throw ApiException::conflict('Complete the fixed enrollment, follow-up and ingestion windows without early stopping.', 'experiment_followup_incomplete');
            }
            if (now()->greaterThan($window['readout']->addDay())) {
                throw ApiException::conflict('The registered readout window was missed; a retrospective winner cannot be selected.', 'experiment_readout_window_missed');
            }
            $closing = $this->proofs->collect($campaign, $closingEvidenceIds);
            foreach ($context as $i => $source) {
                foreach (['connection_id', 'generation', 'domain', 'store_ref', 'pipeline_hash'] as $field) {
                    if (($closing[$i][$field] ?? null) !== $source[$field]) {
                        throw ApiException::conflict('Closing verification must cover the same measurement pipeline.', 'experiment_measurement_unverified');
                    }
                }
                if (CarbonImmutable::parse($closing[$i]['executed_at'])->lessThan($window['followup'])) {
                    throw ApiException::conflict('Run closing measurement verification after the fixed follow-up.', 'experiment_measurement_unverified');
                }
            }
            if (DB::table('banner_experiments as other')->join('banner_campaigns as c', 'c.id', '=', 'other.campaign_id')
                ->where('c.webshop_id', $campaign->webshop_id)->where('other.id', '!=', $e->id)->where('other.started_at', '<', $window['followup'])
                ->where(fn ($q) => $q->whereNull('other.stopped_at')->orWhere('other.stopped_at', '>', $e->started_at))->exists()) {
                throw ApiException::conflict('Another workspace experiment overlapped the registered observation window.', 'experiment_workspace_interference');
            }
            $sourceRuns = $this->reconciledSources($context, CarbonImmutable::parse($e->started_at), $window['readout']);
            $cohort = DB::table('banner_assignments')->where('experiment_id', $e->id)->lazyById(1000);
            $counts = ['control' => 0, 'variant' => 0];
            $cohortHash = hash_init('sha256');
            foreach ($cohort as $assignment) {
                $created = CarbonImmutable::parse($assignment->created_at);
                if (! array_key_exists($assignment->arm, $counts) || $assignment->revoked_at !== null || $created->lessThan(CarbonImmutable::parse($e->started_at))
                    || $created->greaterThanOrEqualTo($window['enrollment']) || ! CarbonImmutable::parse($assignment->expires_at)->equalTo($created->addDays(30))) {
                    throw ApiException::conflict('Assignment windows, consent or cohort integrity are incomplete.', 'experiment_cohort_invalid');
                }
                $counts[$assignment->arm]++;
                hash_update($cohortHash, $assignment->id.'|'.$assignment->arm.'|'.$assignment->token_hash."\n");
            }
            $orders = DB::table('webshop_orders as o')->join('banner_assignments as a', 'a.id', '=', 'o.banner_assignment_id')
                ->where('a.experiment_id', $e->id)->where('o.webshop_id', $campaign->webshop_id);
            if ((clone $orders)->where('o.currency', '!=', 'EUR')->exists()) {
                throw ApiException::conflict('This protocol does not cover non-EUR outcomes.', 'experiment_currency_mismatch');
            }
            $qualifying = (clone $orders)->whereIn('o.connection_id', array_column($context, 'connection_id'))->where('o.ingestion_status', 'complete')
                ->where('o.currency', 'EUR')
                ->whereExists(function ($q) {
                    $q->selectRaw('1')->from('webhook_events as ev')->join('webshop_connections as c', 'c.id', '=', 'ev.connection_id')
                        ->whereColumn('ev.connection_id', 'o.connection_id')->whereColumn('ev.webshop_id', 'o.webshop_id')->whereColumn('ev.credential_generation', 'c.credential_generation')
                        ->where('ev.source_method', 'native_api')->where('ev.status', 'processed')->where('ev.result->complete', true)->where('ev.result->ingested', true)
                        ->where('ev.result->native_payment_evidence', true)->where('ev.result->currency', 'EUR')->where('ev.result->amount_minor', '>', 0)
                        ->whereColumn('ev.result->native_paid_at_utc', '>=', 'a.created_at')->whereColumn('ev.result->native_paid_at_utc', '<=', 'a.expires_at')
                        ->whereColumn('ev.result->native_external_order_id', 'o.external_order_id')
                        ->whereColumn('ev.result->native_assignment_token_hash', 'a.token_hash');
                });
            // A linked paid outcome with no authenticated metadata/payment trail is unknown, never a non-conversion.
            $candidate = (clone $orders)->whereNotNull('o.paid_at')
                ->where(fn ($q) => $q->where('o.amount_minor', '>', 0)->orWhere('o.refunded_minor', '>', 0)->orWhereNull('o.amount_minor'))
                ->whereColumn('o.paid_at', '>=', 'a.created_at')->whereColumn('o.paid_at', '<=', 'a.expires_at');
            if ((clone $candidate)->whereNotIn('o.id', (clone $qualifying)->select('o.id'))->exists()) {
                throw ApiException::conflict('A linked purchase lacks complete native measurement evidence.', 'experiment_outcome_unverified');
            }
            $converted = [];
            $orderHash = hash_init('sha256');
            foreach (['control', 'variant'] as $arm) {
                $converted[$arm] = (clone $qualifying)->where('a.arm', $arm)->distinct()->count('a.id');
            }
            foreach ((clone $qualifying)->select(['o.id', 'o.banner_assignment_id', 'o.paid_at', 'o.provider_updated_at'])->lazyById(1000, 'o.id', 'id') as $order) {
                hash_update($orderHash, json_encode((array) $order, JSON_THROW_ON_ERROR)."\n");
            }
            $n = $counts['control'] + $counts['variant'];
            $expectedVariant = $n * $plan->protocol['variant_bps'] / 10000;
            $expectedControl = $n - $expectedVariant;
            $srm = $n === 0 ? null : ($counts['variant'] - $expectedVariant) ** 2 / $expectedVariant + ($counts['control'] - $expectedControl) ** 2 / $expectedControl;
            if ($srm !== null && $srm > 10.827566170662733) { // chi-square(1), alpha=0.001, pre-registered quality gate
                throw ApiException::conflict('Observed allocation fails the registered sample-ratio check.', 'experiment_sample_ratio_mismatch');
            }
            $comparison = (new ConversionComparison)->calculate($counts['control'], $converted['control'], $counts['variant'], $converted['variant']);
            $adequate = min($counts) >= $plan->protocol['min_visitors_per_arm'] && $comparison['status'] === 'exploratory';
            $threshold = $plan->protocol['minimum_effect_bps'] / 10000;
            $winner = null;
            if ($adequate && $comparison['p_value'] < 0.05) {
                $winner = $comparison['confidence_interval']['lower'] > $threshold ? 'variant' : ($comparison['confidence_interval']['upper'] < -$threshold ? 'control' : null);
            }
            $comparison['status'] = $adequate ? 'fixed_horizon' : 'insufficient_data';
            $comparison['winner'] = $winner;
            $comparison['winner_status'] = $adequate ? ($winner === null ? 'inconclusive' : 'minimum_effect_demonstrated') : 'insufficient_data';
            $result = ['status' => $adequate ? ($winner === null ? 'inconclusive' : 'evaluated') : 'insufficient_data', 'metric' => self::METRIC,
                'effect' => $adequate ? $comparison['absolute_difference'] : null, 'effect_unit' => 'conversion_probability_difference', 'winner' => $winner,
                'blockers' => [], 'automatic_rollout' => false, 'arms' => ['control' => ['visitors' => $counts['control'], 'converted' => $converted['control']],
                    'variant' => ['visitors' => $counts['variant'], 'converted' => $converted['variant']]], 'comparison' => $comparison, 'sample_ratio_chi_square' => $srm];
            $readout = BannerExperimentReadout::create(['experiment_id' => $e->id, 'protocol_id' => $plan->id, 'created_by' => $actor->id, 'result' => $result,
                'source_snapshot' => ['protocol_hash' => $plan->protocol_hash, 'source_runs' => $sourceRuns, 'assignment_digest' => hash_final($cohortHash),
                    'order_digest' => hash_final($orderHash), 'measurement' => $context, 'closing_measurement' => $closing, 'windows' => array_map(fn ($at) => $at->toIso8601String(), $window)]]);
            $this->audit->record('banner.readout_finalized', category: AuditCategory::Admin, resource: $readout, actorUserId: $actor->id, webshopId: $campaign->webshop_id,
                metadata: ['protocol_hash' => $plan->protocol_hash, 'status' => $result['status'], 'winner' => $winner]);

            return $readout;
        });
    }

    /** @param list<array<string,mixed>> $context
     * @return list<string>
     */
    private function reconciledSources(array $context, CarbonImmutable $start, CarbonImmutable $until): array
    {
        $runs = [];
        foreach ($context as $source) {
            $run = IntegrationSyncRun::where('connection_id', $source['connection_id'])->where('credential_generation', $source['generation'])
                ->where('verified_domain', $source['domain'])->where('provider_store_ref', $source['store_ref'])->where('status', 'completed')->where('discovery_complete', true)
                ->where('updated_at', '>=', $until)->where('since_at', '<=', $start->utc()->format('Y-m-d\TH:i:s\Z'))->where('until_at', '>=', $until->utc()->format('Y-m-d\TH:i:s\Z'))->latest('id')->first();
            if ($run === null) {
                throw ApiException::conflict('Complete native discovery/recovery through the follow-up cutoff first.', 'experiment_reconciliation_incomplete');
            }
            $recoveries = IntegrationOrderRecovery::where('bulk_run_id', $run->id)->get();
            foreach ($recoveries as $recovery) {
                $event = WebhookEvent::find($recovery->webhook_event_id);
                if ($recovery->status !== 'succeeded' || $event === null || ($event->result['ingested'] ?? false) !== true || ($event->result['complete'] ?? false) !== true) {
                    throw ApiException::conflict('Native discovery contains incomplete source observations.', 'experiment_reconciliation_incomplete');
                }
            }
            $runs[] = $run->public_id;
        }

        return $runs;
    }

    /** @return array<string,CarbonImmutable> */
    private function window(object $e, BannerExperimentProtocol $plan): array
    {
        $start = CarbonImmutable::parse($e->started_at);
        $enrollment = $start->addDays($plan->protocol['enrollment_days']);
        $followup = $enrollment->addDays(30);

        return ['enrollment' => $enrollment, 'followup' => $followup, 'readout' => $followup->addHours($plan->protocol['ingestion_grace_hours'])];
    }

    /** @param list<string> $reasons
     * @return array<string,mixed>
     */
    private function blocked(array $reasons): array
    {
        return ['status' => 'blocked', 'metric' => self::METRIC, 'effect' => null, 'effect_unit' => 'conversion_probability_difference',
            'winner' => null, 'blockers' => $reasons, 'automatic_rollout' => false];
    }

    /** @param array<string,mixed> $protocol
     * @param  list<array<string,mixed>>  $measurement
     */
    private function hash(array $protocol, array $measurement): string
    {
        return hash('sha256', json_encode([$protocol, $measurement], JSON_THROW_ON_ERROR));
    }

    private function authorize(User $actor): void
    {
        $user = User::find($actor->id);
        if (! $user?->isActive() || ! $user->isSuperAdmin()) {
            throw new ApiException('Active Super Admin authority is required.', 403, 'experiment_analysis_forbidden');
        }
    }
}
