<?php

namespace App\Integrations\Exceptions;

use App\Exceptions\ApiException;

class UnsupportedCapability extends ApiException
{
    public function __construct(string $capability)
    {
        parent::__construct("This platform connection does not support {$capability}.", 409, 'capability_not_supported', ['capability' => $capability]);
    }
}
