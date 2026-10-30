<?php

namespace App\Services\Support;

use App\Exceptions\ApiException;
use App\Models\PlatformSettingVersion;
use App\Models\SupportRegression;
use App\Models\User;
use App\Services\Admin\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class SupportPolicy
{
    public const CODES = ['NO_UNVERIFIED_REFUND_PROMISE', 'NO_UNVERIFIED_RELEASE_DATE', 'BLOCK_GENERAL_ASSISTANT', 'NO_REFUND_PROMISE'];

    public const ACTIONS = ['BLOCK', 'REWRITE', 'ESCALATE', 'REVIEW'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'model' => null,
            'languages' => ['en', 'nl'],
            'daily_request_limit' => 100,
            'timeout_seconds' => 20,
            'max_retry' => 1,
            'restrictions' => [],
            'default_language' => 'nl',
            'tone' => 'friendly_professional',
            'empathy' => 'balanced',
            'answer_detail' => 'concise',
            'target_answer_words' => 45,
            'max_answer_words' => 120,
            'max_clarification_questions' => 1,
            'show_progress_updates' => true,
            'show_verified_badge' => true,
            'suggest_relevant_actions' => true,
            'human_escalation' => 'enabled',
            'reasoning_effort' => 'medium',
            'text_verbosity' => 'low',
            'max_output_tokens' => 1200,
            'tool_choice' => 'required',
            'parallel_tool_calls' => false,
            'store' => false,
            'stream' => false,
            'response_deadline_ms' => 12000,
            'progress_message_delay_ms' => 500,
            'max_research_retries' => 1,
            'max_function_rounds' => 3,
            'daily_cost_limit_eur' => 20,
            'fallback_model' => null,
        ];
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        $allowed = implode(',', array_keys(self::defaults()));

        return [
            'value' => ['required', 'array:'.$allowed],
            'value.enabled' => ['required', 'boolean:strict'],
            'value.model' => ['present', 'nullable', 'string', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,99}$/D'],
            'value.languages' => ['required', 'array', 'list', 'min:1', 'max:2'],
            'value.languages.*' => ['required', 'distinct', Rule::in(['en', 'nl'])],
            'value.daily_request_limit' => ['required', 'integer:strict', 'between:0,1000'],
            'value.timeout_seconds' => ['required', 'integer:strict', 'between:5,20'],
            'value.max_retry' => ['required', 'integer:strict', 'in:0,1'],
            'value.restrictions' => ['present', 'array', 'list', 'max:8'],
            'value.restrictions.*' => ['required', 'array'],
            'value.restrictions.*.rule_code' => ['required', 'distinct', Rule::in(self::CODES)],
            'value.restrictions.*.match_type' => ['required', 'in:semantic_claim'],
            'value.restrictions.*.action' => ['required', Rule::in(self::ACTIONS)],
            'value.restrictions.*.enabled' => ['required', 'boolean:strict'],
            'value.restrictions.*.scope' => ['sometimes', Rule::in(['ALL', 'CONSUMER', 'WORKSPACE'])],
            'value.default_language' => ['sometimes', Rule::in(['en', 'nl'])],
            'value.tone' => ['sometimes', Rule::in(['friendly_professional', 'warm', 'formal'])],
            'value.empathy' => ['sometimes', Rule::in(['low', 'balanced', 'high'])],
            'value.answer_detail' => ['sometimes', Rule::in(['brief', 'concise', 'detailed'])],
            'value.target_answer_words' => ['sometimes', 'integer:strict', 'between:10,200'],
            'value.max_answer_words' => ['sometimes', 'integer:strict', 'between:20,400'],
            'value.max_clarification_questions' => ['sometimes', 'integer:strict', 'between:0,3'],
            'value.show_progress_updates' => ['sometimes', 'boolean:strict'],
            'value.show_verified_badge' => ['sometimes', 'boolean:strict'],
            'value.suggest_relevant_actions' => ['sometimes', 'boolean:strict'],
            'value.human_escalation' => ['sometimes', Rule::in(['enabled', 'disabled'])],
            'value.reasoning_effort' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
            'value.text_verbosity' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
            'value.max_output_tokens' => ['sometimes', 'integer:strict', 'between:256,4000'],
            'value.tool_choice' => ['sometimes', Rule::in(['required', 'auto', 'none'])],
            'value.parallel_tool_calls' => ['sometimes', 'boolean:strict'],
            'value.store' => ['sometimes', 'boolean:strict'],
            'value.stream' => ['sometimes', 'boolean:strict'],
            'value.response_deadline_ms' => ['sometimes', 'integer:strict', 'between:3000,30000'],
            'value.progress_message_delay_ms' => ['sometimes', 'integer:strict', 'between:0,5000'],
            'value.max_research_retries' => ['sometimes', 'integer:strict', 'in:0,1'],
            'value.max_function_rounds' => ['sometimes', 'integer:strict', 'between:1,5'],
            'value.daily_cost_limit_eur' => ['sometimes', 'numeric', 'between:0,1000'],
            'value.fallback_model' => ['sometimes', 'nullable', 'string', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,99}$/D'],
        ];
    }

    /** @return array<string, mixed> */
    public function current(): array
    {
        $published = app(PlatformSettings::class)->published(false);
        $v = $published['values']['support.ai'] ?? [];
        $p = array_merge(self::defaults(), is_array($v) ? $v : []);
        $p['enabled'] = config('support_ai.enabled') && $p['enabled'];
        $p['model'] ??= (string) config('support_ai.model');
        $p['daily_request_limit'] = max(0, min((int) config('support_ai.daily_request_limit'), $p['daily_request_limit'], 1000));
        $p['timeout_seconds'] = max(5, min((int) config('support_ai.timeout_seconds'), $p['timeout_seconds'], 20));
        $p['max_retry'] = max(0, min(1, (int) ($p['max_research_retries'] ?? $p['max_retry'])));
        $p['version'] = $published['versions']['support.ai'] ?? 'default-disabled';
        $p['fingerprint'] = hash('sha256', json_encode($p, JSON_THROW_ON_ERROR));

        return $p;
    }

    /** @param array<string, mixed> $p */
    public function action(array $p, string $intent): ?string
    {
        $code = match ($intent) {
            'escalate' => 'NO_UNVERIFIED_REFUND_PROMISE',
            'release_date' => 'NO_UNVERIFIED_RELEASE_DATE',
            'unknown' => 'BLOCK_GENERAL_ASSISTANT',
            default => null,
        };
        foreach ($p['restrictions'] as $r) {
            if (! ($r['enabled'] ?? false)) {
                continue;
            }
            $rule = $r['rule_code'] ?? '';
            if ($rule === $code || ($intent === 'release_date' && $rule === 'NO_REFUND_PROMISE') || ($intent === 'escalate' && $rule === 'NO_REFUND_PROMISE')) {
                return $r['action'];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function testVersion(PlatformSettingVersion $v, User $actor): array
    {
        $key = DB::table('platform_settings')->where('id', $v->setting_id)->value('key');
        if ($key !== 'support.ai' || ! in_array($v->status, ['draft', 'review'], true)) {
            throw ApiException::conflict('Only a support policy draft can be tested.', 'support_policy_not_draft');
        }
        $fingerprint = self::regressionFingerprint();
        $cases = $this->cases();
        if (! hash_equals($fingerprint, self::regressionFingerprint())) {
            throw ApiException::conflict('The regression cases changed. Create a new support policy draft and test it.', 'support_policy_corpus_changed');
        }
        $prior = DB::table('support_policy_tests')->where('version_id', $v->id)->first();
        if ($prior !== null && (json_decode($prior->report, true)['corpus_fingerprint'] ?? null) !== $fingerprint) {
            throw ApiException::conflict('The regression cases changed. Create a new support policy draft and test it.', 'support_policy_corpus_changed');
        }
        $passed = 0;
        foreach ($cases as [$question, $workspace, $expected]) {
            if (app(SupportPlanner::class)->plan($question, $workspace)['intent'] === $expected) {
                $passed++;
            }
        }
        $report = ['total' => count($cases), 'passed' => $passed, 'failed' => count($cases) - $passed, 'policy_checksum' => $v->checksum,
            'scope' => 'server_intent_and_immutable_safety_guards', 'provider_verified' => false];
        DB::table('support_policy_tests')->insertOrIgnore(['version_id' => $v->id, 'checksum' => $v->checksum,
            'report' => json_encode($report + ['corpus_fingerprint' => $fingerprint], JSON_THROW_ON_ERROR), 'tested_by' => $actor->id, 'created_at' => now()]);

        return $report;
    }

    /** Static cases plus at most500 most recent approved human corrections. No unreviewed votes. */
    public static function regressionFingerprint(): string
    {
        return hash('sha256', file_get_contents(resource_path('support/regressions.php')).json_encode(
            SupportRegression::orderByDesc('id')->limit(500)->get()->map(fn ($r) => [$r->public_id, hash('sha256', $r->question), $r->expected_intent])->all(), JSON_THROW_ON_ERROR));
    }

    /** @return list<array{string, bool, string}> */
    private function cases(): array
    {
        $cases = require resource_path('support/regressions.php');
        $approved = SupportRegression::join('support_reviews', 'support_reviews.id', '=', 'support_regressions.review_id')
            ->join('support_messages', 'support_messages.id', '=', 'support_reviews.message_id')
            ->join('support_conversations', 'support_conversations.id', '=', 'support_messages.conversation_id')
            ->select('support_regressions.*', 'support_conversations.webshop_id as scope_webshop_id')
            ->orderByDesc('support_regressions.id')->limit(500)->get();
        foreach ($approved as $r) {
            $cases[] = [$r->question, $r->getAttribute('scope_webshop_id') !== null, $r->expected_intent];
        }

        return $cases;
    }
}
