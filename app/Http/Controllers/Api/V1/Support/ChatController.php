<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\FaqItem;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportReply;
use App\Models\Webshop;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\RichText;
use App\Services\Support\SupportChat;
use App\Services\Support\SupportReviews;
use App\Services\Support\SupportTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ChatController extends Controller
{
    public function store(Request $r, SupportChat $chat): JsonResponse
    {
        $data = $r->validate(['message' => ['required', 'string', 'max:2000'], 'conversation_id' => ['sometimes', 'nullable', 'ulid'],
            'workspace_id' => ['sometimes', 'nullable', 'ulid'], 'reference' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._-]+$/D'],
            'locale' => ['sometimes', Rule::in(['en', 'nl'])], 'current_page' => ['sometimes', 'nullable', 'string', 'max:200', 'regex:#^/app(?:/[a-zA-Z0-9/_-]*)?$#D'],
            'user_id' => ['prohibited'], 'role' => ['prohibited'], 'allowed_tools' => ['prohibited']]);
        $key = validator(['key' => $r->header('Idempotency-Key')], ['key' => ['required', 'uuid']])->validate()['key'];
        $m = $chat->submit($r->user(), $data, $key)->fresh();

        return response()->json(['data' => ['id' => $m->public_id, 'conversation_id' => SupportConversation::findOrFail($m->conversation_id)->public_id, 'status' => $m->status]], 202, ['Cache-Control' => 'private, no-store']);
    }

    public function index(Request $r, SupportTools $tools): JsonResponse
    {
        $tools->authorize($r->user(), null);
        $rows = SupportConversation::where('user_id', $r->user()->id)->where('expires_at', '>', now())->latest('id')->limit(50)->get()->filter(function ($c) use ($r) {
            return $c->webshop_id === null || (Webshop::find($c->webshop_id)?->memberships()->where('user_id', $r->user()->id)->where('status', 'active')->exists() ?? false);
        })->map(fn ($c) => ['id' => $c->public_id, 'locale' => $c->locale, 'workspace_id' => $c->webshop_id === null ? null : Webshop::findOrFail($c->webshop_id)->public_id, 'expires_at' => $c->expires_at->toIso8601String()])->values();

        return response()->json(['data' => $rows], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function show(Request $r, string $conversation, SupportChat $chat): JsonResponse
    {
        $c = $chat->conversation($r->user(), $conversation);
        $data = $r->validate(['before' => ['sometimes', 'ulid']]);
        $before = isset($data['before']) ? SupportMessage::where('conversation_id', $c->id)->where('public_id', strtolower($data['before']))->value('id') : null;
        $messages = SupportMessage::where('conversation_id', $c->id)->when($before !== null, fn ($q) => $q->where('id', '<', $before))->latest('id')->limit(50)->get()->reverse()->values();

        return response()->json(['data' => ['id' => $c->public_id, 'messages' => $messages->map(fn ($m) => $chat->present($r->user(), $m))->all(),
            'replies' => SupportReply::where('conversation_id', $c->id)->latest('id')->limit(50)->get()->reverse()->values()->map(fn ($reply) => ['id' => $reply->public_id, 'sender' => 'human_support', 'body' => $reply->body, 'created_at' => $reply->created_at->toIso8601String()])->all(),
            'next_before' => $messages->first()?->public_id]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function message(Request $r, string $message, SupportChat $chat): JsonResponse
    {
        return response()->json(['data' => $chat->present($r->user(), $chat->ownedMessage($r->user(), $message))], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function destroy(Request $r, string $conversation, SupportChat $chat, AuditLogger $audit): JsonResponse
    {
        $c = $chat->conversation($r->user(), $conversation);
        DB::transaction(function () use ($c, $r, $audit) {
            SupportConversation::whereKey($c->id)->lockForUpdate()->firstOrFail();
            if (SupportMessage::where('conversation_id', $c->id)->whereIn('status', SupportChat::ACTIVE)->exists()) {
                throw ApiException::conflict('Wait for the current support answer.', 'support_conversation_busy');
            }
            $audit->record('support.conversation_deleted', category: AuditCategory::Security, resource: $c, actorUserId: $r->user()->id);
            $c->delete();
        });

        return response()->json(null, 204);
    }

    public function feedback(Request $r, string $message, SupportChat $chat, SupportReviews $reviews): JsonResponse
    {
        $data = $r->validate(['rating' => ['required', Rule::in(['up', 'down'])], 'reason' => ['required_if:rating,down', 'nullable', Rule::in(['incorrect_information', 'did_not_answer', 'outdated_information', 'unclear', 'other'])], 'comment' => ['sometimes', 'nullable', 'string', 'max:2000']]);
        $f = $reviews->feedback($r->user(), $chat->ownedMessage($r->user(), $message), $data);

        return response()->json(['data' => ['id' => $f->public_id, 'rating' => $f->rating, 'review_required' => $f->rating === 'down']]);
    }

    public function escalate(Request $r, string $message, SupportChat $chat, SupportReviews $reviews): JsonResponse
    {
        $review = $reviews->escalate($r->user(), $chat->ownedMessage($r->user(), $message));

        return response()->json(['data' => ['id' => $review->public_id, 'status' => 'ESCALATED', 'notice' => 'A human support review has been requested.']]);
    }

    public function knowledge(Request $r, SupportTools $tools): JsonResponse
    {
        $data = $r->validate(['query' => ['required', 'string', 'min:2', 'max:100'], 'workspace_id' => ['sometimes', 'nullable', 'ulid'], 'locale' => ['sometimes', Rule::in(['en', 'nl'])]]);
        $workspace = $data['workspace_id'] ?? null;
        $tools->authorize($r->user(), $workspace);
        $locale = $data['locale'] ?? 'en';
        $search = mb_strtolower($data['query']);
        $items = FaqItem::where('published', true)->whereIn('audience', ['public', $workspace === null ? 'consumers' : 'merchants'])->orderByDesc('id')->limit(200)->get()->filter(function ($i) use ($locale, $search) {
            $t = $i->translations[$locale] ?? $i->translations['nl'] ?? null;

            return $t !== null && str_contains(mb_strtolower($t['question'].' '.RichText::plainText($t['answer'])), $search);
        })->take(10)->map(function ($i) use ($tools, $r, $workspace, $locale) {
            $e = $tools->retrieve($r->user(), 'get_approved_knowledge', ['document_id' => $i->public_id], $workspace, $locale);

            return ['document_id' => $i->public_id, 'scope' => $e->facts['scope'], 'version' => $e->facts['version'], 'approved_at' => $e->facts['approved_at'], 'locale' => $e->facts['locale'], 'content' => $e->facts['content'], 'checked_at' => $e->retrievedAt->toIso8601String()];
        })->values();

        return response()->json(['data' => $items], 200, ['Cache-Control' => 'private, no-store']);
    }
}
