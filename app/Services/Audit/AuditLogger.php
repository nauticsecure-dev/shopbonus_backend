<?php

namespace App\Services\Audit;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Logging\SensitiveDataProcessor;
use App\Models\AuditEvent;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single entry point for writing audit_events.
 *
 * Request-scoped facts come from Context so that later middleware can supply
 * them without changing call sites:
 *   - request_id          (AssignRequestId)
 *   - webshop_id          (workspace resolution, B03)
 *   - real_actor_user_id  (Super Admin support sessions, B16)
 *
 * Use record() inside business transactions where a missing audit row must
 * abort the operation (e.g. wallet postings). Use recordSafely() for
 * observational events where the response must not fail because of audit.
 */
class AuditLogger
{
    public const CONTEXT_WEBSHOP_ID = 'webshop_id';

    public const CONTEXT_REAL_ACTOR_ID = 'real_actor_user_id';

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly SensitiveDataProcessor $redactor,
        private readonly AuditIntegrity $integrity,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata  Redacted before storage.
     */
    public function record(
        string $action,
        AuditOutcome $outcome = AuditOutcome::Success,
        AuditCategory $category = AuditCategory::Security,
        Model|string|null $resource = null,
        int|string|null $resourceId = null,
        ?int $webshopId = null,
        array $metadata = [],
        ?int $httpStatus = null,
        ?int $actorUserId = null,
        ?Request $request = null,
    ): AuditEvent {
        $request ??= $this->currentRequest();

        if ($resource instanceof Model) {
            $resourceId ??= $resource->getKey();
            $resource = $resource->getMorphClass();
        }

        $actorUserId ??= $this->currentUserId();
        $realActorId = Context::get(self::CONTEXT_REAL_ACTOR_ID);

        return $this->integrity->record(fn () => AuditEvent::create([
            'occurred_at' => now(),
            'request_id' => Context::get('request_id'),
            'category' => $category,
            'action' => $action,
            'outcome' => $outcome,
            'actor_user_id' => $actorUserId,
            'real_actor_user_id' => $realActorId ?? $actorUserId,
            'webshop_id' => $webshopId ?? Context::get(self::CONTEXT_WEBSHOP_ID),
            'resource_type' => $resource,
            'resource_id' => $resourceId === null ? null : (string) $resourceId,
            'http_status' => $httpStatus,
            'http_method' => $request?->getMethod(),
            'route' => $request?->route()?->uri(),
            'ip_hash' => $this->hashIp($request?->ip()),
            'metadata' => $metadata === [] ? null : $this->redactor->redactArray($metadata),
        ]));
    }

    /**
     * Same as record(), but a storage failure is logged and swallowed.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordSafely(
        string $action,
        AuditOutcome $outcome = AuditOutcome::Success,
        AuditCategory $category = AuditCategory::Security,
        Model|string|null $resource = null,
        int|string|null $resourceId = null,
        ?int $webshopId = null,
        array $metadata = [],
        ?int $httpStatus = null,
        ?int $actorUserId = null,
        ?Request $request = null,
    ): ?AuditEvent {
        try {
            return $this->record(
                $action, $outcome, $category, $resource, $resourceId,
                $webshopId, $metadata, $httpStatus, $actorUserId, $request,
            );
        } catch (Throwable $e) {
            Log::error('Audit event could not be stored', [
                'action' => $action,
                'exception_class' => $e::class,
            ]);

            return null;
        }
    }

    public function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    /**
     * The current HTTP request, or null outside one (queue workers, console),
     * where the container's placeholder request would give a misleading
     * method/IP.
     */
    private function currentRequest(): ?Request
    {
        $request = app()->bound('request') ? app('request') : null;

        return $request instanceof Request && $request->route() !== null ? $request : null;
    }

    private function currentUserId(): ?int
    {
        $id = $this->auth->guard()->id();

        return is_numeric($id) ? (int) $id : null;
    }
}
