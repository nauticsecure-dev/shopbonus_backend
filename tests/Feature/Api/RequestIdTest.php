<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class RequestIdTest extends TestCase
{
    public function test_generates_a_request_id_when_none_supplied(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk();
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));
    }

    public function test_reuses_a_safe_client_request_id(): void
    {
        $this->getJson('/api/v1/health', ['X-Request-Id' => 'answer24-callback-0001'])
            ->assertHeader('X-Request-Id', 'answer24-callback-0001');
    }

    public function test_replaces_an_unsafe_client_request_id(): void
    {
        $response = $this->getJson('/api/v1/health', ['X-Request-Id' => "bad id\n<script>"]);

        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));
    }

    public function test_request_id_is_shared_with_log_context(): void
    {
        Route::middleware('api')->get('api/v1/_test/context', fn () => ['ctx' => Context::get('request_id')]);

        $response = $this->getJson('/api/v1/_test/context', ['X-Request-Id' => 'trace-abc-12345']);

        $response->assertJsonPath('ctx', 'trace-abc-12345');
    }

    public function test_web_routes_also_receive_a_request_id(): void
    {
        $this->get('/')->assertOk()->assertHeader('X-Request-Id');
    }
}
