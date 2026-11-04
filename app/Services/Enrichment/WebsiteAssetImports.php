<?php

namespace App\Services\Enrichment;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\BannerAsset;
use App\Models\MerchantAssetImport;
use App\Models\MerchantEnrichmentRun;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class WebsiteAssetImports
{
    public function __construct(private readonly SafeUrlFetcher $fetcher, private readonly WebsiteResearcher $researcher, private readonly AuditLogger $audit) {}

    public function authorize(User $actor): void
    {
        $current = User::find($actor->id);
        if (! $current?->isActive() || ! $current->isSuperAdmin()) {
            throw new ApiException('Current Super Admin authority is required.', 403, 'forbidden');
        }
    }

    public function preview(MerchantAssetImport $import, User $actor): string
    {
        $this->authorize($actor);
        $r = MerchantAssetImport::findOrFail($import->id);
        try {
            $bytes = Storage::disk('local')->get($r->path);
        } catch (Throwable) {
            throw new ApiException('Private image storage is unavailable.', 503, 'asset_storage_unavailable');
        }
        if (! is_string($bytes) || strlen($bytes) > 2097152 || ! hash_equals($r->content_hash, hash('sha256', $bytes))) {
            throw ApiException::conflict('Private asset content is missing or changed.', 'asset_integrity_failed');
        }

        return $bytes;
    }

    public function import(MerchantEnrichmentRun $run, string $key, User $actor): MerchantAssetImport
    {
        $this->authorize($actor);
        $run = MerchantEnrichmentRun::with('webshop')->findOrFail($run->id);
        $url = $this->source($run, $key);
        $existing = MerchantAssetImport::where('run_id', $run->id)->where('evidence_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }
        $binary = $this->fetcher->fetchImage($url);
        if ($binary === null) {
            throw new ApiException('The candidate image could not be safely downloaded.', 422, 'asset_download_refused');
        }
        $info = @getimagesizefromstring($binary);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] < 16 || $info[1] < 16 || $info[0] > 4000 || $info[1] > 4000 || $info[0] * $info[1] > 12_000_000) {
            throw new ApiException('The candidate is not a supported bounded raster image.', 422, 'asset_image_invalid');
        }
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            throw new ApiException('Image codec is unavailable.', 503, 'asset_codec_unavailable');
        }
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            throw new ApiException('The image cannot be decoded.', 422, 'asset_image_invalid');
        }
        ob_start();
        try {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $ok = imagewebp($image, null, 82);
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        if (! $ok || ! is_string($bytes) || $bytes === '' || strlen($bytes) > 2097152 || @getimagesizefromstring($bytes) === false) {
            throw new ApiException('The normalized image is invalid.', 422, 'asset_image_invalid');
        }
        $path = 'merchant-imports/'.$run->webshop->public_id.'/'.Str::lower((string) Str::ulid()).'.webp';
        $retained = false;
        try {
            if (! Storage::disk('local')->put($path, $bytes)) {
                throw new ApiException('Private image storage is unavailable.', 503, 'asset_storage_unavailable');
            }
            $result = DB::transaction(function () use ($run, $key, $url, $actor, $path, $bytes, $info) {
                $this->authorize($actor);
                $locked = MerchantEnrichmentRun::with('webshop')->whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($this->source($locked, $key) !== $url || $locked->research_hash !== $run->research_hash) {
                    throw ApiException::conflict('Candidate source changed.', 'asset_source_changed');
                }
                $existing = MerchantAssetImport::where('run_id', $run->id)->where('evidence_key', $key)->first();
                if ($existing !== null) {
                    return $existing;
                }
                $r = MerchantAssetImport::create(['run_id' => $run->id, 'evidence_key' => $key, 'webshop_id' => $run->webshop_id, 'created_by' => $actor->id, 'source_url' => $url, 'research_hash' => $run->research_hash, 'content_hash' => hash('sha256', $bytes), 'path' => $path, 'width' => $info[0], 'height' => $info[1], 'status' => 'pending']);
                $this->audit->record('enrichment.asset_imported', category: AuditCategory::Admin, resource: $r, actorUserId: $actor->id, webshopId: $run->webshop_id, metadata: ['research_hash' => $run->research_hash, 'content_hash' => $r->content_hash]);

                return $r;
            });
            $retained = $result->path === $path;

            return $result;
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable) {
            throw new ApiException('Private image import could not be persisted.', 503, 'asset_storage_unavailable');
        } finally {
            if (! $retained) {
                try {
                    Storage::disk('local')->delete($path);
                } catch (Throwable $cleanup) {
                    report($cleanup);
                }
            }
        }
    }

    public function review(MerchantAssetImport $import, User $actor, bool $approve, string $note, ?string $rights): MerchantAssetImport
    {
        $this->authorize($actor);
        if (trim($note) === '' || mb_strlen($note) > 2000 || ($approve && (trim((string) $rights) === '' || mb_strlen((string) $rights) > 2000))) {
            throw new ApiException('A review note and approval usage-rights reference are required.', 422, 'asset_review_invalid');
        }

        return DB::transaction(function () use ($import, $actor, $approve, $note, $rights) {
            $this->authorize($actor);
            $r = MerchantAssetImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'pending') {
                if ($r->status === ($approve ? 'approved' : 'rejected')) {
                    return $r;
                }
                throw ApiException::conflict('This import already has a final decision.', 'asset_review_completed');
            }
            $assetId = null;
            if ($approve) {
                $run = MerchantEnrichmentRun::with('webshop')->whereKey($r->run_id)->lockForUpdate()->firstOrFail();
                if ($run->webshop_id !== $r->webshop_id || $run->research_hash !== $r->research_hash || $this->source($run, $r->evidence_key) !== $r->source_url) {
                    throw ApiException::conflict('The merchant evidence changed.', 'asset_source_changed');
                }
                $this->preview($r, $actor);
                $asset = BannerAsset::create(['webshop_id' => $r->webshop_id, 'path' => $r->path, 'mime' => 'image/webp', 'width' => $r->width, 'height' => $r->height, 'uploaded_by' => $actor->id]);
                $assetId = $asset->id;
            }
            $r->forceFill(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $actor->id, 'review_note' => trim($note), 'rights_reference' => $approve ? trim((string) $rights) : null, 'banner_asset_id' => $assetId])->save();
            $this->audit->record('enrichment.asset_'.$r->status, category: AuditCategory::Admin, resource: $r, actorUserId: $actor->id, webshopId: $r->webshop_id, metadata: ['asset_id' => $assetId]);

            return $r;
        });
    }

    private function source(MerchantEnrichmentRun $run, string $key): string
    {
        if (! $run->webshop->isOwnershipVerified() || $run->created_at->lt(now()->subDays(30)) || $run->source_url !== 'https://'.$run->webshop->verified_domain.'/'
            || (! hash_equals($run->research_hash, $this->researcher->hash($run->research)) && ! hash_equals($run->research_hash, $this->researcher->legacyHash($run->research)))) {
            throw ApiException::conflict('Current verified merchant research is required.', 'asset_source_changed');
        }
        $candidate = $run->research['evidence'][$key] ?? null;
        $url = is_array($candidate) ? ($candidate['value'] ?? null) : null;
        if (! is_array($candidate) || ! in_array($candidate['fact'] ?? null, ['image_candidate', 'logo_candidate'], true) || ! is_string($url) || strlen($url) > 2048 || parse_url($url, PHP_URL_HOST) !== $run->webshop->verified_domain) {
            throw new ApiException('Select an existing same-origin research image candidate.', 422, 'asset_candidate_invalid');
        }

        return $url;
    }
}
