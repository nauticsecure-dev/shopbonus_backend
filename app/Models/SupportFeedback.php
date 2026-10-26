<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $message_id
 * @property int $user_id
 * @property string $rating
 * @property string|null $reason
 * @property string|null $comment
 */
class SupportFeedback extends Model
{
    use AppendOnly, HasUlids;

    protected $table = 'support_feedback';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'user_id', 'webshop_id', 'conversation_id', 'message_id', 'request_key', 'request_hash'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['comment' => 'encrypted'];
    }
}
