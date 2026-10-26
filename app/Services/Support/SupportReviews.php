<?php

namespace App\Services\Support;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\FaqItem;
use App\Models\SupportConversation;
use App\Models\SupportFeedback;
use App\Models\SupportMessage;
use App\Models\SupportRegression;
use App\Models\SupportReply;
use App\Models\SupportReview;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationInbox;
use Illuminate\Support\Facades\DB;

final class SupportReviews
{
    public function __construct(private readonly SupportChat $chat, private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function feedback(User $actor, SupportMessage $m, array $data): SupportFeedback
    {
        if ($m->completed_at === null) {
            throw ApiException::conflict('Wait for the current support answer.', 'support_message_pending');
        }

        return DB::transaction(function () use ($actor, $m, $data) {
            SupportMessage::whereKey($m->id)->lockForUpdate()->firstOrFail();
            $f = SupportFeedback::where('message_id', $m->id)->where('user_id', $actor->id)->first();
            if ($f !== null) {
                if ($f->rating !== $data['rating'] || $f->reason !== ($data['reason'] ?? null) || $f->comment !== ($data['comment'] ?? null)) {
                    throw ApiException::conflict('Feedback is already recorded.', 'support_feedback_conflict');
                }

                return $f;
            }
            $f = SupportFeedback::create(['message_id' => $m->id, 'user_id' => $actor->id, 'rating' => $data['rating'], 'reason' => $data['reason'] ?? null, 'comment' => $data['comment'] ?? null]);
            if ($f->rating === 'down') {
                SupportReview::firstOrCreate(['message_id' => $m->id, 'trigger' => 'negative_feedback']);
            }
            $this->audit->record('support.feedback_recorded', category: AuditCategory::Security, resource: $f, actorUserId: $actor->id, metadata: ['rating' => $f->rating]);
            DB::afterCommit(fn () => $this->chat->broadcast($m, 'ChatFeedbackSubmitted'));

            return $f;
        });
    }

    public function escalate(User $actor, SupportMessage $m): SupportReview
    {
        return DB::transaction(function () use ($actor, $m) {
            SupportMessage::whereKey($m->id)->lockForUpdate()->firstOrFail();
            $review = SupportReview::firstOrCreate(['message_id' => $m->id, 'trigger' => 'customer_request']);
            $this->audit->record('support.review_requested', category: AuditCategory::Security, resource: $review, actorUserId: $actor->id);
            DB::afterCommit(fn () => $this->chat->broadcast($m, 'ChatReviewRequired'));

            return $review;
        });
    }

    /** @param array<string, mixed> $data */
    public function resolve(User $actor, SupportReview $review, array $data): SupportReview
    {
        return DB::transaction(function () use ($actor, $review, $data) {
            $review = SupportReview::whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($review->reviewed_at !== null) {
                throw ApiException::conflict('This support review is resolved.', 'support_review_resolved');
            }
            $knowledge = isset($data['knowledge_id']) ? FaqItem::where('public_id', strtolower($data['knowledge_id']))->where('published', true)->first() : null;
            if (isset($data['knowledge_id']) && $knowledge === null) {
                throw new ApiException('Published knowledge not found.', 404, 'not_found');
            }
            $review->forceFill(['outcome' => $data['outcome'], 'note' => $data['note'], 'reviewed_by' => $actor->id, 'reviewed_at' => now()])->save();
            if ($data['outcome'] === 'CONFIRMED_ERROR') {
                $m = SupportMessage::findOrFail($review->message_id);
                SupportRegression::create(['review_id' => $review->id, 'question' => $m->question,
                    'expected_intent' => $data['expected_intent'], 'knowledge_id' => $knowledge?->id]);
            }
            $this->audit->record('support.review_resolved', category: AuditCategory::Admin, resource: $review, actorUserId: $actor->id,
                metadata: ['outcome' => $review->outcome, 'knowledge_ref' => $knowledge?->public_id]);

            return $review;
        });
    }

    public function reply(User $actor, SupportConversation $c, string $body): SupportReply
    {
        if ($c->expires_at->isPast()) {
            throw ApiException::conflict('This support conversation has expired.', 'support_conversation_expired');
        }

        return DB::transaction(function () use ($actor, $c, $body) {
            $r = SupportReply::create(['conversation_id' => $c->id, 'created_by' => $actor->id, 'body' => $body]);
            app(NotificationInbox::class)->supportReply($c, $r);
            $this->audit->record('support.human_replied', category: AuditCategory::Admin, resource: $r, actorUserId: $actor->id);
            $m = SupportMessage::where('conversation_id', $c->id)->latest('id')->first();
            if ($m !== null) {
                DB::afterCommit(fn () => $this->chat->broadcast($m, 'ChatSupportReplyAvailable'));
            }

            return $r;
        });
    }
}
