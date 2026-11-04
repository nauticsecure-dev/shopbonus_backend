<?php

namespace App\Services\Cms;

use App\Exceptions\ApiException;
use App\Models\CmsAsset;
use App\Models\CmsMediaDerivative;
use App\Models\CmsMediaRecipe;
use App\Models\CmsPage;
use App\Models\CmsPageLocale;
use App\Models\CmsRevision;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Authoritative backend snapshots only; never claims discovery of client code or tenant content. */
class CmsContentBindings
{
    /** @param array<string,mixed> $content
     * @return array<string,string>
     */
    public function references(array $content): array
    {
        $out = [];
        $walk = function (array $node, string $path) use (&$walk, &$out): void {
            foreach ($node as $key => $value) {
                if ($key === 'media_recipes') {
                    continue;
                }
                $p = ltrim($path.'.'.$key, '.');
                if (is_string($value) && ($key === 'asset_id' || str_ends_with((string) $key, '_asset_id'))) {
                    $out[$p] = $value;
                } elseif (is_array($value) && $value !== []) {
                    $walk($value, $p);
                }
            }
        };
        $walk($content, '');

        return $out;
    }

    /** @param array<string,mixed> $content
     * @return array<string,string>
     */
    public function validatePins(array $content, mixed $pins): array
    {
        if (! is_array($pins) || count($pins) > 200) {
            throw ValidationException::withMessages(['content.media_recipes' => 'Use a map of at most 200 image-field paths to recipe IDs.']);
        }
        $refs = $this->references($content);
        foreach ($pins as $path => $id) {
            if (! is_string($path) || strlen($path) > 300 || ! isset($refs[$path]) || ! is_string($id) || ! Str::isUlid($id)) {
                throw ValidationException::withMessages(['content.media_recipes' => 'Pin an existing image field to its exact asset recipe.']);
            }
            $asset = CmsAsset::where('public_id', $refs[$path])->first();
            $recipe = $asset === null ? null : CmsMediaRecipe::where('asset_id', $asset->id)->where('public_id', $id)->first();
            if ($recipe === null || $recipe->original_hash !== $asset->original_hash || CmsMediaDerivative::where('recipe_id', $recipe->id)->where('status', 'ready')->whereIn('breakpoint', CmsMedia::BREAKPOINTS)->count() !== 3) {
                throw ValidationException::withMessages(['content.media_recipes' => 'All three derivatives of the exact asset recipe must be ready.']);
            }
        }
        ksort($pins);

        return $pins;
    }

    /** @return array{content:array<string,mixed>,source:array<string,mixed>} */
    public function snapshot(CmsPage $page, string $locale, string $version): array
    {
        if (! in_array($locale, CmsService::LOCALES, true)) {
            throw new ApiException('Content not found.', 404, 'not_found');
        }
        $l = CmsPageLocale::where('page_id', $page->id)->where('locale', $locale)->first() ?? throw new ApiException('Content not found.', 404, 'not_found');
        if ($version === 'draft') {
            $content = $l->draft_content ?? [];
            $identity = ['kind' => 'draft', 'id' => $page->public_id.':'.$locale, 'revision' => $l->draft_revision];
        } else {
            $query = CmsRevision::where('page_id', $page->id)->where('locale', $locale);
            $r = ($version === 'published' ? $query->whereKey($l->published_revision_id) : $query->where('public_id', $version))->first() ?? throw new ApiException('Content not found.', 404, 'not_found');
            $content = $r->content;
            $identity = ['kind' => 'revision', 'id' => $r->public_id, 'revision' => $r->number];
        }
        $source = ['scope' => 'platform_global', 'webshop_id' => null, 'page_id' => $page->public_id, 'page_key' => $page->key, 'locale' => $locale, 'version' => $identity, 'content_sha256' => hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];

        return ['content' => $content, 'source' => $source];
    }

    /** @param array{content:array<string,mixed>,source:array<string,mixed>} $snapshot
     * @return list<array<string,mixed>>
     */
    public function fields(CmsPage $page, array $snapshot): array
    {
        $text = array_column(CmsTextBindings::discover($snapshot['content'], $page->type, $page->key), null, 'path');
        $refs = $this->references($snapshot['content']);
        $out = [];
        $walk = function (array $node, string $path) use (&$walk, &$out, $snapshot, $text, $refs): void {
            foreach ($node as $key => $value) {
                if ($key === 'media_recipes') {
                    continue;
                }
                $p = ltrim($path.'.'.$key, '.');
                if (is_array($value) && $value !== []) {
                    $walk($value, $p);
                } else {
                    $out[] = ['path' => $p, 'source' => $snapshot['source'], 'value' => $value, 'translatable' => isset($text[$p]), 'kind' => isset($refs[$p]) ? 'media_reference' : (isset($text[$p]) ? 'translatable_text' : 'content_value')];
                }
            }
        };
        $walk($snapshot['content'], '');

        return $out;
    }

    /** @param array{content:array<string,mixed>,source:array<string,mixed>} $snapshot
     * @return array<string,mixed>
     */
    public function preview(array $snapshot): array
    {
        $usages = [];
        foreach ($this->references($snapshot['content']) as $path => $id) {
            $asset = CmsAsset::where('public_id', $id)->first();
            $pin = $snapshot['content']['media_recipes'][$path] ?? null;
            $recipe = $asset === null || ! is_string($pin) ? null : CmsMediaRecipe::where('asset_id', $asset->id)->where('public_id', $pin)->first();
            $derivatives = [];
            if ($recipe !== null) {
                foreach (CmsMediaDerivative::where('recipe_id', $recipe->id)->get() as $d) {
                    $derivatives[$d->breakpoint] = ['status' => $d->status, 'width' => $d->width, 'height' => $d->height, 'sha256' => $d->content_hash, 'url' => $d->status === 'ready' ? route('api.v1.public.content-assets.derivative', ['id' => $id, 'recipe' => $recipe->public_id, 'breakpoint' => $d->breakpoint]) : null];
                }
            }
            $usages[] = ['path' => $path, 'component_path' => explode('.', $path)[0], 'asset_id' => $id, 'recipe_id' => $recipe?->public_id, 'recipe' => $recipe?->recipe, 'pin_state' => $recipe === null ? 'recipe_not_pinned' : 'pinned',
                'compatibility_url' => $asset === null ? null : route('api.v1.public.content-assets.show', ['id' => $id]), 'derivatives' => $derivatives];
        }

        return ['source' => $snapshot['source'], 'content' => $snapshot['content'], 'media_usages' => $usages, 'viewports' => ['desktop' => 1440, 'tablet' => 768, 'mobile' => 390],
            'evidence_scope' => 'Backend component content and pinned artifact manifest only; actual client layout/rendering and exhaustive frontend coverage are unverified.'];
    }
}
