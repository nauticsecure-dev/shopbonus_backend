<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only status history of an affiliate transaction.
 *
 * @property int $id
 * @property int $transaction_id
 * @property string $source
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $network_status
 * @property string|null $note
 * @property int|null $actor_user_id
 */
class AffiliateReconciliationEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}
