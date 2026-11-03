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
use App\Services\Support\SupportModelContract;
use App\Services\Support\SupportOperations;
use App\Services\Support\SupportPlanner;
use App\Services\Support\SupportPolicy;
use App\Services\Support\SupportReviews;
use Carbon\CarbonImmutable;
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

    public function operations(Request $request, SupportPolicy $policy): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['sometimes', 'date', 'after_or_equal:'.now()->subDays(90)->toDateString()],
            'to' => ['sometimes', 'date', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'scope' => ['sometimes', Rule::in(['consumer', 'workspace'])],
            'locale' => ['sometimes', Rule::in(['en', 'nl'])],
            'status' => ['sometimes', Rule::in(['PENDING', 'RESEARCHING', 'VERIFYING', 'RETRYING', 'VERIFIED', 'PARTIALLY_VERIFIED', 'NEEDS_CLARIFICATION', 'ESCALATED', 'UNAVAILABLE'])],
            'model' => ['sometimes', 'string', 'max:100'],
            'retry_used' => ['sometimes', 'boolean'],
            'outcome' => ['sometimes', Rule::in(['CONFIRMED_ERROR', 'ANSWER_CORRECT', 'NEEDS_INVESTIGATION'])],
        ]);
        $from = isset($filters['from']) ? CarbonImmutable::parse($filters['from'])->utc() : now()->utc()->subDay();
        $to = isset($filters['to']) ? CarbonImmutable::parse($filters['to'])->utc() : now()->utc();
        $query = SupportMessage::whereBetween('created_at', [$from, $to]);
        foreach (['status', 'retry_used'] as $key) {
            if (isset($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (isset($filters['model'])) {
            $query->where('metrics->model', $filters['model']);
        }
        if (isset($filters['outcome'])) {
            $query->whereIn('id', SupportReview::where('outcome', $filters['outcome'])->select('message_id'));
        }
        if (isset($filters['scope']) || isset($filters['locale'])) {
            $conversations = SupportConversation::query();
            if (isset($filters['scope'])) {
                $filters['scope'] === 'consumer' ? $conversations->whereNull('webshop_id') : $conversations->whereNotNull('webshop_id');
            }
            if (isset($filters['locale'])) {
                $conversations->where('locale', $filters['locale']);
            }
            $query->whereIn('conversation_id', $conversations->select('id'));
        }
        $metrics = app(SupportOperations::class)->aggregate($query);
        $current = $policy->current();

        return response()->json(['data' => $metrics + ['from' => $from->toIso8601String(), 'checked_at' => now()->toIso8601String(), 'to' => $to->toIso8601String(),
            'provider_cost_estimate' => self::costEstimate($metrics['input_tokens'], $metrics['output_tokens']),
            'cost_notice' => 'Cost is an estimate for requests with configured verified EUR rates; coverage is reported separately. Billed cost is unavailable. Missing prices are never zero.',
            'open_reviews' => SupportReview::whereNull('outcome')->count(), 'approved_regressions' => SupportRegression::count(),
            'daily_requests' => (int) (DB::table('support_quotas')->where('day', now()->utc()->toDateString())->where('scope', 'global')->value('requests') ?? 0),
            'circuit_open' => Cache::has('support-ai:circuit'), 'broadcaster' => config('broadcasting.default'), 'last_successful_broadcast_at' => Cache::get('support-ai:last-broadcast-at'),
            'broadcast_failures_last_24h' => DB::table('audit_events')->where('action', 'support.broadcast_unavailable')->where('occurred_at', '>=', $from)->count(),
            'connectivity' => ['provider_last_success_at' => Cache::get('support-ai:last-provider-success-at'), 'worker_last_execution_at' => Cache::get('support-ai:last-worker-execution-at'), 'queue_connection' => config('queue.default'), 'reverb_live_probe' => 'not_measured', 'broadcast_evidence' => 'dispatch_attempt_only'],
            'model_contract' => ['model' => $current['model'], 'verified' => app(SupportModelContract::class)->verified($current['model']), 'proof_scope' => 'expiring_exact_model_key_profile_synthetic_strict_tool_canary_not_live_availability_or_quality'],
            'policy_version' => $current['version']]], 200, ['Cache-Control' => 'private, no-store']);
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
