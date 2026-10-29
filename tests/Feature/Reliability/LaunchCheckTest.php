<?php

namespace Tests\Feature\Reliability;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class LaunchCheckTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function check(): array
    {
        $code = Artisan::call('shopbonus:launch-check', ['--json' => true]);
        $rows = collect(json_decode(Artisan::output(), true)['data'])->pluck('result', 'check')->all();
        $rows['_exit'] = (string) $code;

        return $rows;
    }

    public function test_test_environment_is_reported_not_launch_ready_without_printing_secrets(): void
    {
        config(['shopbonus.payments.mollie.key' => 'test_SYNTHETIC_secret_mollie_key', 'shopbonus.answer24.signing_secret' => 'SYNTHETIC_answer24_secret']);
        $rows = $this->check();
        $this->assertSame('1', $rows['_exit']);
        $this->assertSame(['FAIL', 'FAIL', 'PASS', 'MANUAL'], [$rows['app.environment'], $rows['queue.async'], $rows['database.migrations_applied'], $rows['manual.provider_live_runs']]);
        Artisan::call('shopbonus:launch-check');
        $this->assertStringNotContainsString('SYNTHETIC', Artisan::output());
    }

    public function test_production_configuration_passes_with_only_owner_decisions_left(): void
    {
        config(['app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://api.shopbonus.example', 'session.secure' => true, 'session.http_only' => true,
            'session.same_site' => 'lax', 'session.driver' => 'redis', 'cors.allowed_origins' => ['https://shopbonus.example'], 'sanctum.stateful' => ['shopbonus.example'],
            'shopbonus.security.hsts_max_age' => 31536000, 'shopbonus.security.trusted_proxies' => '10.0.0.0/8', 'shopbonus.admin.mfa_required' => true,
            'queue.default' => 'redis', 'cache.default' => 'redis', 'mail.default' => 'smtp', 'shopbonus.payments.mollie.key' => 'live_SYNTHETIC',
            'shopbonus.payments.mollie.allow_live' => true, 'shopbonus.answer24.signing_secret' => 'SYNTHETIC', 'shopbonus.answer24.base_url' => 'https://answer24.example',
            'shopbonus.answer24.dispatch_enabled' => true, 'recovery.enabled' => true, 'recovery.key' => 'SYNTHETIC', 'recovery.restore_connection' => 'restore']);
        $rows = $this->check();
        $this->assertSame('0', $rows['_exit']);
        unset($rows['_exit']);
        $this->assertSame(['manual.legal_decisions', 'manual.provider_live_runs'], collect($rows)->reject(fn ($r) => $r === 'PASS')->keys()->sort()->values()->all());

        config(['cors.allowed_origins' => ['*'], 'sanctum.stateful' => ['localhost:3000']]);
        $rows = $this->check();
        $this->assertSame(['1', 'FAIL', 'FAIL'], [$rows['_exit'], $rows['cors.explicit_https_origins'], $rows['sanctum.stateful_domains']]);
    }
}
