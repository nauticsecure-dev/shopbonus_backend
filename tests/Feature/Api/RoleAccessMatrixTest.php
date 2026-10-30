<?php

namespace Tests\Feature\Api;

use App\Enums\MembershipRole;
use App\Enums\PlatformRole;
use App\Enums\WorkspacePermission;
use App\Models\User;
use App\Models\Webshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Trello #22 API & permission audit: every /api/v1 route is called directly
 * (no frontend) as each identity the card lists, and the middleware outcome
 * is asserted by error code. Resource IDs are unknown ULIDs, so a request
 * that passes authorization ends in validation/404 rather than an effect.
 */
class RoleAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const UNKNOWN = '01jz0000000000000000000000';

    private Webshop $shop;

    private Webshop $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $owner = User::factory()->create();
        $this->shop = Webshop::factory()->withMember($owner)->create(['created_by' => $owner->id]);
        $other = User::factory()->create();
        $this->foreign = Webshop::factory()->withMember($other)->create(['created_by' => $other->id]);
    }

    /** @return list<Route> */
    private function routes(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/v1/'))->values()->all();
    }

    private static function has(Route $r, string $prefix): bool
    {
        return collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && ($m === $prefix || str_starts_with($m, $prefix.':')));
    }

    private static function permission(Route $r): ?string
    {
        $m = collect($r->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'workspace.can:'));

        return $m === null ? null : substr($m, strlen('workspace.can:'));
    }

    /** @return list<array{0: string, 1: string, 2: Route}> */
    private function calls(?Webshop $workspace = null): array
    {
        $out = [];
        foreach ($this->routes() as $r) {
            $uri = preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $m[1] === 'workspace' ? ($workspace ?? $this->shop)->public_id : self::UNKNOWN, $r->uri());
            foreach (array_diff($r->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $out[] = [$method, '/'.$uri, $r];
            }
        }

        return $out;
    }

    private function code(string $method, string $uri, ?User $as = null): ?string
    {
        $this->app['auth']->forgetGuards();
        if ($as !== null) {
            // The Shopify OAuth callback uses the browser session guard; all others use Sanctum.
            Sanctum::actingAs($as);
            $this->actingAs($as, 'web');
        }

        $response = $this->json($method, $uri);

        // A streamed export that passed authorization has no JSON error body.
        return $response->baseResponse instanceof StreamedResponse ? null : $response->json('error.code');
    }

    public function test_anonymous_suspended_and_unverified_identities_are_stopped_by_the_api(): void
    {
        $suspended = User::factory()->suspended()->create();
        $unverified = User::factory()->unverified()->create();
        $checked = 0;
        foreach ($this->calls() as [$method, $uri, $r]) {
            if (! self::has($r, 'auth')) {
                continue;
            }
            $label = "$method $uri";
            $this->assertSame('unauthenticated', $this->code($method, $uri), $label.' anonymous');
            $this->assertSame('account_inactive', $this->code($method, $uri, $suspended), $label.' suspended');
            if (self::has($r, 'verified')) {
                $this->assertSame('email_not_verified', $this->code($method, $uri, $unverified), $label.' unverified');
            }
            $checked++;
        }
        $this->assertGreaterThan(250, $checked);
    }

    public function test_consumers_never_reach_admin_or_foreign_workspace_routes(): void
    {
        $consumer = User::factory()->create();
        foreach ($this->calls($this->foreign) as [$method, $uri, $r]) {
            if (self::has($r, 'platform')) {
                $this->assertSame('platform_role_required', $this->code($method, $uri, $consumer), "$method $uri consumer");
            } elseif (self::permission($r) !== null) {
                // Non-members get the deliberate audited generic 404, never the workspace.
                $this->assertSame('not_found', $this->code($method, $uri, $consumer), "$method $uri consumer");
            }
        }
    }

    public function test_workspace_roles_get_exactly_their_permissions(): void
    {
        $users = [];
        foreach ([MembershipRole::Manager, MembershipRole::Employee] as $role) {
            $users[$role->value] = User::factory()->create();
            $this->shop->memberships()->create(['user_id' => $users[$role->value]->id, 'role' => $role, 'status' => 'active']);
        }
        $users['owner'] = $this->shop->memberships()->where('role', 'owner')->sole()->user;
        $routes = 0;
        foreach ($this->calls() as [$method, $uri, $r]) {
            $permission = self::permission($r);
            if ($permission === null) {
                continue;
            }
            foreach ($users as $role => $user) {
                $allowed = in_array(WorkspacePermission::from($permission), MembershipRole::from($role)->defaultPermissions(), true);
                $code = $this->code($method, $uri, $user);
                $allowed
                    ? $this->assertNotSame('workspace_permission_denied', $code, "$method $uri $role should be allowed")
                    : $this->assertSame('workspace_permission_denied', $code, "$method $uri $role should be denied");
            }
            $routes++;
        }
        $this->assertGreaterThan(90, $routes);
    }

    public function test_super_admin_passes_the_platform_gate_only_with_mfa(): void
    {
        config(['shopbonus.admin.mfa_required' => true]);
        $admin = User::factory()->create();
        $admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $checked = 0;
        foreach ($this->calls() as [$method, $uri, $r]) {
            if (! self::has($r, 'platform') || ! str_contains((string) collect($r->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'platform:')), 'super_admin')) {
                continue;
            }
            $this->assertSame('mfa_required', $this->code($method, $uri, $admin->fresh()), "$method $uri admin without 2FA");
            $checked++;
        }
        $admin->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();
        foreach ($this->calls() as [$method, $uri, $r]) {
            if (self::has($r, 'platform')) {
                $this->assertNotContains($this->code($method, $uri, $admin->fresh()), ['platform_role_required', 'mfa_required', 'unauthenticated'], "$method $uri admin");
            }
        }
        $this->assertGreaterThan(50, $checked);
    }
}
