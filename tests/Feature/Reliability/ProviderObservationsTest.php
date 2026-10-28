<?php

namespace Tests\Feature\Reliability;

use App\Services\Reliability\ProviderObservations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderObservationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_opt_in_provider_failures_keep_only_numeric_metadata_and_fixed_labels(): void
    {
        config(['reliability.enabled' => true, 'reliability.provider_failure_threshold' => 2]);
        Http::fake(['api.openai.com/*' => Http::response(['private' => 'SECRET response'], 503), 'api.mollie.com/*' => Http::failedConnection()]);
        Http::withToken('SECRET token')->post('https://api.openai.com/v1/responses?secret=SECRET', ['input' => 'SECRET prompt']);
        try {
            Http::get('https://api.mollie.com/v2/payments/private-id');
        } catch (ConnectionException) {
            // Connection failure is an observation, never an invented HTTP500 response.
        }
        $report = app(ProviderObservations::class)->report();
        $this->assertSame('CRITICAL', $report['http_status']);
        $this->assertSame(2, $report['http_failures']);
        $rows = DB::table('operational_observations')->get();
        $this->assertCount(2, $rows);
        $this->assertStringNotContainsString('SECRET', json_encode($rows));
        $this->assertStringNotContainsString('private-id', json_encode($rows));
        config(['reliability.enabled' => false]);
        Http::get('https://api.openai.com/v1/responses');
        $this->assertDatabaseCount('operational_observations', 2);
        $this->assertSame('UNKNOWN', app(ProviderObservations::class)->report()['http_status']);
    }
}
