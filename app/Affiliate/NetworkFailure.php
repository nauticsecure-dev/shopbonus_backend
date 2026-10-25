<?php

namespace App\Affiliate;

use RuntimeException;

/** Only fixed, non-sensitive codes may cross the provider boundary. */
class NetworkFailure extends RuntimeException
{
    public function __construct(public readonly string $error, public readonly string $status = 'error')
    {
        parent::__construct($error);
    }
}
