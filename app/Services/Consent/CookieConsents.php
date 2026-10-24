<?php

namespace App\Services\Consent;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\CookieConsent;
use App\Models\CookiePolicy;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Cookie policy versions and visitor consent (Trello #5). Necessary cookies
 * are always on; every optional category needs an explicit opt-in under the
 * current policy version; only trackers of consented categories may load.
 */
class CookieConsents
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function validatePolicy(array $data): array
    {
        $text = ['required', 'string', 'max:1000', 'not_regex:/[<>]/'];
        $valid = Validator::make($data, [
            'version' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
            'requires_reconsent' => ['sometimes', 'boolean'],
            'categories' => ['required', 'array', 'size:4'],
            'categories.*.key' => ['required', 'distinct', Rule::in(CookiePolicy::CATEGORIES)],
            'categories.*.trackers' => ['present', 'array', 'max:30'],
            'categories.*.trackers.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/'],
            'categories.*.trackers.*.name' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'],
            'categories.*.trackers.*.provider' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'],
            'categories.*.trackers.*.purpose_nl' => $text, 'categories.*.trackers.*.purpose_en' => $text,
            'categories.*.trackers.*.retention' => ['required', 'string', 'max:40', 'not_regex:/[<>]/'],
            'texts' => ['required', 'array:nl,en', 'size:2'],
            'texts.*.title' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'], 'texts.*.body' => $text,
            'texts.*.accept_all' => ['required', 'string', 'max:30', 'not_regex:/[<>]/'], 'texts.*.reject_optional' => ['required', 'string', 'max:30', 'not_regex:/[<>]/'],
            'texts.*.customize' => ['required', 'string', 'max:30', 'not_regex:/[<>]/'],
        ])->validate();

        // "Necessary" is always required; the optional categories never are.
        $valid['categories'] = array_map(fn ($c) => ['key' => $c['key'], 'required' => $c['key'] === 'necessary', 'trackers' => array_values($c['trackers'])], $valid['categories']);

        return $valid;
    }

    public function publish(CookiePolicy $policy, int $actorId): CookiePolicy
    {
        if ($policy->status !== 'draft') {
            throw ApiException::conflict('Only a draft can be published.', 'policy_not_draft');
        }

        return DB::transaction(function () use ($policy, $actorId) {
            CookiePolicy::where('status', 'published')->update(['status' => 'retired', 'updated_at' => now()]);
            $policy->forceFill(['status' => 'published', 'published_at' => now(), 'published_by' => $actorId])->save();
            $this->audit->record('cookie_policy.published', category: AuditCategory::Admin, resource: $policy, actorUserId: $actorId,
                metadata: ['version' => $policy->version, 'requires_reconsent' => $policy->requires_reconsent]);

            return $policy;
        });
    }

    /**
     * @param  array{preferences?: bool, analytics?: bool, marketing?: bool}  $choices
     * @return array{record: CookieConsent, allowed_trackers: list<string>}
     */
    public function record(?string $consentId, string $policyVersion, string $action, array $choices, ?int $userId, ?string $ipHash): array
    {
        $policy = CookiePolicy::current();
        if ($policy === null || $policy->version !== $policyVersion) {
            throw ApiException::conflict('The cookie policy changed. Reload to see the current choices.', 'policy_outdated', ['current_version' => $policy?->version]);
        }
        if ($action === 'withdraw' && ($consentId === null || ! CookieConsent::where('consent_id', $consentId)->exists())) {
            throw new ApiException('There is no earlier choice to withdraw.', 404, 'not_found');
        }

        $all = $action === 'accept_all';
        $none = in_array($action, ['reject_optional', 'withdraw'], true);
        $final = ['necessary' => true];
        foreach (['preferences', 'analytics', 'marketing'] as $c) {
            $final[$c] = $all || (! $none && (bool) ($choices[$c] ?? false));
        }

        $record = CookieConsent::create(['consent_id' => $consentId ?? (string) Str::uuid(), 'user_id' => $userId, 'policy_version' => $policy->version,
            'choices' => $final, 'action' => $action, 'ip_hash' => $ipHash]);

        return ['record' => $record, 'allowed_trackers' => $this->allowedTrackers($policy, $final)];
    }

    /**
     * @param  array<string, bool>  $choices
     * @return list<string>
     */
    public function allowedTrackers(CookiePolicy $policy, array $choices): array
    {
        $keys = [];
        foreach ($policy->categories as $c) {
            if (! empty($choices[$c['key']])) {
                array_push($keys, ...array_column($c['trackers'], 'key'));
            }
        }

        return $keys;
    }
}
