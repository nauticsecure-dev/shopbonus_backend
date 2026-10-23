<?php

namespace App\Models;

use App\Enums\LegalDocumentType;
use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property LegalDocumentType $document_type
 * @property string $document_version
 * @property CarbonImmutable $accepted_at
 * @property array<string, mixed>|null $evidence
 */
class LegalAcceptance extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'document_type' => LegalDocumentType::class,
            'accepted_at' => 'immutable_datetime',
            'evidence' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
