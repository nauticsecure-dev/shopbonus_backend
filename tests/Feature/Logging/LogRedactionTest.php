<?php

namespace Tests\Feature\Logging;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LogRedactionTest extends TestCase
{
    public function test_configured_channels_redact_sensitive_data_when_written(): void
    {
        $path = storage_path('logs/redaction-test-'.uniqid().'.log');
        config(['logging.channels.single.path' => $path]);
        Log::forgetChannel('single');

        Log::channel('single')->info('Webhook from jan@example.nl', [
            'api_key' => 'sk_live_supersecret',
            'order_id' => 'ext-1001',
        ]);

        $written = (string) file_get_contents($path);
        @unlink($path);

        $this->assertStringNotContainsString('jan@example.nl', $written);
        $this->assertStringNotContainsString('sk_live_supersecret', $written);
        $this->assertStringContainsString('ext-1001', $written);
        $this->assertStringContainsString('[REDACTED]', $written);
    }
}
