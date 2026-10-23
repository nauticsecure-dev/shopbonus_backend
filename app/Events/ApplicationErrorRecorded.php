<?php

namespace App\Events;

use App\Models\ApplicationError;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Alert hook: fired after an application error is stored. Admin alerting
 * (mail/Slack/dashboard, B16) subscribes here. Listeners must not throw.
 */
class ApplicationErrorRecorded
{
    use Dispatchable;

    public function __construct(public readonly ApplicationError $error) {}
}
