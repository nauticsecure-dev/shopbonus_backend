<?php

namespace App\Services\Consumer;

use App\Enums\AuditCategory;
use App\Enums\ConsentPurpose;
use App\Exceptions\ApiException;
use App\Models\ConsentRecord;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Identity\LegalConsents;
use Illuminate\Support\Facades\DB;

/**
 * The profile's email notification switches (Trello #10 §5). Each is an
 * optional purpose backed by the append-only consent history; the current
 * value is the latest record (or the purpose default). Account, security
 * and transactional mail never consult this. Senders of optional mail must
 * call allows() right before sending.
 */
class CommunicationPreferences
{
    public const SWITCHES = [
        'vouchers_discounts' => ConsentPurpose::MarketingEmail,
        'reminders' => ConsentPurpose::VoucherReminders,
        'offers_inspiration' => ConsentPurpose::MarketingInspiration,
    ];

    public function __construct(private readonly LegalConsents $legal, private readonly AuditLogger $audit) {}

    public function allows(User $user, ConsentPurpose $purpose): bool
    {
        $latest = ConsentRecord::where('user_id', $user->id)->where('purpose', $purpose)->latest('id')->value('granted');

        return $latest === null ? $purpose->default() : (bool) $latest;
    }

    /** @return array<string, bool> */
    public function current(User $user): array
    {
        return array_map(fn (ConsentPurpose $p) => $this->allows($user, $p), self::SWITCHES);
    }

    /**
     * Records only the switches whose value changes.
     *
     * @param  array<string, bool>  $switches
     * @return array<string, bool>
     */
    public function update(User $user, array $switches): array
    {
        if ($this->legal->marketingConsentVersion() === null) {
            throw new ApiException('Communication preferences cannot be changed right now.', 503, 'preferences_unavailable');
        }

        $changed = [];
        DB::transaction(function () use ($user, $switches, &$changed) {
            User::whereKey($user->id)->lockForUpdate()->first(); // serialise concurrent saves per user
            foreach ($switches as $key => $value) {
                $purpose = self::SWITCHES[$key];
                if ($this->allows($user, $purpose) !== $value) {
                    $this->legal->recordConsent($user, $purpose, $value, 'profile');
                    $changed[$key] = $value;
                }
            }
        });

        if ($changed !== []) {
            $this->audit->recordSafely('consumer.preferences_changed', category: AuditCategory::Identity, resource: $user, actorUserId: $user->id, metadata: ['changed' => $changed]);
        }

        return $this->current($user);
    }
}
