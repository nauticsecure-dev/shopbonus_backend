<?php

namespace App\Services\Enrichment;

class EnrichmentFailure extends \RuntimeException
{
    public function __construct(public readonly string $failureCode)
    {
        parent::__construct('Merchant enrichment failed.');
    }
}
