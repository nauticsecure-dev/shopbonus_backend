<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $review_id
 * @property string $question
 * @property string $expected_intent
 * @property int|null $knowledge_id
 */
class SupportRegression extends Model
{
    use AppendOnly, HasUlids;

    protected $table = 'support_regressions';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'user_id', 'webshop_id', 'conversation_id', 'message_id', 'request_key', 'request_hash'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['question' => 'encrypted'];
    }
}
