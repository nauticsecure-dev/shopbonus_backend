<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * For history/ledger tables: rows may be inserted, never updated or deleted
 * through Eloquent. Corrections are new rows.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new LogicException(static::class.' records are append-only and cannot be updated.'));
        static::deleting(fn () => throw new LogicException(static::class.' records are append-only and cannot be deleted.'));
    }
}
