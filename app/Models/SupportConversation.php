<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int|null $webshop_id
 * @property string $locale
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 */
class SupportConversation extends Model
{
    use HasUlids;

    protected $table = 'support_conversations';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'user_id', 'webshop_id', 'conversation_id', 'message_id', 'request_key', 'request_hash'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
