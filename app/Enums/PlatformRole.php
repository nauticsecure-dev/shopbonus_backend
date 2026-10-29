<?php

namespace App\Enums;

/**
 * Platform-wide roles, provisioned privately (console), never through
 * signup or merchant interfaces.
 */
enum PlatformRole: string
{
    case SuperAdmin = 'super_admin';

    /** Quality Center (Trello #37): runs and approves tests, records evidence and retests. */
    case QaTester = 'qa_tester';

    /** Quality Center (Trello #37): sees findings assigned to them and records fix commits. */
    case Developer = 'developer';
}
