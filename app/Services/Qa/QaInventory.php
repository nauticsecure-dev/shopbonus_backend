<?php

namespace App\Services\Qa;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\QaComponent;
use App\Models\QaEndpoint;
use App\Models\QaInventorySnapshot;
use App\Services\Admin\ApiRouteRegistry;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as Routes;
use ReflectionFunction;
use ReflectionMethod;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Automatic discovery (Trello #37 §1). Reads the deployed routes, OpenAPI
 * contract, jobs, events, listeners, commands, schedules, migrations and
 * provider connectors, compares each fingerprint with the previous pass and
 * records new/changed/removed functions. Nothing is ever deleted: removed
 * entries keep their history with `removed_at`.
 *
 * An endpoint fingerprint covers method, path, middleware and the source of
 * its controller method, so a changed handler or access rule makes earlier
 * test evidence STALE.
 */
class QaInventory
{
    public function __construct(private readonly ApiRouteRegistry $registry, private readonly AuditLogger $audit) {}

    public function scan(string $sha, ?int $actorId = null): QaInventorySnapshot
    {
        $lock = Cache::lock('qa:inventory-scan', 300);
        if (! $lock->get()) {
            throw ApiException::conflict('An inventory scan is already running.', 'scan_in_progress');
        }
        try {
            $endpoints = $this->discoverEndpoints();
            $components = $this->discoverComponents();
            $now = Carbon::now();

            $snapshot = DB::transaction(function () use ($sha, $actorId, $endpoints, $components, $now) {
                $e = $this->sync(QaEndpoint::class, $endpoints, fn (QaEndpoint $m) => $m->method.' '.$m->path, $sha, $now);
                $c = $this->sync(QaComponent::class, $components, fn (QaComponent $m) => $m->kind.':'.$m->name, $sha, $now);
                $undocumented = QaEndpoint::whereNull('removed_at')->where('documented', false)->get()->map(fn ($m) => $m->method.' '.$m->path)->values()->all();

                return QaInventorySnapshot::create([
                    'git_sha' => $sha, 'created_by' => $actorId, 'created_at' => $now,
                    'totals' => ['endpoints' => count($endpoints), 'components' => count($components), 'undocumented' => count($undocumented)]
                        + collect($components)->countBy('kind')->all(),
                    'diff' => ['endpoints' => $e, 'components' => $c, 'undocumented' => $undocumented],
                ]);
            });
        } finally {
            $lock->release();
        }
        $this->audit->record('qa.inventory_scanned', category: AuditCategory::Admin, actorUserId: $actorId,
            metadata: ['snapshot' => $snapshot->public_id, 'git_sha' => $sha, 'added' => count($snapshot->diff['endpoints']['added']),
                'changed' => count($snapshot->diff['endpoints']['changed']), 'removed' => count($snapshot->diff['endpoints']['removed'])]);

        return $snapshot;
    }

    /**
     * Upserts discovered rows and returns the keys that were added, changed or removed.
     *
     * @param  class-string<QaEndpoint|QaComponent>  $model
     * @param  array<string, array<string, mixed>>  $found  key => attributes incl. fingerprint
     * @return array{added: list<string>, changed: list<string>, removed: list<string>}
     */
    private function sync(string $model, array $found, Closure $key, string $sha, Carbon $now): array
    {
        $diff = ['added' => [], 'changed' => [], 'removed' => []];
        $existing = $model::query()->lockForUpdate()->get()->keyBy(fn ($m): string => (string) $key($m));
        foreach ($found as $k => $attributes) {
            $row = $existing->get($k);
            if ($row === null) {
                $model::create($attributes + ['first_seen_sha' => $sha, 'changed_sha' => $sha, 'changed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
                $diff['added'][] = $k;

                continue;
            }
            $changed = $row->fingerprint !== $attributes['fingerprint'];
            if ($row->removed_at !== null) {
                $diff['added'][] = $k; // reappeared
                $changed = true;
            } elseif ($changed) {
                $diff['changed'][] = $k;
            }
            $row->forceFill($attributes + ['removed_at' => null] + ($changed ? ['changed_sha' => $sha, 'changed_at' => $now] : []))->save();
        }
        foreach ($existing as $k => $row) {
            if (! isset($found[$k]) && $row->removed_at === null) {
                $row->forceFill(['removed_at' => $now])->save();
                $diff['removed'][] = $k;
            }
        }

        return $diff;
    }

    /** @return array<string, array<string, mixed>> */
    private function discoverEndpoints(): array
    {
        $spec = Yaml::parseFile(base_path('docs/api/openapi.yaml'));
        $registry = collect($this->registry->entries())->keyBy(fn ($e) => $e['method'].' '.$e['route']);
        $found = [];
        foreach (Routes::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $path = '/'.$route->uri();
                $entry = $registry->get($method.' '.$path);
                $operation = $spec['paths'][$path][strtolower($method)] ?? null;
                $middleware = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m))->sort()->values()->all();
                $found[$method.' '.$path] = [
                    'method' => $method, 'path' => $path, 'name' => $route->getName(), 'action' => $this->actionName($route),
                    'summary' => isset($operation['summary']) ? mb_substr((string) $operation['summary'], 0, 255) : null,
                    'access' => $entry['access'] ?? 'public', 'permission' => $entry['permission'] ?? null, 'scope' => $entry['scope'] ?? 'public',
                    'documented' => $operation !== null,
                    'fingerprint' => hash('sha256', json_encode([$method, $path, $middleware, $this->actionName($route), $this->actionSource($route)])),
                ];
            }
        }
        ksort($found);

        return $found;
    }

    private function actionName(Route $route): string
    {
        $uses = $route->getAction('uses');

        return $uses instanceof Closure ? 'Closure' : str_replace('App\\Http\\Controllers\\', '', (string) $uses);
    }

    /** The handler's own source lines; a body change changes the fingerprint. */
    private function actionSource(Route $route): string
    {
        try {
            $uses = $route->getAction('uses');
            $ref = $uses instanceof Closure ? new ReflectionFunction($uses) : new ReflectionMethod(...explode('@', (string) $uses, 2));
            $lines = file((string) $ref->getFileName()) ?: [];

            return hash('sha256', implode('', array_slice($lines, (int) $ref->getStartLine() - 1, (int) $ref->getEndLine() - (int) $ref->getStartLine() + 1)));
        } catch (Throwable) {
            return 'unresolved';
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function discoverComponents(): array
    {
        $found = [];
        $files = [
            'job' => app_path('Jobs'), 'event' => app_path('Events'), 'listener' => app_path('Listeners'),
            'command' => app_path('Console/Commands'), 'migration' => database_path('migrations'),
            'connector' => app_path('Integrations/Connectors'),
        ];
        foreach ($files as $kind => $dir) {
            foreach (File::isDirectory($dir) ? File::allFiles($dir) : [] as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $name = $kind === 'migration' ? $file->getFilenameWithoutExtension() : str_replace([base_path().'/', '.php'], '', $file->getPathname());
                $found[$kind.':'.$name] = ['kind' => $kind, 'name' => $name, 'fingerprint' => hash_file('sha256', $file->getPathname())];
            }
        }
        // Payment and affiliate provider clients are connectors too (Mollie, Moneybird, Answer24, Daisycon, TradeTracker).
        foreach (File::allFiles(app_path('Services')) as $file) {
            if (preg_match('/(Mollie|Moneybird|Answer24|Daisycon|TradeTracker)[A-Za-z]*\.php$/', $file->getFilename()) === 1) {
                $name = str_replace([base_path().'/', '.php'], '', $file->getPathname());
                $found['connector:'.$name] = ['kind' => 'connector', 'name' => $name, 'fingerprint' => hash_file('sha256', $file->getPathname())];
            }
        }
        foreach (app(Schedule::class)->events() as $event) {
            $name = mb_substr(trim(preg_replace("/^.*artisan'?\s+/", '', (string) $event->command) ?: (string) $event->description), 0, 255);
            if ($name === '') {
                continue;
            }
            $found['schedule:'.$name] = ['kind' => 'schedule', 'name' => $name, 'fingerprint' => hash('sha256', $event->expression.'|'.$name.'|'.(int) $event->withoutOverlapping)];
        }
        ksort($found);

        return $found;
    }
}
