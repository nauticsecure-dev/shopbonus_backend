<?php

namespace App\Services\Support;

use App\Models\SupportMessage;
use App\Models\SupportReview;
use Illuminate\Database\Eloquent\Builder;

/** Full filtered observations; never confuses execution states with human quality reviews. */
final class SupportOperations
{
    /** @param Builder<SupportMessage> $query
     * @return array<string, mixed>
     */
    public function aggregate(Builder $query): array
    {
        $query = (clone $query)->where('id', '<=', (clone $query)->max('id') ?? 0);
        $total = $input = $output = $retries = $costed = $latencyCount = 0;
        $cost = 0.0;
        $statuses = $failures = $latencies = [];
        $coverage = ['input_tokens' => 0, 'output_tokens' => 0, 'latency_ms' => 0];
        foreach ((clone $query)->select(['id', 'status', 'failure_code', 'retry_used', 'metrics'])->lazyById(250) as $row) {
            $total++;
            $statuses[$row->status] = ($statuses[$row->status] ?? 0) + 1;
            if ($row->failure_code !== null) {
                $failures[$row->failure_code] = ($failures[$row->failure_code] ?? 0) + 1;
            }
            $retries += (int) $row->retry_used;
            $metrics = $row->metrics ?? [];
            foreach (array_keys($coverage) as $field) {
                if (! is_int($metrics[$field] ?? null) || $metrics[$field] < 0) {
                    continue;
                }
                $coverage[$field]++;
                match ($field) {
                    'input_tokens' => $input += $metrics[$field],
                    'output_tokens' => $output += $metrics[$field],
                    'latency_ms' => $latencies[$metrics[$field]] = ($latencies[$metrics[$field]] ?? 0) + 1,
                };
            }
            $estimate = $metrics['estimated_cost_eur'] ?? null;
            if (is_numeric($estimate) && is_finite((float) $estimate) && (float) $estimate >= 0) {
                $costed++;
                $cost += (float) $estimate;
            }
        }
        ksort($statuses);
        ksort($latencies, SORT_NUMERIC);
        $rank = (int) ceil(array_sum($latencies) * .95);
        $p95 = null;
        foreach ($latencies as $ms => $count) {
            $latencyCount += $count;
            if ($latencyCount >= $rank) {
                $p95 = $ms;
                break;
            }
        }
        $reviews = SupportReview::whereIn('message_id', (clone $query)->select('id'));
        $resolved = (clone $reviews)->whereNotNull('reviewed_at')->whereIn('outcome', ['ANSWER_CORRECT', 'CONFIRMED_ERROR', 'NEEDS_INVESTIGATION']);
        $outcomes = (clone $resolved)->selectRaw('outcome, COUNT(*) as aggregate')->groupBy('outcome')->pluck('aggregate', 'outcome')->map(fn ($v) => (int) $v)->all();
        $reviewed = (clone $resolved)->distinct()->count('message_id');
        $percentages = static fn (array $counts, int $denominator): array => array_map(static fn (int $count) => round($count * 100 / max(1, $denominator), 2), $counts);
        $execution = $percentages($statuses, $total);

        return [
            'aggregation_scope' => 'full_filtered_dataset', 'sample_limit' => null, 'sample_size' => $total, 'matching_total' => $total,
            'sample_fields_notice' => 'Legacy sample_size equals the full observed matching count; no row cap is applied.',
            'execution_status_percentages' => $execution,
            'outcome_percentages' => $execution,
            'outcome_percentages_basis' => 'deprecated_execution_status_alias_use_reviewed_outcome_percentages_for_quality',
            'reviewed_outcome_percentages' => $percentages($outcomes, array_sum($outcomes)),
            'reviewed_outcomes' => $outcomes, 'resolved_review_total' => array_sum($outcomes),
            'reviewed_outcome_denominator' => 'resolved_review_records', 'reviewed_message_total' => $reviewed,
            'unreviewed_message_total' => max(0, $total - $reviewed), 'matching_open_reviews' => (clone $reviews)->whereNull('outcome')->count(),
            'quality_notice' => 'One message can have several review triggers. Percentages count resolved review records, not all answers or a representative live-quality evaluation.',
            'statuses' => $statuses, 'failures' => $failures, 'retrieval_retries' => $retries, 'latency_p95_ms' => $p95,
            'input_tokens' => $input, 'output_tokens' => $output, 'metric_coverage' => $coverage,
            'provider_cost' => $costed === 0 ? null : round($cost, 8), 'costed_requests' => $costed, 'cost_currency' => 'EUR',
        ];
    }
}
