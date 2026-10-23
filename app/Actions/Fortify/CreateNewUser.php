<?php

namespace App\Actions\Fortify;

use App\Enums\ConsentPurpose;
use App\Enums\OnboardingIntent;
use App\Models\User;
use App\Services\Identity\LegalConsents;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * POST /register (Fortify). Creates the identity plus its legal acceptances
 * and optional consent atomically. Only the listed fields are read: any
 * client-sent role, status, admin flag or webshop ID is ignored.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(private readonly LegalConsents $legal) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $versions = $this->legal->requiredVersions();

        if (isset($input['email']) && is_string($input['email'])) {
            $input['email'] = User::normalizeEmail($input['email']);
        }

        $data = Validator::make($input, [
            'account_type' => ['required', Rule::enum(OnboardingIntent::class)],
            'first_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
            'accept_terms' => ['accepted'],
            // The client echoes the versions it displayed; a stale page cannot
            // record acceptance of a document that is no longer current.
            'terms_version' => ['required', 'string', Rule::in([$versions['terms']])],
            'privacy_version' => ['required', 'string', Rule::in([$versions['privacy']])],
            'marketing_opt_in' => ['sometimes', 'boolean'],
            'locale' => ['sometimes', Rule::in(User::SUPPORTED_LOCALES)],
        ], [
            'terms_version.in' => 'The Terms have been updated. Reload the page and review them.',
            'privacy_version.in' => 'The Privacy Statement has been updated. Reload the page and review it.',
        ])->validate();

        try {
            return DB::transaction(function () use ($data) {
                $user = User::create([
                    'first_name' => $data['first_name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'onboarding_intent' => $data['account_type'],
                    'locale' => $data['locale'] ?? 'nl',
                ]);

                $this->legal->recordRequiredAcceptances($user);
                $this->legal->recordConsent(
                    $user,
                    ConsentPurpose::MarketingEmail,
                    (bool) ($data['marketing_opt_in'] ?? false),
                    'registration',
                );

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent signup for the same email.
            throw ValidationException::withMessages([
                'email' => [__('validation.unique', ['attribute' => 'email'])],
            ]);
        }
    }
}
