<?php

namespace App\Services\Cms;

use App\Models\CmsAsset;
use Illuminate\Validation\ValidationException;

/** Soft archive preserves existing usage/history, but cannot introduce a new archived reference. */
class CmsAssetPolicy
{
    /** Call inside the content mutation transaction, after locale locks.
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     */
    public function assertNewReferences(array $before, array $after): void
    {
        $bindings = app(CmsContentBindings::class);
        $old = $bindings->references($before);
        $new = $bindings->references($after);
        $assets = CmsAsset::whereIn('public_id', array_values($new))->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');
        foreach ($new as $path => $id) {
            $asset = $assets[$id] ?? null;
            if ($asset === null || ($asset->archived_at !== null && ($old[$path] ?? null) !== $id)) {
                throw ValidationException::withMessages(['content.'.$path => 'Choose an active CMS asset for this new usage.']);
            }
        }
    }
}
