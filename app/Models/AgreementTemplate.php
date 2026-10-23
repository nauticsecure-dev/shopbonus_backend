<?php

namespace App\Models;

use App\Enums\AgreementTemplateStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property string $key
 * @property string $version
 * @property string $locale
 * @property string $title
 * @property string $body
 * @property AgreementTemplateStatus $status
 * @property bool $requires_reacceptance
 * @property CarbonImmutable|null $published_at
 * @property int|null $published_by
 * @property CarbonImmutable|null $retired_at
 * @property int $created_by
 * @property CarbonImmutable $created_at
 */
class AgreementTemplate extends Model
{
    use HasUlids;

    public const MERCHANT = 'merchant_agreement';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'created_by', 'published_by'];

    protected function casts(): array
    {
        return [
            'status' => AgreementTemplateStatus::class,
            'requires_reacceptance' => 'boolean',
            'published_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected static function booted(): void
    {
        // A published text is legal evidence: its wording can never change.
        static::updating(function (self $template) {
            if ($template->getRawOriginal('status') !== AgreementTemplateStatus::Draft->value
                && $template->isDirty(['key', 'version', 'locale', 'title', 'body'])) {
                throw new LogicException('Published agreement templates are immutable.');
            }
        });
        static::deleting(function (self $template) {
            if ($template->status !== AgreementTemplateStatus::Draft) {
                throw new LogicException('Published agreement templates cannot be deleted.');
            }
        });
    }
}
