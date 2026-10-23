<?php

namespace App\Enums;

/**
 * Optional, consent-based purposes. Mandatory account and security messages
 * are not listed: they do not depend on consent.
 *
 * Profile notification switches (Trello #10 §5) map onto these:
 * vouchers_discounts → MarketingEmail (also the registration opt-in),
 * offers_inspiration → MarketingInspiration, reminders → VoucherReminders.
 */
enum ConsentPurpose: string
{
    case MarketingEmail = 'marketing_email';
    case MarketingInspiration = 'marketing_inspiration';
    case VoucherReminders = 'voucher_reminders';

    /** Value when the consumer never chose: marketing is off, reminders about own vouchers are on. */
    public function default(): bool
    {
        return $this === self::VoucherReminders;
    }
}
