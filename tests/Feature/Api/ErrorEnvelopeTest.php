<?php

namespace Tests\Feature\Api;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class ErrorEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/v1/_test')->group(function () {
            Route::get('auth', fn () => 'ok')->middleware('auth:sanctum');
            Route::get('forbidden', fn () => throw new AuthorizationException('Secret policy detail'));
            Route::get('missing-model', fn () => throw (new ModelNotFoundException)->setModel(User::class, [42]));
            Route::get('conflict', fn () => throw ApiException::conflict(
                'Voucher code already reserved.', 'voucher_code_reserved', ['retryable' => false],
            ));
            Route::get('csrf', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
            Route::get('origin', fn () => throw new OriginMismatchException('Origin mismatch.'));
            Route::post('validate', fn (Request $request) => $request->validate(['email' => ['required', 'email']]));
            Route::get('boom', fn () => throw new RuntimeException('SQLSTATE secret internals at /var/www'));
            Route::get('unavailable', fn () => throw new ApiException('Temporarily unavailable.', 503, 'feature_unavailable'));
        });
    }

    private function assertEnvelope(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['error' => ['status', 'code', 'message', 'request_id']])
            ->assertJsonPath('error.status', $status)
            ->assertJsonPath('error.code', $code);

        $this->assertSame($response->headers->get('X-Request-Id'), $response->json('error.request_id'));
    }

    public function test_unknown_route_returns_404_envelope(): void
    {
        $this->assertEnvelope($this->getJson('/api/v1/does-not-exist'), 404, 'not_found');
    }

    public function test_unknown_route_returns_json_even_without_accept_header(): void
    {
        $this->assertEnvelope($this->get('/api/v1/does-not-exist'), 404, 'not_found');
    }

    public function test_wrong_method_returns_405_envelope(): void
    {
        $this->assertEnvelope($this->postJson('/api/v1/health'), 405, 'method_not_allowed');
    }

    public function test_unauthenticated_returns_401_envelope_not_redirect(): void
    {
        $this->assertEnvelope($this->get('/api/v1/_test/auth'), 401, 'unauthenticated');
    }

    public function test_forbidden_returns_403_without_leaking_policy_message(): void
    {
        $response = $this->getJson('/api/v1/_test/forbidden');

        $this->assertEnvelope($response, 403, 'forbidden');
        $this->assertStringNotContainsString('Secret policy detail', $response->getContent());
    }

    public function test_model_not_found_returns_generic_404_without_model_name(): void
    {
        $response = $this->getJson('/api/v1/_test/missing-model');

        $this->assertEnvelope($response, 404, 'not_found');
        $this->assertStringNotContainsString('User', $response->getContent());
        $this->assertStringNotContainsString('42', $response->json('error.message'));
    }

    public function test_api_exception_returns_its_code_message_and_details(): void
    {
        $response = $this->getJson('/api/v1/_test/conflict');

        $this->assertEnvelope($response, 409, 'voucher_code_reserved');
        $response->assertJsonPath('error.message', 'Voucher code already reserved.')
            ->assertJsonPath('error.details.retryable', false);
    }

    public function test_csrf_mismatch_returns_419_envelope(): void
    {
        $this->assertEnvelope($this->getJson('/api/v1/_test/csrf'), 419, 'csrf_token_mismatch');
    }

    public function test_origin_mismatch_returns_403_origin_not_allowed(): void
    {
        $this->assertEnvelope($this->getJson('/api/v1/_test/origin'), 403, 'origin_not_allowed');
    }

    public function test_validation_returns_422_with_fields(): void
    {
        $response = $this->postJson('/api/v1/_test/validate', ['email' => 'not-an-email']);

        $this->assertEnvelope($response, 422, 'validation_failed');
        $response->assertJsonStructure(['error' => ['fields' => ['email']]]);
    }

    public function test_rate_limit_returns_429_with_retry_after(): void
    {
        config(['shopbonus.api.rate_limit_per_minute' => 2]);

        $this->getJson('/api/v1/health')->assertOk();
        $this->getJson('/api/v1/health')->assertOk();
        $response = $this->getJson('/api/v1/health');

        $this->assertEnvelope($response, 429, 'too_many_requests');
        $response->assertHeader('Retry-After');
    }

    public function test_server_error_returns_500_without_internals_when_debug_off(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/v1/_test/boom');

        $this->assertEnvelope($response, 500, 'server_error');
        $response->assertJsonMissingPath('error.debug');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('/var/www', $response->getContent());
    }

    public function test_intentional_5xx_api_exception_never_includes_debug_block(): void
    {
        config(['app.debug' => true]);

        $this->getJson('/api/v1/_test/unavailable')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'feature_unavailable')
            ->assertJsonMissingPath('error.debug');
    }

    public function test_server_error_includes_debug_block_only_when_debug_on(): void
    {
        config(['app.debug' => true]);

        $this->getJson('/api/v1/_test/boom')
            ->assertStatus(500)
            ->assertJsonPath('error.debug.exception', RuntimeException::class);
    }
}
