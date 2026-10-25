<?php

namespace App\Services\Admin\Diagnostics;

class ProviderFailure extends \RuntimeException
{
    public function __construct(public readonly string $failureCode)
    {
        parent::__construct('Incident diagnostics failed.');
    }
}
