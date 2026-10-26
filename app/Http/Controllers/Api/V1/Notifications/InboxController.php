<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Http\Controllers\Controller;
use App\Services\Notifications\NotificationInbox;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class InboxController extends Controller
{
    public function index(Request $request, NotificationInbox $inbox): JsonResponse
    {
        $input = $request->validate(['filter' => ['sometimes', Rule::in(['all', 'unread'])], 'page' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = $inbox->visible($request->user());
        $unread = (clone $query)->whereNull('read_at')->count();
        $page = $query->when(($input['filter'] ?? 'all') === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($input['per_page'] ?? 20);

        return response()->json(['data' => collect($page->items())->map(fn ($n) => $inbox->present($n))->all(),
            'meta' => ['unread_count' => $unread, 'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function read(Request $request, NotificationInbox $inbox, string $notification): JsonResponse
    {
        return response()->json(['data' => $inbox->read($request->user(), $notification)]);
    }

    public function readAll(Request $request, NotificationInbox $inbox): JsonResponse
    {
        return response()->json(['data' => ['marked_read' => $inbox->readAll($request->user())]]);
    }

    public function preferences(Request $request, NotificationPreferences $preferences): JsonResponse
    {
        return response()->json(['data' => $preferences->current($request->user())]);
    }

    public function updatePreferences(Request $request, NotificationPreferences $preferences): JsonResponse
    {
        $input = $request->validate([
            'locale' => ['sometimes', Rule::in(['nl', 'en'])], 'email_enabled' => ['sometimes', 'boolean'],
            'preferences' => ['sometimes', 'array:'.implode(',', NotificationPreferences::EVENTS)],
            'preferences.*' => ['array:in_app,email'], 'preferences.*.in_app' => ['sometimes', 'boolean', 'accepted'],
            'preferences.*.email' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $preferences->update($request->user(), $input)]);
    }
}
