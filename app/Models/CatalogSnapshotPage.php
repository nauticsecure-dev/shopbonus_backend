<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One signed page of a merchant-pushed catalog snapshot (OpenCart extension worker).
 * Items are encrypted at rest: coupon codes are merchant secrets.
 *
 * @property int $id
 * @property int $connection_id
 * @property int $credential_generation
 * @property string $kind
 * @property string $snapshot_id
 * @property int $page
 * @property bool $last
 * @property string $currency
 * @property list<array<string,mixed>> $items
 * @property CarbonImmutable $received_at
 */
class CatalogSnapshotPage extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'encrypted:array', 'last' => 'boolean', 'received_at' => 'immutable_datetime'];
    }

    /** The newest snapshot whose pages 1..N all arrived and page N is marked last; null if none is complete. */
    public static function latestComplete(int $connectionId, int $generation, string $kind): ?string
    {
        $candidates = self::where('connection_id', $connectionId)->where('credential_generation', $generation)->where('kind', $kind)
            ->where('last', true)->orderByDesc('received_at')->orderByDesc('id')->limit(5)->get(['snapshot_id', 'page']);
        foreach ($candidates as $final) {
            $count = self::where('connection_id', $connectionId)->where('credential_generation', $generation)->where('kind', $kind)
                ->where('snapshot_id', $final->snapshot_id)->where('page', '<=', $final->page)->count();
            if ($count === $final->page) {
                return $final->snapshot_id;
            }
        }

        return null;
    }
}
