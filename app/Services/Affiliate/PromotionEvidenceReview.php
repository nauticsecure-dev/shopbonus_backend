<?php

namespace App\Services\Affiliate;

use App\Enums\AuditCategory;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Exceptions\ApiException;
use App\Models\AffiliateConnection;
use App\Models\AffiliateProgram;
use App\Models\AffiliatePromotionReview;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Enrichment\WebsiteResearcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Explicit reviewed field mapping, never inferred advertiser permission. */
class PromotionEvidenceReview
{
    public function __construct(private readonly AuditLogger $audit, private readonly WebsiteResearcher $hashes) {}

    public function authorize(User $actor): void
    {
        $current = User::find($actor->id);
        if (! $current?->isActive() || ! $current->isSuperAdmin()) {
            throw new ApiException('Current Super Admin authority is required.', 403, 'forbidden');
        }
    }

    /** @param array<string,mixed> $mapping */
    public function propose(Offer $offer, AffiliateProgram $program, array $mapping, User $actor): AffiliatePromotionReview
    {
        $this->authorize($actor);
        $v = Validator::make($mapping, ['resource' => ['required', 'in:promotions,access-rules'], 'record' => ['required', 'string', 'max:500'], 'program' => ['required', 'string', 'max:500'], 'conditions' => ['required', 'string', 'max:500'], 'exclusions' => ['required', 'string', 'max:500']]);
        if ($v->fails()) {
            throw new ApiException('Select explicit source fields for review.', 422, 'promotion_mapping_invalid');
        }
        $mapping = $v->validated();

        return DB::transaction(function () use ($offer, $program, $mapping, $actor) {
            $this->authorize($actor);
            $o = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $p = AffiliateProgram::whereKey($program->id)->lockForUpdate()->firstOrFail();
            $c = AffiliateConnection::whereKey($p->connection_id)->lockForUpdate()->firstOrFail();
            $this->context($o, $p, $c);
            $key = $mapping['resource'].($mapping['resource'] === 'promotions' ? '' : ':'.$p->external_id);
            $snapshot = $this->snapshot($c, $key);
            $row = $this->pointer($snapshot['payload'], $mapping['record']);
            $programId = $this->pointer($row, $mapping['program']);
            if (! is_array($row) || (! is_string($programId) && ! is_int($programId)) || (string) $programId !== $p->external_id) {
                throw ApiException::conflict('The source record does not prove this exact program.', 'promotion_program_mismatch');
            }
            $normalized = ['conditions' => $this->texts($this->pointer($row, $mapping['conditions'])), 'exclusions' => $this->texts($this->pointer($row, $mapping['exclusions']))];
            if ($normalized['conditions'] === []) {
                throw ApiException::conflict('Conditions must be explicit; unknown exclusions are not an empty list.', 'promotion_conditions_missing');
            }
            $evidence = ['connection_id' => $c->id, 'program_external_id' => $p->external_id, 'credential_hash' => $this->hashes->hash($c->credentials), 'resource_key' => $key,
                'source_hash' => $this->hashes->hash($snapshot['payload']), 'fetched_at' => $snapshot['fetched_at'], 'mapping' => $mapping, 'source_record' => $row];
            $r = AffiliatePromotionReview::create(['offer_id' => $o->id, 'program_id' => $p->id, 'offer_version_id' => $o->current_version_id, 'created_by' => $actor->id, 'status' => 'pending', 'evidence' => $evidence, 'normalized' => $normalized]);
            $this->audit->record('affiliate.promotion_evidence_proposed', category: AuditCategory::Integration, resource: $r, actorUserId: $actor->id, webshopId: $o->webshop_id, metadata: ['source_hash' => $evidence['source_hash']]);

            return $r;
        });
    }

    public function review(AffiliatePromotionReview $review, User $actor, bool $approve, string $note): AffiliatePromotionReview
    {
        $this->authorize($actor);
        if (trim($note) === '' || mb_strlen($note) > 2000) {
            throw new ApiException('A bounded review note is required.', 422, 'promotion_note_required');
        }

        return DB::transaction(function () use ($review, $actor, $approve, $note) {
            $this->authorize($actor);
            $r = AffiliatePromotionReview::whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'pending') {
                if ($r->status === ($approve ? 'approved' : 'rejected')) {
                    return $r;
                }
                throw ApiException::conflict('This review already has a final decision.', 'promotion_review_completed');
            }
            $o = Offer::whereKey($r->offer_id)->lockForUpdate()->firstOrFail();
            $versionId = null;
            if ($approve) {
                $p = AffiliateProgram::whereKey($r->program_id)->lockForUpdate()->firstOrFail();
                $c = AffiliateConnection::whereKey($p->connection_id)->lockForUpdate()->firstOrFail();
                $this->context($o, $p, $c);
                $e = $r->evidence;
                $snapshot = $this->snapshot($c, $e['resource_key']);
                if ($o->current_version_id !== $r->offer_version_id || $c->id !== $e['connection_id'] || $p->external_id !== $e['program_external_id']
                    || ! hash_equals($e['credential_hash'], $this->hashes->hash($c->credentials)) || ! hash_equals($e['source_hash'], $this->hashes->hash($snapshot['payload'])) || $snapshot['fetched_at'] !== $e['fetched_at']) {
                    throw ApiException::conflict('Source or offer changed; propose and review again.', 'promotion_evidence_stale');
                }
                $current = OfferVersion::whereKey($o->current_version_id)->lockForUpdate()->firstOrFail();
                $terms = implode("\n", $r->normalized['conditions']);
                if ($r->normalized['exclusions'] !== []) {
                    $terms .= "\nExclusions:\n".implode("\n", $r->normalized['exclusions']);
                }
                if (mb_strlen($terms) > 10000) {
                    throw ApiException::conflict('Reviewed terms exceed the offer description limit.', 'promotion_field_invalid');
                }
                $next = $o->versions()->create([...$current->only(OfferVersion::TERM_FIELDS), 'public_description' => $terms, 'combinable' => false, 'cashback_permission' => null,
                    'version' => $current->version + 1, 'created_by' => $actor->id, 'locked_at' => $o->status === OfferStatus::Draft ? null : now()]);
                $versionId = $next->id;
                $o->forceFill(['current_version_id' => $next->id])->save();
            }
            $r->forceFill(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $actor->id, 'review_note' => trim($note), 'published_version_id' => $versionId])->save();
            $this->audit->record('affiliate.promotion_evidence_'.$r->status, category: AuditCategory::Integration, resource: $r, actorUserId: $actor->id, webshopId: $o->webshop_id, metadata: ['version_id' => $versionId]);

            return $r;
        });
    }

    private function context(Offer $o, AffiliateProgram $p, AffiliateConnection $c): void
    {
        if ($o->acquisition !== OfferAcquisition::PromotionalCode || in_array($o->status, [OfferStatus::Ended, OfferStatus::Archived], true) || $p->webshop_id !== $o->webshop_id || ! $p->active || $c->network !== 'daisycon' || $c->status !== 'connected') {
            throw ApiException::conflict('An active exact-shop Daisycon promotional source is required.', 'promotion_source_invalid');
        }
    }

    /** @return array{payload:array<mixed>,fetched_at:string} */
    private function snapshot(AffiliateConnection $connection, string $key): array
    {
        $s = DB::table('affiliate_provider_resources')->where('connection_id', $connection->id)->where('resource_key', $key)->lockForUpdate()->first();
        if ($s === null || ! is_string($s->credential_hash) || ! hash_equals($this->hashes->hash($connection->credentials), $s->credential_hash) || $s->last_error !== null || $s->fetched_at === null || CarbonImmutable::parse($s->fetched_at)->lt(now()->subDays(7)) || CarbonImmutable::parse($s->fetched_at)->isFuture()) {
            throw ApiException::conflict('A complete recent provider snapshot is required.', 'promotion_snapshot_unavailable');
        }
        $payload = json_decode($s->payload, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw ApiException::conflict('Invalid stored source.', 'promotion_snapshot_unavailable');
        }

        return ['payload' => $payload, 'fetched_at' => (string) $s->fetched_at];
    }

    private function pointer(mixed $value, string $pointer): mixed
    {
        if ($pointer === '') {
            return $value;
        }
        if (! str_starts_with($pointer, '/') || preg_match('/~(?![01])/', $pointer)) {
            throw new ApiException('Invalid source pointer.', 422, 'promotion_mapping_invalid');
        }
        foreach (explode('/', substr($pointer, 1)) as $key) {
            $key = str_replace(['~1', '~0'], ['/', '~'], $key);
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                throw ApiException::conflict('A mapped source field is missing.', 'promotion_field_missing');
            }
            $value = $value[$key];
        }

        return $value;
    }

    /** @return list<string> */
    private function texts(mixed $value): array
    {
        $values = is_string($value) ? [$value] : $value;
        if (! is_array($values) || ! array_is_list($values) || count($values) > 30) {
            throw ApiException::conflict('Map explicit text or a list of text; missing exclusions are unknown.', 'promotion_field_invalid');
        }
        foreach ($values as $v) {
            if (! is_string($v) || trim($v) === '' || mb_strlen($v) > 1000 || strip_tags($v) !== $v) {
                throw ApiException::conflict('Provider terms require bounded plain text.', 'promotion_field_invalid');
            }
        }

        return array_map('trim', $values);
    }
}
