<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\Admin\StepUpCodes;
use App\Services\Admin\SupportSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Super Admin support sessions (Trello #21 "Impersonation"). Starting one
 * needs a recent password and an emailed step-up code, which is used up by
 * the start; ending one needs neither.
 */
class SupportSessionController extends Controller
{
    public function __construct(private readonly SupportSessions $sessions) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(['active', 'ended', 'expired'])]]);

        $rows = ImpersonationSession::with(['admin', 'target'])
            ->when(($data['status'] ?? null) === 'active', fn ($q) => $q->whereNull('ended_at')->where('expires_at', '>', now()))
            ->when(($data['status'] ?? null) === 'ended', fn ($q) => $q->whereNotNull('ended_at'))
            ->when(($data['status'] ?? null) === 'expired', fn ($q) => $q->whereNull('ended_at')->where('expires_at', '<=', now()))
            ->orderByDesc('id')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (ImpersonationSession $s) => self::present($s))->all()]);
    }

    public function show(ImpersonationSession $session): JsonResponse
    {
        // Complete action history: every request made with the session's token.
        $actions = AuditEvent::where('resource_type', $session->getMorphClass())->where('resource_id', (string) $session->id)
            ->orderBy('id')->limit(1000)->get()
            ->map(fn (AuditEvent $e) => [
                'occurred_at' => $e->occurred_at->toIso8601String(),
                'action' => $e->action,
                'outcome' => $e->outcome->value,
                'http_method' => $e->http_method,
                'route' => $e->route,
                'route_name' => $e->metadata['route_name'] ?? null,
                'http_status' => $e->http_status,
                'request_id' => $e->request_id,
            ])->all();

        return response()->json(['data' => self::present($session) + ['actions' => $actions]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string', 'max:26'],
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        $target = User::where('public_id', $data['user_id'])->first();
        abort_if($target === null, 404);

        [$session, $token] = $this->sessions->start($request->user(), $target, $data['reason']);

        // The emailed code authorises this one start only.
        $request->session()->forget(StepUpCodes::SESSION_KEY);

        return response()->json(['data' => self::present($session) + [
            'token' => $token,
            'token_type' => 'Bearer',
        ]], 201);
    }

    public function end(ImpersonationSession $session): JsonResponse
    {
        return response()->json(['data' => self::present($this->sessions->end($session, 'ended_by_admin'))]);
    }

    /** @return array<string, mixed> */
    public static function present(ImpersonationSession $s): array
    {
        return [
            'id' => $s->public_id,
            'status' => $s->status(),
            'admin' => ['id' => $s->admin->public_id, 'first_name' => $s->admin->first_name, 'email' => $s->admin->email],
            'target' => ['id' => $s->target->public_id, 'first_name' => $s->target->first_name, 'email' => $s->target->email],
            'reason' => $s->reason,
            'started_at' => $s->created_at->toIso8601String(),
            'expires_at' => $s->expires_at->toIso8601String(),
            'ended_at' => $s->ended_at?->toIso8601String(),
            'ended_reason' => $s->ended_reason,
        ];
    }
}
