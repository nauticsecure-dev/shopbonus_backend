<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditIntegrity;
use Illuminate\Console\Command;

class VerifyAudit extends Command
{
    protected $signature = 'shopbonus:audit-verify';

    protected $description = 'Verify audit event content, chain links, count and committed head';

    public function handle(AuditIntegrity $integrity): int
    {
        $result = $integrity->verify();
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['valid'] ? self::SUCCESS : self::FAILURE;
    }
}
