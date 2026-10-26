<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property int $campaign_id
 * @property int $requested_by
 * @property string $kind
 * @property string $status
 * @property int|null $source_offer_version_id
 * @property int $revision
 * @property string $model
 * @property array<string, mixed> $context
 * @property string $context_hash
 * @property array<string, mixed> $source_config
 * @property string|null $failure_code
 */
class BannerAiRequest extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['context' => 'array', 'source_config' => 'array', 'revision' => 'integer'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $m) {
            foreach (['webshop_id', 'campaign_id', 'requested_by', 'request_key', 'kind', 'revision', 'source_offer_version_id', 'model', 'context', 'context_hash', 'source_config'] as $field) {
                if ($m->isDirty($field)) {
                    throw new LogicException('AI request evidence is immutable.');
                }
            }
        });
        static::deleting(fn () => throw new LogicException('AI request evidence is immutable.'));
    }
}
