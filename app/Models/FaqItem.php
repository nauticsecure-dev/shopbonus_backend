<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $category_id
 * @property string $audience
 * @property bool $published
 * @property int $position
 * @property array<string, array{question: string, answer: array<string, mixed>}> $translations
 * @property int $revision
 */
class FaqItem extends Model
{
    use HasUlids;

    public const AUDIENCES = ['public', 'consumers', 'merchants'];

    protected $guarded = ['id'];

    protected $hidden = ['id', 'category_id'];

    protected $attributes = ['audience' => 'public', 'published' => false, 'position' => 0, 'revision' => 1];

    protected function casts(): array
    {
        return ['translations' => 'array', 'published' => 'boolean', 'position' => 'integer', 'revision' => 'integer'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
