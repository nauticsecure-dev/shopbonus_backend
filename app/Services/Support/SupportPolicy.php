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
    public const CODES = ['NO_UNVERIFIED_REFUND_PROMISE', 'NO_UNVERIFIED_RELEASE_DATE', 'BLOCK_GENERAL_ASSISTANT'];

    public const ACTIONS = ['BLOCK', 'REWRITE', 'ESCALATE', 'REVIEW'];

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return ['value' => ['required', 'array:enabled,model,languages,daily_request_limit,timeout_seconds,max_retry,restrictions'],
            'value.enabled' => ['required', 'boolean:strict'], 'value.model' => ['present', 'nullable', 'string', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,99}$/D'],
            'value.languages' => ['required', 'array', 'list', 'min:1', 'max:2'], 'value.languages.*' => ['required', 'distinct', Rule::in(['en', 'nl'])],
            'value.daily_request_limit' => ['required', 'integer:strict', 'between:0,1000'], 'value.timeout_seconds' => ['required', 'integer:strict', 'between:5,20'],
            'value.max_retry' => ['required', 'integer:strict', 'in:0,1'], 'value.restrictions' => ['present', 'array', 'list', 'max:3'],
            'value.restrictions.*' => ['required', 'array:rule_code,match_type,action,enabled'],
            'value.restrictions.*.rule_code' => ['required', 'distinct', Rule::in(self::CODES)],
            'value.restrictions.*.match_type' => ['required', 'in:semantic_claim'], 'value.restrictions.*.action' => ['required', Rule::in(self::ACTIONS)],
            'value.restrictions.*.enabled' => ['required', 'boolean:strict']];
    }

    /** @return array<string, mixed> */
    public function current(): array
    {
        $published = app(PlatformSettings::class)->published(false);
        $v = $published['values']['support.ai'] ?? [];
        $p = array_merge(['enabled' => false, 'model' => null, 'languages' => ['en', 'nl'], 'daily_request_limit' => 100,
            'timeout_seconds' => 20, 'max_retry' => 1, 'restrictions' => []], is_array($v) ? $v : []);
        $p['enabled'] = config('support_ai.enabled') && $p['enabled'];
        $p['model'] ??= (string) config('support_ai.model');
        $p['daily_request_limit'] = max(0, min((int) config('support_ai.daily_request_limit'), $p['daily_request_limit'], 1000));
        $p['timeout_seconds'] = max(5, min((int) config('support_ai.timeout_seconds'), $p['timeout_seconds'], 20));
        $p['version'] = $published['versions']['support.ai'] ?? 'default-disabled';
        $p['fingerprint'] = hash('sha256', json_encode($p, JSON_THROW_ON_ERROR));

        return $p;
    }

    /** @param array<string, mixed> $p */
    public function action(array $p, string $intent): ?string
    {
        $code = match ($intent) {
            'escalate' => self::CODES[0], 'release_date' => self::CODES[1], 'unknown' => self::CODES[2], default => null
        };
        foreach ($p['restrictions'] as $r) {
            if ($r['enabled'] && $r['rule_code'] === $code) {
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
        $cases = $this->cases();
        $fingerprint = self::regressionFingerprint();
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
