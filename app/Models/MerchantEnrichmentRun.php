<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property string $status
 * @property string $trigger
 * @property string $source_url
 * @property array<string, mixed> $research
 * @property string $research_hash
 * @property string $text_model
 * @property string|null $image_model
 * @property array<string, mixed>|null $proposal
 * @property bool $review_required
 * @property string|null $risk_level
 * @property string|null $cover_path
 * @property string|null $cover_mime
 * @property string|null $cover_failure_code
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property string|null $failure_code
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable $created_at
 * @property-read Webshop $webshop
 */
class MerchantEnrichmentRun extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return [
            'research' => 'array',
            'proposal' => 'array',
            'review_required' => 'boolean',
            'finished_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Webshop, $this> */
    public function webshop(): BelongsTo
    {
        return $this->belongsTo(Webshop::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $run) {
            foreach (['public_id', 'webshop_id', 'source_url', 'research', 'research_hash', 'text_model', 'image_model', 'trigger'] as $field) {
                if ($run->isDirty($field)) {
                    throw new \LogicException('Enrichment evidence is immutable.');
                }
            }
        });
    }
}
