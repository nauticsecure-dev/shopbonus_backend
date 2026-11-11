<?php

namespace Tests\Feature\Discovery;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProtectedDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_discovery_and_code_paths_reject_before_lookup(): void
    {
        foreach (['webshops', 'webshops/nonexistent', 'webshops/nonexistent/visit', 'offers', 'offers/nonexistent', 'search?q=shop', 'discount-codes'] as $path) {
            $this->getJson('/api/v1/public/'.$path)->assertUnauthorized()->assertJsonMissingPath('data.0.code');
        }
        $this->getJson('/api/v1/public/categories')->assertOk();
    }

    public function test_signed_in_discovery_is_private_and_cannot_be_shared_cached(): void
    {
        Sanctum::actingAs(User::factory()->create());
        foreach (['webshops', 'offers', 'discount-codes'] as $path) {
            $response = $this->getJson('/api/v1/public/'.$path)->assertOk();
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        }
    }
}
