<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\FaqItem;
use App\Models\PlatformSettingVersion;
use App\Models\SupportConversation;
use App\Models\SupportFeedback;
use App\Models\SupportKnowledgeVersion;
use App\Models\SupportMessage;
use App\Models\SupportRegression;
use App\Models\SupportReview;
use App\Services\Audit\AuditLogger;
use App\Services\Support\SupportKnowledge;
use App\Services\Support\SupportPlanner;
use App\Services\Support\SupportPolicy;
use App\Services\Support\SupportReviews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AiSupportController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $data = $r->validate(['outcome' => ['sometimes', Rule::in(['open', 'CONFIRMED_ERROR', 'ANSWER_CORRECT', 'NEEDS_INVESTIGATION'])]]);
        $q = SupportReview::query();
        if (($data['outcome'] ?? '') === 'open') {
            $q->whereNull('outcome');
        } elseif (isset($data['outcome'])) {
            $q->where('outcome', $data['outcome']);
        }
        $rows = $q->latest('id')->paginate(25);

        return response()->json(['data' => $rows->getCollection()->map(fn ($v) => ['id' => $v->public_id, 'message_id' => SupportMessage::findOrFail($v->message_id)->public_id,
            'trigger' => $v->trigger, 'outcome' => $v->outcome, 'reviewed_at' => $v->reviewed_at?->toIso8601String()])->all(), 'meta' => ['page' => $rows->currentPage(), 'total' => $rows->total()]]);
    }

    public function show(Request $r, string $review, AuditLogger $audit): JsonResponse
    {
        $v = $this->review($review);
        $m = SupportMessage::findOrFail($v->message_id);
        $audit->recordSafely('support.review_inspected', category: AuditCategory::Admin, resource: $v, actorUserId: $r->user()->id);

        return response()->json(['data' => ['id' => $v->public_id, 'conversation_id' => SupportConversation::findOrFail($m->conversation_id)->public_id,
            'message_id' => $m->public_id, 'question' => $m->question, 'result' => $m->result, 'evidence' => $m->evidence, 'research' => $m->research,
            'retry_used' => $m->retry_used, 'metrics' => $m->metrics, 'failure_code' => $m->failure_code,
            'feedback' => SupportFeedback::where('message_id', $m->id)->get()->map(fn ($f) => ['rating' => $f->rating, 'reason' => $f->reason, 'comment' => $f->comment])->all(),
            'outcome' => $v->outcome, 'note' => $v->note, 'regression_added' => SupportRegression::where('review_id', $v->id)->exists()]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function resolve(Request $r, string $review, SupportReviews $reviews): JsonResponse
    {
        $data = $r->validate(['outcome' => ['required', Rule::in(['CONFIRMED_ERROR', 'ANSWER_CORRECT', 'NEEDS_INVESTIGATION'])], 'note' => ['required', 'string', 'min:5', 'max:2000'],
            'expected_intent' => ['required_if:outcome,CONFIRMED_ERROR', Rule::in(SupportPlanner::INTENTS)], 'knowledge_id' => ['sometimes', 'nullable', 'ulid']]);
        $v = $reviews->resolve($r->user(), $this->review($review), $data);

        return response()->json(['data' => ['id' => $v->public_id, 'outcome' => $v->outcome, 'regression_added' => SupportRegression::where('review_id', $v->id)->exists()]]);
    }

    public function reply(Request $r, string $conversation, SupportReviews $reviews): JsonResponse
    {
        $body = $r->validate(['body' => ['required', 'string', 'min:2', 'max:4000']])['body'];
        $c = SupportConversation::where('public_id', strtolower($conversation))->first() ?? throw new ApiException('Support conversation not found.', 404, 'not_found');
        $reply = $reviews->reply($r->user(), $c, $body);

        return response()->json(['data' => ['id' => $reply->public_id, 'sender' => 'human_support', 'body' => $reply->body, 'created_at' => $reply->created_at->toIso8601String()]], 201);
    }

    public function testPolicy(Request $r, string $version, SupportPolicy $policy, AuditLogger $audit): JsonResponse
    {
        $v = PlatformSettingVersion::where('public_id', strtolower($version))->first() ?? throw new ApiException('Setting version not found.', 404, 'not_found');
        $report = $policy->testVersion($v, $r->user());
        $audit->recordSafely('support.policy_tested', category: AuditCategory::Admin, resource: $v, actorUserId: $r->user()->id, metadata: $report);

        return response()->json(['data' => $report]);
    }

    /** @return array{currency: string, amount_minor: int, basis: string}|null */
    private static function costEstimate(int $input, int $output): ?array
    {
        $in = config('support_ai.price_eur_per_million_input');
        $out = config('support_ai.price_eur_per_million_output');
        if (! is_numeric($in) || ! is_numeric($out) || (float) $in < 0 || (float) $out < 0) {
            return null;
        }

        return ['currency' => 'EUR', 'amount_minor' => (int) round(($input * (float) $in + $output * (float) $out) / 1_000_000 * 100),
            'basis' => 'configured_token_prices'];
    }

    public function operations(SupportPolicy $policy): JsonResponse
    {
        $from = now()->utc()->subDay();
        $rows = SupportMessage::where('created_at', '>=', $from)->latest('id')->limit(500)->get();
        $latency = $rows->pluck('metrics.latency_ms')->filter(fn ($v) => is_int($v))->sort()->values();

        return response()->json(['data' => ['from' => $from->toIso8601String(), 'checked_at' => now()->toIso8601String(), 'sample_limit' => 500, 'sample_size' => $rows->count(),
            'statuses' => $rows->countBy('status')->all(), 'failures' => $rows->whereNotNull('failure_code')->countBy('failure_code')->all(),
            'retrieval_retries' => $rows->where('retry_used', true)->count(), 'latency_p95_ms' => $latency->isEmpty() ? null : $latency[(int) ceil($latency->count() * .95) - 1],
            'input_tokens' => $rows->sum('metrics.input_tokens'), 'output_tokens' => $rows->sum('metrics.output_tokens'), 'provider_cost' => null,
            'provider_cost_estimate' => self::costEstimate((int) $rows->sum('metrics.input_tokens'), (int) $rows->sum('metrics.output_tokens')),
            'cost_notice' => 'Token usage is measured; billed provider cost is not available here. The estimate uses operator-configured prices.',
            'open_reviews' => SupportReview::whereNull('outcome')->count(), 'approved_regressions' => SupportRegression::count(),
            'daily_requests' => (int) (DB::table('support_quotas')->where('day', now()->utc()->toDateString())->where('scope', 'global')->value('requests') ?? 0),
            'circuit_open' => Cache::has('support-ai:circuit'), 'broadcaster' => config('broadcasting.default'), 'last_successful_broadcast_at' => Cache::get('support-ai:last-broadcast-at'),
            'broadcast_failures_last_24h' => DB::table('audit_events')->where('action', 'support.broadcast_unavailable')->where('occurred_at', '>=', $from)->count(),
            'policy_version' => $policy->current()['version']]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function knowledgeVersions(string $document): JsonResponse
    {
        $faq = FaqItem::where('public_id', strtolower($document))->first() ?? throw new ApiException('FAQ item not found.', 404, 'not_found');
        $versions = SupportKnowledgeVersion::where('faq_id', $faq->id)->orderByDesc('revision')->limit(100)->get();

        return response()->json(['data' => $versions->map(fn ($v) => ['id' => $v->public_id, 'revision' => $v->revision, 'snapshot' => $v->snapshot, 'checksum' => $v->checksum, 'origin' => $v->origin, 'recorded_at' => $v->created_at->toIso8601String()])->all()]);
    }

    public function rollbackKnowledge(Request $r, string $document, SupportKnowledge $knowledge): JsonResponse
    {
        $data = $r->validate(['version_id' => ['required', 'ulid'], 'revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $faq = FaqItem::where('public_id', strtolower($document))->first() ?? throw new ApiException('FAQ item not found.', 404, 'not_found');
        $v = SupportKnowledgeVersion::where('public_id', strtolower($data['version_id']))->first() ?? throw new ApiException('Published knowledge not found.', 404, 'not_found');
        $faq = $knowledge->rollback($faq, $v, $data['revision'], $r->user(), $data['reason']);

        return response()->json(['data' => ['document_id' => $faq->public_id, 'revision' => $faq->revision, 'published' => $faq->published, 'audience' => $faq->audience]]);
    }

    private function review(string $id): SupportReview
    {
        return SupportReview::where('public_id', strtolower($id))->first() ?? throw new ApiException('Support review not found.', 404, 'not_found');
    }
}
