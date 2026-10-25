<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

/** Append-only companion integrity evidence for an AuditEvent; database triggers also enforce immutability. */
class AuditEventSeal extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = ['id'];
}
