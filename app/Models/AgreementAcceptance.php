<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property int $agreement_template_id
 * @property string $version
 * @property string $rendered_body
 * @property string $document_hash
 * @property string $signatory_name
 * @property int $accepted_by
 * @property CarbonImmutable $accepted_at
 * @property array<string, mixed>|null $evidence
 * @property-read AgreementTemplate $template
 */
class AgreementAcceptance extends Model
{
    use AppendOnly, HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'webshop_agreement_acceptances';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'agreement_template_id', 'accepted_by'];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'immutable_datetime',
            'evidence' => 'array',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<AgreementTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(AgreementTemplate::class, 'agreement_template_id');
    }
}
