<?php

namespace App\Services\Support;

use App\Enums\AuditCategory;
use App\Events\SupportProgress;
use App\Exceptions\ApiException;
use App\Jobs\ProcessSupportMessage;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportReview;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Admin\Diagnostics\ProviderFailure;
use App\Services\Audit\AuditLogger;
use App\Services\Errors\ErrorRecorder;
use App\Support\FrontendPath;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class SupportChat
{
    public const ACTIVE = ['PENDING', 'RESEARCHING', 'VERIFYING', 'RETRYING'];

    public function __construct(private readonly SupportTools $tools, private readonly SupportPlanner $planner,
        private readonly SupportPolicy $policies, private readonly SupportProvider $provider,
        private readonly VerifiedSupportAnswer $verifier, private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function submit(User $actor, array $data, string $key): SupportMessage
    {
        $key = strtolower($key);
        if (isset($data['conversation_id'])) {
            $existing = $this->conversation($actor, $data['conversation_id']);
            $data['workspace_id'] ??= $existing->webshop_id === null ? null : Webshop::findOrFail($existing->webshop_id)->public_id;
            $data['locale'] ??= $existing->locale;
        }
        $workspace = isset($data['workspace_id']) ? strtolower($data['workspace_id']) : null;
        $this->tools->authorize($actor, $workspace);
        $hash = hash_hmac('sha256', json_encode([$data['conversation_id'] ?? null, $workspace, $data['locale'] ?? 'en', $data['message'], $data['reference'] ?? null], JSON_THROW_ON_ERROR), (string) config('app.key'));
        if (($old = SupportMessage::where('user_id', $actor->id)->where('request_key', $key)->first()) !== null) {
            return $this->replay($actor, $old, $hash);
        }
        try {
            return DB::transaction(function () use ($actor, $data, $key, $workspace, $hash) {
                if (isset($data['conversation_id'])) {
                    $c = $this->conversation($actor, $data['conversation_id'], true);
                    if ($c->locale !== ($data['locale'] ?? 'en') || $c->webshop_id !== ($workspace === null ? null : Webshop::where('public_id', $workspace)->value('id'))) {
                        throw ApiException::conflict('Conversation scope cannot change.', 'support_scope_conflict');
                    }
                } else {
                    $c = SupportConversation::create(['user_id' => $actor->id, 'webshop_id' => $workspace === null ? null : Webshop::where('public_id', $workspace)->value('id'),
                        'locale' => $data['locale'] ?? 'en', 'expires_at' => now()->addDays(max(1, min(90, (int) config('support_ai.retention_days'))))]);
                }
                if (SupportMessage::where('conversation_id', $c->id)->whereIn('status', self::ACTIVE)->exists()) {
                    throw ApiException::conflict('Wait for the current support answer.', 'support_conversation_busy');
                }
                $plan = $this->planner->plan($data['message'], $workspace !== null, $data['reference'] ?? null);
                if ($plan['intent'] === 'knowledge' && $plan['reference'] === null
                    && ($faq = $this->tools->matchKnowledge($workspace !== null, $data['message'], $data['locale'] ?? 'en')) !== null) {
                    $plan = $this->planner->plan($data['message'], $workspace !== null, $faq);
                }
                $m = SupportMessage::create(['conversation_id' => $c->id, 'user_id' => $actor->id, 'request_key' => $key, 'request_hash' => $hash,
                    'question' => $data['message'], 'research' => $plan, 'status' => 'PENDING']);
                $this->audit->record('support.message_requested', category: AuditCategory::Security, actorUserId: $actor->id, resource: $m, webshopId: $c->webshop_id);
                ProcessSupportMessage::dispatch($m->id)->afterCommit();

                return $m;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            $old = $this->findRequest($actor, $key);

            return $old === null ? throw $e : $this->replay($actor, $old, $hash);
        }
    }

    private function findRequest(User $actor, string $key): ?SupportMessage
    {
        return SupportMessage::query()->where('user_id', $actor->id)->where('request_key', $key)->first();
    }

    private function replay(User $actor, SupportMessage $m, string $hash): SupportMessage
    {
        $this->conversation($actor, SupportConversation::findOrFail($m->conversation_id)->public_id);
        if (! hash_equals($m->request_hash, $hash)) {
            throw ApiException::conflict('This support request key was already used.', 'support_idempotency_conflict');
        }

        return $m;
    }

    public function conversation(User $actor, string $id, bool $lock = false): SupportConversation
    {
        $c = SupportConversation::where('public_id', strtolower($id))->where('user_id', $actor->id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if ($c === null || $c->expires_at->isPast()) {
            $this->audit->recordSafely('support.conversation_denied', category: AuditCategory::Security, actorUserId: $actor->id, httpStatus: 404);
            throw new ApiException('Support conversation not found.', 404, 'not_found');
        }
        $this->tools->authorize($actor, $c->webshop_id === null ? null : Webshop::findOrFail($c->webshop_id)->public_id);

        return $c;
    }

    public function ownedMessage(User $actor, string $id): SupportMessage
    {
        $m = SupportMessage::where('public_id', strtolower($id))->where('user_id', $actor->id)->first() ?? throw new ApiException('Support message not found.', 404, 'not_found');
        $this->conversation($actor, SupportConversation::findOrFail($m->conversation_id)->public_id);

        return $m;
    }

    /** @return array<string, mixed> */
    public function present(User $actor, SupportMessage $m): array
    {
        $c = $this->conversation($actor, SupportConversation::findOrFail($m->conversation_id)->public_id);
        $workspace = $c->webshop_id === null ? null : Webshop::findOrFail($c->webshop_id)->public_id;
        // Historical answers remain timestamped snapshots, but current permissions must still allow each tool.
        foreach ($m->evidence['snapshots'] ?? [] as $snapshot) {
            $this->tools->retrieve($actor, $snapshot['tool'], $snapshot['arguments'], $workspace, $c->locale);
        }

        $result = is_array($m->result) ? $m->result : [];

        // Contract projection (message_id/answer/evidence) plus nested result for existing clients.
        return [
            'id' => $m->public_id,
            'message_id' => $m->public_id,
            'conversation_id' => $c->public_id,
            'question' => $m->question,
            'status' => $m->status,
            'answer' => $result['answer'] ?? null,
            'evidence_ids' => $result['evidence_ids'] ?? [],
            'checked_at' => $result['checked_at'] ?? null,
            'retry_used' => (bool) $m->retry_used,
            'action' => $result['action'] ?? null,
            'checks' => $result['checks'] ?? null,
            'result' => $m->result,
            'created_at' => $m->created_at->toIso8601String(),
            'completed_at' => $m->completed_at?->toIso8601String(),
        ];
    }

    public function execute(int $id): void
    {
        if (SupportMessage::whereKey($id)->where('status', 'PENDING')->update(['status' => 'RESEARCHING', 'updated_at' => now()]) !== 1) {
            return;
        }
        $m = SupportMessage::findOrFail($id);
        $started = microtime(true);
        $trace = [];
        $evidence = [];
        $metrics = [];
        try {
            $c = SupportConversation::findOrFail($m->conversation_id);
            $actor = User::findOrFail($m->user_id);
            $this->conversation($actor, $c->public_id);
            $workspace = $c->webshop_id === null ? null : Webshop::findOrFail($c->webshop_id)->public_id;
            $this->progress($m, 'RESEARCHING');
            $p = $this->policies->current();
            $plan = $m->research;
            $metrics['policy_version'] = $p['version'];
            $action = $this->policies->action($p, $plan['intent']);
            $metrics['restriction_action'] = $action;
            if ($plan['intent'] === 'escalate' || $action === 'ESCALATE' || $action === 'REVIEW') {
                $this->finish($m, 'ESCALATED', $c->locale === 'nl' ? 'Een beoordeling door ondersteuning is aangevraagd.' : 'A human support review has been requested.', [], [], $metrics, $started, true);

                return;
            }
            if ($plan['intent'] === 'release_date') {
                $this->finish($m, 'NEEDS_CLARIFICATION', $c->locale === 'nl' ? 'Er is geen bevestigde vrijgavedatum. Geef uw orderreferentie of vraag ondersteuning.' : 'No release date has been confirmed. Provide your order reference or request human support.', [], [], $metrics, $started);

                return;
            }
            if ($plan['intent'] === 'unknown' || $plan['wrong_scope'] || $plan['missing_reference'] || $action === 'BLOCK') {
                $this->finish($m, 'NEEDS_CLARIFICATION', $this->clarification($plan, $c->locale), [], [], $metrics, $started);

                return;
            }
            if (! $p['enabled'] || ! in_array($c->locale, $p['languages'], true) || ! $this->provider->configured($p['model'])) {
                throw new ProviderFailure('not_configured');
            }
            if (Cache::has('support-ai:circuit')) {
                throw new ProviderFailure('circuit_open');
            }
            $required = [$plan['tool']];
            $primaryFailed = false;
            try {
                $evidence[] = $this->tools->retrieve($actor, $plan['tool'], $plan['arguments'], $workspace, $c->locale);
                $trace[] = ['tool' => $plan['tool'], 'outcome' => 'retrieved'];
            } catch (Throwable $e) {
                if ($e instanceof ApiException && in_array($e->status, [401, 403, 404], true)) {
                    throw $e;
                }
                if (! $e instanceof ApiException) {
                    app(ErrorRecorder::class)->recordJobFailure($e, ProcessSupportMessage::class, null, (string) config('queue.default'), ['phase' => 'primary_research']);
                }
                $primaryFailed = true;
                $trace[] = ['tool' => $plan['tool'], 'outcome' => 'unavailable'];
            }
            if ($plan['intent'] === 'reward' && $p['max_retry'] === 1 && $plan['reference'] !== null) {
                $known = $evidence[0]->facts ?? ['available_minor' => null];
                $diagnostic = SupportAnswerChecks::diagnostic(
                    $m->question,
                    'MISSING_REWARD_STATUS',
                    is_array($known) ? array_intersect_key($known, array_flip(['available_minor', 'reserved_minor', 'pending_minor', 'spent_minor', 'currency', 'unit'])) : [],
                    ['order_reward_status'],
                    ['get_my_order_reward_status'],
                    1,
                );
                $metrics['diagnostic'] = $diagnostic;
                $m->retry_used = true;
                $this->progress($m, 'RETRYING');
                $trace[] = ['tool' => 'get_my_order_reward_status', 'outcome' => 'attempted', 'diagnostic' => $diagnostic['failure_reason']];
                $evidence[] = $this->tools->retrieve($actor, 'get_my_order_reward_status', ['order_reference' => $plan['reference']], $workspace, $c->locale);
                $trace[count($trace) - 1]['outcome'] = 'retrieved';
                $required = $primaryFailed ? ['get_my_order_reward_status'] : [...$required, 'get_my_order_reward_status'];
            } elseif ($primaryFailed) {
                throw new ProviderFailure('evidence_unavailable');
            }
            $this->quota($actor, $p['daily_request_limit']);
            $this->progress($m, 'VERIFYING');
            $this->conversation($actor, $c->public_id);
            foreach ($evidence as $i => $item) {
                $fresh = $this->tools->retrieve($actor, $item->tool, $item->arguments, $workspace, $c->locale);
                if (! $item->matches($fresh)) {
                    throw ApiException::conflict('Support evidence has changed.', 'support_evidence_changed');
                }
                $evidence[$i] = $fresh;
            }
            $output = $this->provider->select($evidence, $p);
            $metrics = array_merge($metrics, $output['usage'], ['model' => $p['model']]);
            Cache::forget('support-ai:failures');
            $answer = $this->verifier->verify($actor, $output['selection'], $evidence, $required);
            if ($this->policies->current()['fingerprint'] !== $p['fingerprint']) {
                throw new ProviderFailure('policy_changed');
            }
            $this->conversation($actor, $c->public_id);
            $answer['action'] = $this->action($plan, $workspace, $c->locale === 'nl');
            if ($primaryFailed && $answer['answer'] !== null) {
                $answer['status'] = 'PARTIALLY_VERIFIED';
                $answer['answer'] .= $c->locale === 'nl' ? ' Het walletsaldo kon niet worden gecontroleerd.' : ' The wallet balance could not be verified.';
            }
            $this->finish($m, $answer['status'], $answer['answer'], $evidence, $trace, $metrics, $started, false, $answer);
        } catch (Throwable $e) {
            if (! $e instanceof ProviderFailure && ! $e instanceof ApiException) {
                app(ErrorRecorder::class)->recordJobFailure($e, ProcessSupportMessage::class, null, (string) config('queue.default'), ['phase' => 'support_processing']);
            }
            $code = $e instanceof ProviderFailure ? $e->failureCode : ($e instanceof ApiException ? $e->errorCode : 'research_unavailable');
            if ($e instanceof ProviderFailure && in_array($code, ['provider_unavailable', 'provider_rate_limited', 'invalid_provider_output'], true)) {
                Cache::add('support-ai:failures', 0, 300);
                if (Cache::increment('support-ai:failures') >= 3) {
                    Cache::put('support-ai:circuit', true, 300);
                }
            }
            $m->failure_code = $code;
            $status = $code === 'not_found' ? 'NEEDS_CLARIFICATION' : ($code === 'support_evidence_conflict' ? 'ESCALATED' : 'UNAVAILABLE');
            $this->finish($m, $status, null, $evidence, $trace, $metrics, $started, $code !== 'not_configured');
        }
    }

    /** @param list<ToolEvidence> $evidence
     * @param  list<array<string, mixed>>  $trace
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>|null  $result
     */
    private function finish(SupportMessage $m, string $status, ?string $answer, array $evidence, array $trace, array $metrics, float $started, bool $review = false, ?array $result = null): void
    {
        $finished = DB::transaction(function () use ($m, $status, $answer, $evidence, $trace, $metrics, $started, $review, $result) {
            $locked = SupportMessage::whereKey($m->id)->lockForUpdate()->first();
            if ($locked === null || $locked->completed_at !== null) {
                return false;
            }
            $m->status = $status;
            $m->evidence = ['snapshots' => array_map(fn (ToolEvidence $e) => ['id' => $e->id, 'tool' => $e->tool, 'arguments' => $e->arguments, 'source' => $e->sourceId, 'facts' => $e->facts, 'checked_at' => $e->retrievedAt->toIso8601String()], $evidence), 'trace' => $trace];
            $m->result = $result ?? ['status' => $status, 'answer' => $answer, 'evidence_ids' => [], 'checked_at' => now()->toIso8601String(), 'action' => null];
            $m->metrics = $metrics + ['latency_ms' => (int) round((microtime(true) - $started) * 1000), 'additional_retrieval_rounds' => $m->retry_used ? 1 : 0];
            $m->completed_at = now();
            $m->save();
            if ($review) {
                SupportReview::firstOrCreate(['message_id' => $m->id, 'trigger' => 'automatic']);
            }
            $this->audit->record('support.message_completed', category: AuditCategory::Security, resource: $m, actorUserId: $m->user_id,
                metadata: ['status' => $status, 'retry_used' => $m->retry_used, 'failure_code' => $m->failure_code]);

            return true;
        });
        if (! $finished) {
            return;
        }
        $this->broadcast($m, $status === 'VERIFIED' || $status === 'PARTIALLY_VERIFIED' ? 'ChatAnswerVerified' : 'ChatAnswerUnavailable');
        if ($review) {
            $this->broadcast($m, 'ChatReviewRequired');
        }
    }

    public function failed(int $id): void
    {
        $m = SupportMessage::find($id);
        if ($m !== null && $m->completed_at === null) {
            $m->failure_code = 'job_failed';
            $this->finish($m, 'UNAVAILABLE', null, [], [], [], microtime(true), true);
        }
    }

    private function quota(User $actor, int $global): void
    {
        DB::transaction(function () use ($actor, $global) {
            $day = now()->utc()->toDateString();
            foreach (['global' => $global, 'user:'.$actor->id => max(0, min(100, (int) config('support_ai.user_daily_request_limit')))] as $scope => $limit) {
                DB::table('support_quotas')->insertOrIgnore(['day' => $day, 'scope' => $scope, 'requests' => 0]);
                $row = DB::table('support_quotas')->where('day', $day)->where('scope', $scope)->lockForUpdate()->first();
                if ($row->requests >= $limit) {
                    throw new ProviderFailure('quota_exceeded');
                }
                DB::table('support_quotas')->where('day', $day)->where('scope', $scope)->increment('requests');
            }
        }, 3);
    }

    private function progress(SupportMessage $m, string $status): void
    {
        $m->status = $status;
        $m->save();
        $this->broadcast($m, match ($status) {
            'RESEARCHING' => 'ChatResearchStarted', 'RETRYING' => 'ChatRetryStarted', default => 'ChatVerificationStarted'
        });
    }

    public function broadcast(SupportMessage $m, string $event): void
    {
        try {
            event(new SupportProgress(SupportConversation::findOrFail($m->conversation_id)->public_id, $m->public_id, $m->status, $event));
            Cache::put('support-ai:last-broadcast-at', now()->toIso8601String(), 86400);
        } catch (Throwable) {
            $this->audit->recordSafely('support.broadcast_unavailable', category: AuditCategory::Security, resource: $m);
        }
    }

    /** @param array<string, mixed> $plan */
    private function clarification(array $plan, string $locale): string
    {
        $nl = $locale === 'nl';
        if ($plan['wrong_scope']) {
            return $nl ? 'Open uw eigen consumentenaccount voor persoonlijke gegevens of selecteer de juiste webshop voor webshopgegevens.'
                : 'Open your consumer account for personal records, or select the appropriate workspace for merchant records.';
        }

        return match ($plan['intent']) {
            'reward' => $nl ? 'Geef de orderreferentie of het publieke ID van uw verwachte walletbeloning. Deel geen kortingscodes of betaalgegevens.' : 'Provide the order reference or public ID of your pending wallet reward. Do not send voucher codes or payment details.',
            'quote', 'offer_cost' => $nl ? 'Geef het publieke ID van de aanbieding waarvoor u de prijsopgave of kosten wilt controleren.' : 'Provide the public offer ID for the quote or cost you want checked.',
            'campaign' => $nl ? 'Geef het publieke campagne-ID uit de geselecteerde webshop.' : 'Provide the public campaign ID from the selected workspace.',
            'integration' => $nl ? 'Geef het publieke verbindings-ID uit de geselecteerde webshop.' : 'Provide the public connection ID from the selected workspace.',
            'knowledge' => $nl ? 'Zoek in de gepubliceerde FAQ en selecteer het relevante publieke artikel-ID.' : 'Search the published FAQ and select the relevant public article ID.',
            default => $nl ? 'Ik kan helpen met ShopBonus-wallets, beloningen, vouchers, aanbiedingen, campagnes en integraties. Waarmee heeft u hulp nodig?' : 'I can help with ShopBonus wallets, rewards, vouchers, offers, campaigns and integrations. Which do you need help with?',
        };
    }

    /** @param array<string, mixed> $plan
     * @return array<string, string>|null
     */
    private function action(array $plan, ?string $workspace, bool $nl): ?array
    {
        $route = match ($plan['intent']) {
            'wallet', 'reward' => FrontendPath::to('consumer_wallet'),
            'vouchers', 'quote' => FrontendPath::to('consumer_vouchers'),
            'funding' => FrontendPath::to('workspace_wallet', ['workspace' => $workspace ?? 'current']),
            'offer_cost' => FrontendPath::to('workspace_offers', ['workspace' => $workspace ?? 'current']),
            'campaign' => FrontendPath::to('workspace_banners', ['workspace' => $workspace ?? 'current']),
            'integration' => FrontendPath::to('workspace_integrations', ['workspace' => $workspace ?? 'current']),
            default => null,
        };
        if ($route === null) {
            return null;
        }
        $label = match ($plan['intent']) {
            'wallet', 'reward' => $nl ? 'Bekijk mijn tegoed' : 'View my credit',
            'vouchers', 'quote' => $nl ? 'Bekijk mijn vouchers' : 'View my vouchers',
            'funding' => $nl ? 'Bekijk webshopfinanciering' : 'View merchant funding',
            'offer_cost' => $nl ? 'Bekijk aanbiedingen' : 'View offers',
            'campaign' => $nl ? 'Bekijk campagneoverzicht' : 'View campaign overview',
            default => $nl ? 'Bekijk integraties' : 'View integrations', // only 'integration' remains: $route is null otherwise
        };

        return ['label' => $label, 'route' => $route];
    }
}
