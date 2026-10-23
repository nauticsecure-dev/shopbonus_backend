<?php

namespace App\Models;

use App\Enums\OnboardingIntent;
use App\Enums\UserStatus;
use App\Notifications\ResetPasswordLink;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

/**
 * One identity for consumers, merchants and staff (Trello #4). Roles and
 * workspace memberships live in separate server-owned records (B03); nothing
 * on this model grants elevated access.
 *
 * `status`, `email_verified_at` and `public_id` are deliberately not fillable.
 *
 * @property int $id
 * @property string $public_id
 * @property string $first_name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property UserStatus $status
 * @property OnboardingIntent $onboarding_intent
 * @property string $locale
 * @property CarbonImmutable|null $last_login_at
 */
#[Fillable(['first_name', 'email', 'password', 'onboarding_intent', 'locale'])]
#[Hidden(['id', 'password', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference
{
    /**
     * Session-authenticated (SPA) requests carry a TransientToken; bearer
     * requests carry a PersonalAccessToken.
     *
     * @use HasApiTokens<PersonalAccessToken|TransientToken>
     */
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable;

    public const SUPPORTED_LOCALES = ['nl', 'en'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'onboarding_intent' => OnboardingIntent::class,
        ];
    }

    protected $attributes = [
        'status' => 'active',
        'onboarding_intent' => 'consumer',
        'locale' => 'nl',
    ];

    /**
     * Lower-case and trim so the unique index is case-insensitive everywhere.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => self::normalizeEmail($value));
    }

    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * public_id is generated on create; the integer primary key stays internal.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function preferredLocale(): string
    {
        return in_array($this->locale, self::SUPPORTED_LOCALES, true) ? $this->locale : 'nl';
    }

    /**
     * Queued, encrypted reset mail pointing at the SPA (FortifyServiceProvider).
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordLink($token));
    }

    public function isActive(): bool
    {
        return $this->status->canAuthenticate();
    }

    /** @return HasMany<LegalAcceptance, $this> */
    public function legalAcceptances(): HasMany
    {
        return $this->hasMany(LegalAcceptance::class);
    }

    /** @return HasMany<ConsentRecord, $this> */
    public function consentRecords(): HasMany
    {
        return $this->hasMany(ConsentRecord::class);
    }

    /** @return HasMany<EmailVerificationChallenge, $this> */
    public function emailVerificationChallenges(): HasMany
    {
        return $this->hasMany(EmailVerificationChallenge::class);
    }
}
