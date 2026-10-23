<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Do-not-contact entry (opt-out). webshop_id null = applies to every webshop.
 * Only keyed hashes are stored, never the email or phone itself.
 *
 * @property int $id
 * @property int|null $webshop_id
 * @property string $kind
 * @property string $subject_hash
 * @property string|null $reason
 * @property string $source
 * @property int|null $created_by
 */
class ContactSuppression extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public static function emailHash(string $email): string
    {
        return hash_hmac('sha256', 'email|'.mb_strtolower(trim($email)), (string) config('app.key'));
    }
}
