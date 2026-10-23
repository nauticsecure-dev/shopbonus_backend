<?php

namespace App\Services\Discovery;

use App\Enums\AuditCategory;
use App\Enums\WebshopStatus;
use App\Exceptions\ApiException;
use App\Models\Webshop;
use App\Models\WebshopMembership;
use App\Models\WebshopPublicProfile;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Merchant management of the public profile (Trello #18 "Show only
 * merchant-approved public information").
 */
class PublicProfileService
{
    public const DISK = 'local';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{display_name?: string, short_description?: string|null, description?: string|null, website_url?: string|null, categories?: list<string>, slug?: string|null, published?: bool}  $data
     */
    public function save(WebshopMembership $actor, array $data): WebshopPublicProfile
    {
        $webshop = $actor->webshop;

        return DB::transaction(function () use ($actor, $webshop, $data) {
            $profile = WebshopPublicProfile::where('webshop_id', $webshop->id)->lockForUpdate()->first()
                ?? new WebshopPublicProfile(['webshop_id' => $webshop->id, 'display_name' => $webshop->name]);

            if (isset($data['slug']) && $data['slug'] !== $profile->slug) {
                if ($profile->first_published_at !== null) {
                    throw ApiException::conflict('The public address cannot change after the profile was first published.', 'slug_locked');
                }
                if (WebshopPublicProfile::where('slug', $data['slug'])->where('webshop_id', '!=', $webshop->id)->exists()) {
                    throw ValidationException::withMessages(['slug' => 'This public address is already taken.']);
                }
                $profile->slug = $data['slug'];
            }

            $profile->fill(array_intersect_key($data, array_flip(['display_name', 'short_description', 'description', 'website_url'])));
            $profile->slug ??= $this->uniqueSlug($profile->display_name);

            $categories = $data['categories'] ?? ($profile->exists ? $profile->categories() : []);
            $publish = $data['published'] ?? $profile->published;

            if ($publish) {
                $missing = array_keys(array_filter([
                    'display_name' => trim((string) $profile->display_name) === '',
                    'short_description' => trim((string) $profile->short_description) === '',
                    'categories' => $categories === [],
                ]));
                if ($missing !== []) {
                    throw new ApiException('Complete the public profile before publishing it.', 422, 'public_profile_incomplete', ['missing' => $missing]);
                }
            }

            $wasPublished = $profile->published;
            $profile->published = $publish;
            if ($publish && ! $wasPublished) {
                $profile->published_at = now();
                $profile->first_published_at ??= now();
            }
            $profile->save();

            if (array_key_exists('categories', $data)) {
                DB::table('webshop_public_categories')->where('webshop_id', $webshop->id)->delete();
                DB::table('webshop_public_categories')->insert(array_map(fn ($c) => ['webshop_id' => $webshop->id, 'category' => $c], array_values(array_unique($categories))));
                DiscoveryCache::flush();
            }

            $this->audit->record('public_profile.updated', category: AuditCategory::Workspace, webshopId: $webshop->id, actorUserId: $actor->user_id,
                metadata: ['fields' => array_keys($data), 'published' => $profile->published]);

            return $profile;
        });
    }

    public function replaceLogo(WebshopMembership $actor, UploadedFile $file): WebshopPublicProfile
    {
        $profile = $this->ensure($actor->webshop);
        $path = 'public-profiles/'.$actor->webshop->public_id.'/'.Str::lower((string) Str::ulid()).'.'.$file->guessExtension();

        if (Storage::disk(self::DISK)->putFileAs(dirname($path), $file, basename($path)) === false) {
            throw new ApiException('The logo could not be stored. Please try again.', 503, 'storage_unavailable');
        }

        $old = $profile->logo_path;
        $profile->forceFill(['logo_path' => $path, 'logo_mime' => $file->getMimeType()])->save();
        if ($old !== null) {
            Storage::disk(self::DISK)->delete($old);
        }

        $this->audit->record('public_profile.logo_replaced', category: AuditCategory::Workspace, webshopId: $actor->webshop_id, actorUserId: $actor->user_id);

        return $profile;
    }

    public function removeLogo(WebshopMembership $actor): WebshopPublicProfile
    {
        $profile = $this->ensure($actor->webshop);
        if ($profile->logo_path !== null) {
            Storage::disk(self::DISK)->delete($profile->logo_path);
            $profile->forceFill(['logo_path' => null, 'logo_mime' => null])->save();
            $this->audit->record('public_profile.logo_removed', category: AuditCategory::Workspace, webshopId: $actor->webshop_id, actorUserId: $actor->user_id);
        }

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Webshop $webshop, ?WebshopPublicProfile $profile): array
    {
        $blockers = array_keys(array_filter([
            'profile_not_published' => ! ($profile->published ?? false),
            'webshop_not_approved' => $webshop->status !== WebshopStatus::Approved,
        ]));

        return [
            'slug' => $profile?->slug,
            'display_name' => $profile->display_name ?? $webshop->name,
            'short_description' => $profile?->short_description,
            'description' => $profile?->description,
            'website_url' => $profile?->website_url,
            'categories' => $profile?->categories() ?? [],
            'has_logo' => $profile?->logo_path !== null,
            'published' => $profile->published ?? false,
            'published_at' => $profile?->published_at?->toIso8601String(),
            'slug_locked' => $profile?->first_published_at !== null,
            'listed' => $blockers === [],
            'listing_blockers' => $blockers,
            'public_url_path' => $profile === null ? null : '/webshops/'.$profile->slug,
        ];
    }

    private function ensure(Webshop $webshop): WebshopPublicProfile
    {
        return WebshopPublicProfile::firstOrCreate(['webshop_id' => $webshop->id], ['display_name' => $webshop->name, 'slug' => $this->uniqueSlug($webshop->name)]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 60, '') ?: 'webshop';

        for ($i = 1; $i < 50; $i++) {
            $slug = $i === 1 ? $base : "{$base}-{$i}";
            if (! WebshopPublicProfile::where('slug', $slug)->exists()) {
                return $slug;
            }
        }

        return $base.'-'.Str::lower(Str::random(6));
    }
}
