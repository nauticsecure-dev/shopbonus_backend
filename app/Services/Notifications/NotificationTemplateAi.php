<?php

namespace App\Services\Notifications;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Admin\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** Strict drafts only; provider sees event schema, never customer/source data. */
final class NotificationTemplateAi
{
    /** @return list<array<string, mixed>> */
    public function generate(User $actor, string $event, string $key): array
    {
        if (! config('notifications.ai_enabled') || config('notifications.api_key') === '' || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,99}$/D', (string) config('notifications.model'))) {
            throw new ApiException('Template generation is not configured.', 503, 'notification_ai_not_configured');
        }
        $hash = hash('sha256', $event);
        $id = DB::transaction(function () use ($actor, $key, $hash) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('notification_template_generations')->where('created_by', $actor->id)->where('request_key', $key)->first();
            if ($existing !== null) {
                if ($existing->request_hash !== $hash) {
                    throw ApiException::conflict('Idempotency key belongs to another request.', 'notification_generation_conflict');
                }

                return $existing->id;
            }
            $day = now()->utc()->toDateString();
            DB::table('notification_ai_quotas')->upsert([['day' => $day, 'requests' => 0]], ['day'], ['day']);
            $quota = DB::table('notification_ai_quotas')->where('day', $day)->lockForUpdate()->first();
            if ($quota->requests >= max(0, min(100, (int) config('notifications.daily_limit')))) {
                $retry = max(1, (int) ceil(now()->utc()->diffInSeconds(now()->utc()->addDay()->startOfDay())));
                throw new ApiException('Daily template generation limit reached.', 429, 'notification_ai_daily_limit', ['retry_after_seconds' => $retry], ['Retry-After' => (string) $retry]);
            }
            DB::table('notification_ai_quotas')->where('day', $day)->increment('requests');

            return DB::table('notification_template_generations')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'created_by' => $actor->id,
                'request_key' => $key, 'request_hash' => $hash, 'status' => 'reserved', 'created_at' => now(), 'updated_at' => now()]);
        });
        $claimed = DB::table('notification_template_generations')->where('id', $id)->where('status', 'reserved')->update(['status' => 'processing', 'updated_at' => now()]);
        if (! $claimed) {
            $old = DB::table('notification_template_generations')->where('id', $id)->first();
            if ($old->status !== 'completed') {
                throw ApiException::conflict('This generation is pending or failed; no provider retry was made.', 'notification_generation_pending');
            }

            return array_map(fn ($v) => app(NotificationTemplates::class)->present(app(NotificationTemplates::class)->find($v)), json_decode($old->versions, true, flags: JSON_THROW_ON_ERROR));
        }
        try {
            $fields = ['title' => ['type' => 'string'], 'body' => ['type' => 'string'], 'action_label' => ['type' => 'string']];
            $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['nl', 'en'], 'properties' => array_fill_keys(['nl', 'en'], ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($fields), 'properties' => $fields])];
            $response = Http::withToken(config('notifications.api_key'))->acceptJson()->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(20)->post('https://api.openai.com/v1/responses', [
                'model' => config('notifications.model'), 'store' => false, 'max_output_tokens' => 1000,
                'instructions' => 'Draft professional ShopBonus NL and EN notification templates. Plain text, title at most70 characters, body180, action_label40. Never invent amounts, dates, deadlines, payment/redemption states or links. No HTML, digits, email addresses or provider references. Use only the supplied placeholder; cashback must include it. Describe only the event, and invite viewing current account details. These are private human-review drafts; never publish.',
                'input' => json_encode(['event_type' => $event, 'audience' => NotificationCatalogue::EVENTS[$event][0], 'allowed_variables' => $event === 'cashback_credited' ? ['amount_formatted'] : []], JSON_THROW_ON_ERROR),
                'text' => ['format' => ['type' => 'json_schema', 'name' => 'notification_templates', 'strict' => true, 'schema' => $schema]],
            ]);
            if (! $response->successful() || strlen($response->body()) > 16384 || $response->json('status') !== 'completed') {
                throw new \RuntimeException('Provider failed.');
            }
            $texts = [];
            foreach ($response->json('output', []) as $output) {
                if (($output['type'] ?? '') === 'reasoning') {
                    continue;
                }
                if (($output['type'] ?? '') !== 'message' || ($output['role'] ?? '') !== 'assistant') {
                    throw new \RuntimeException('Invalid provider output.');
                }
                foreach ($output['content'] ?? [] as $item) {
                    if (($item['type'] ?? '') !== 'output_text') {
                        throw new \RuntimeException('Invalid provider content.');
                    }
                    $texts[] = $item['text'];
                }
            }
            $value = count($texts) === 1 ? json_decode($texts[0], true, flags: JSON_THROW_ON_ERROR) : null;
            if (! is_array($value) || count($value) !== 2 || ! isset($value['nl'], $value['en']) || str_contains(json_encode($value), (string) config('notifications.api_key'))) {
                throw new \RuntimeException('Invalid templates.');
            }
            foreach (['nl', 'en'] as $locale) {
                NotificationTemplates::validate(NotificationTemplates::key($event, $locale), $value[$locale]);
            }
            $freshActor = $actor->fresh();
            if ($freshActor === null || ! $freshActor->isActive() || $freshActor->email_verified_at === null || ! $freshActor->isSuperAdmin()) {
                throw new \RuntimeException('Actor revoked.');
            }

            return DB::transaction(function () use ($actor, $event, $id, $value) {
                $generation = DB::table('notification_template_generations')->where('id', $id)->lockForUpdate()->first();
                if ($generation->status !== 'processing') {
                    throw new \RuntimeException('Generation claim ended.');
                }
                $versions = [];
                foreach (['nl', 'en'] as $locale) {
                    $versions[] = app(PlatformSettings::class)->draft($actor, NotificationTemplates::key($event, $locale), $value[$locale], 'AI-generated private template draft');
                }
                DB::table('notification_template_generations')->where('id', $id)->update(['status' => 'completed', 'versions' => json_encode(array_map(fn ($v) => $v->public_id, $versions)), 'updated_at' => now()]);

                return array_map(fn ($v) => app(NotificationTemplates::class)->present($v), $versions);
            });
        } catch (Throwable) {
            DB::table('notification_template_generations')->where('id', $id)->update(['status' => 'failed', 'error_code' => 'generation_failed', 'updated_at' => now()]);
            throw new ApiException('Template generation failed safely. No template was published.', 503, 'notification_generation_failed');
        }
    }
}
