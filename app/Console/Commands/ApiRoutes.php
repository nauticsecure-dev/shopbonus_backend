<?php

namespace App\Console\Commands;

use App\Services\Admin\ApiRouteRegistry;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Output\OutputInterface;

class ApiRoutes extends Command
{
    protected $signature = 'shopbonus:api-routes {--markdown : Render the release inventory as Markdown instead of JSON}';

    protected $description = 'Generate the API route/access/scope/audit registry from runtime routes and OpenAPI';

    public function handle(ApiRouteRegistry $registry): int
    {
        $entries = $registry->entries();
        $matches = collect($entries)->every(fn ($r) => $r['contract_matches']);
        if ($this->option('markdown')) {
            if (! $matches) {
                $this->error('Runtime/OpenAPI access or permission drift prevents release inventory generation.');

                return self::FAILURE;
            }
            $this->output->getOutput()->writeln($this->markdown($entries), OutputInterface::OUTPUT_RAW);
        } else {
            // Preserve the existing machine-readable command contract.
            $this->line(json_encode(['data' => $entries], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }

        return $matches ? self::SUCCESS : self::FAILURE;
    }

    /** @param list<array<string, mixed>> $entries */
    private function markdown(array $entries): string
    {
        $routes = array_values(array_filter(Route::getRoutes()->getRoutes(), fn ($r) => str_starts_with($r->uri(), 'api/v1/')));
        $limits = [];
        foreach ($routes as $route) {
            $middleware = app(Router::class)->gatherRouteMiddleware($route);
            $names = array_values(array_filter($middleware, fn ($m) => is_string($m) && str_contains($m, 'Throttle')));
            $names = array_map(fn ($m) => substr($m, strrpos($m, '\\') === false ? 0 : strrpos($m, '\\') + 1), $names);
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $limits[$method.' /'.$route->uri()] = implode(', ', $names) ?: '-';
            }
        }
        usort($entries, fn ($a, $b) => [$a['route'], $a['method']] <=> [$b['route'], $b['method']]);
        $counts = array_count_values(array_column($entries, 'access'));
        ksort($counts);
        $access = implode(', ', array_map(fn ($k, $v) => $k.' '.$v, array_keys($counts), $counts));
        $paths = count(array_unique(array_column($entries, 'route')));
        $out = '# API route inventory (Trello #22)'."\n\n"
            .'Generated '.now()->utc()->toDateString().' using `php artisan shopbonus:api-routes --markdown` from runtime routes and OpenAPI.'."\n\n"
            .'Runtime route entries: **'.count($routes).'**. Method/path operations (HEAD aliases excluded): **'.count($entries).'**. Unique paths: **'.$paths.'**.'."\n\n"
            .'Access counts are per operation: '.$access.'. Runtime access and workspace permission annotations match OpenAPI for every listed operation.'."\n\n"
            .'A route entry may accept multiple methods. GET and POST `/api/v1/affiliate/postback/{token}` are separate operations on one entry; HEAD aliases are not additional operations.'."\n\n"
            .'`admin` means the platform-role middleware, not necessarily Super Admin alone: the Roles column lists accepted roles, including QA/developer routes. Workspace permissions and scopes remain server-enforced; foreign workspaces use the audited404 policy. Public means no Sanctum role gate; provider signature/authentication checks may still occur inside handlers.'."\n\n"
            .'Rate-limit cells name expanded middleware, not deployment-specific numeric limits. Authentication, scope and audit-category columns are registry classifications, not proof of successful authentication, audited execution, provider installation or release approval. See `docs/release/INDEPENDENT_ACCEPTANCE.md` for outstanding gates.'."\n\n"
            .'Regenerate before release using a temporary file, check command exit0, then replace this document. JSON output without `--markdown` is unchanged. `RoleAccessMatrixTest` checks protected-route middleware outcomes with unknown resource IDs; it is not every successful business action or independent provider evidence.'."\n\n"
            .'| Method | Path | Access | Roles | Authentication (route gate) | Permission | Scope | Step-up | Rate-limit middleware | Audit classification |'."\n"
            .'|---|---|---|---|---|---|---|---|---|---|'."\n";
        foreach ($entries as $e) {
            $step = array_filter([$e['password_recent'] ? 'recent password' : null, $e['email_otp'] ? 'email code' : null]);
            $cells = [$e['method'], '`'.$e['route'].'`', $e['access'], implode(', ', $e['roles']) ?: '-',
                implode(', ', $e['authentication_types']), $e['permission'] ?? '-', $e['scope'], implode(', ', $step) ?: '-',
                $limits[$e['method'].' '.$e['route']], $e['audit_category']];
            $out .= '| '.implode(' | ', array_map(fn ($v) => str_replace(['|', "\n"], ['\\|', ' '], (string) $v), $cells))." |\n";
        }

        return rtrim($out);
    }
}
