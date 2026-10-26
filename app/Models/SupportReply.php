<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $conversation_id
 * @property int $created_by
 * @property string $body
 * @property CarbonImmutable $created_at
 */
class SupportReply extends Model
{
    use AppendOnly, HasUlids;

    protected $table = 'support_replies';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'user_id', 'webshop_id', 'conversation_id', 'message_id', 'request_key', 'request_hash'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'created_at' => 'immutable_datetime'];
    }
}
