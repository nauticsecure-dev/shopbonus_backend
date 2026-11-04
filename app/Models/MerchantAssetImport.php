<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property int $id
 * @property string $public_id
 * @property int $run_id
 * @property int $webshop_id
 * @property string $evidence_key
 * @property string $source_url
 * @property string $research_hash
 * @property string $content_hash
 * @property string $path
 * @property int $width
 * @property int $height
 * @property string $status
 * @property int|null $banner_asset_id
 */
class MerchantAssetImport extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['path'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $r) {
            if ($r->isDirty(['public_id', 'run_id', 'webshop_id', 'evidence_key', 'source_url', 'research_hash', 'content_hash', 'path', 'width', 'height', 'created_by']) || $r->getOriginal('status') !== 'pending') {
                throw new \LogicException('Asset source and completed review are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Asset import evidence cannot be deleted.'));
    }
}
