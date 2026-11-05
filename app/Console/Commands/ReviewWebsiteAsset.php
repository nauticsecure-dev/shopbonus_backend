<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Models\BannerAsset;
use App\Models\MerchantAssetImport;
use App\Models\MerchantEnrichmentRun;
use App\Models\User;
use App\Services\Enrichment\WebsiteAssetImports;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ReviewWebsiteAsset extends Command
{
    protected $signature = 'shopbonus:website-asset {action : import|show|approve|reject} {id : Enrichment run public ID for import; import public ID otherwise} {--actor=} {--evidence= : Existing research evidence key} {--note=} {--rights= : Explicit usage-rights approval reference} {--preview-file= : New local file for private normalized preview}';

    protected $description = 'Import and review website image candidates before reuse as merchant banner assets';

    public function handle(WebsiteAssetImports $service): int
    {
        try {
            $actor = User::where('public_id', $this->option('actor'))->firstOrFail();
            $service->authorize($actor);
            $action = (string) $this->argument('action');
            if ($action === 'import') {
                $r = $service->import(MerchantEnrichmentRun::where('public_id', $this->argument('id'))->firstOrFail(), (string) $this->option('evidence'), $actor);
            } else {
                $r = MerchantAssetImport::where('public_id', $this->argument('id'))->firstOrFail();
                if ($action === 'show' && $this->option('preview-file')) {
                    $bytes = $service->preview($r, $actor);
                    $file = (string) $this->option('preview-file');
                    $handle = @fopen($file, 'xb');
                    if ($handle === false) {
                        throw new \InvalidArgumentException;
                    }
                    try {
                        chmod($file, 0600);
                        if (fwrite($handle, $bytes) !== strlen($bytes)) {
                            throw new \InvalidArgumentException;
                        }
                    } finally {
                        fclose($handle);
                    }
                } elseif ($action !== 'show') {
                    if (! in_array($action, ['approve', 'reject'], true)) {
                        throw new \InvalidArgumentException;
                    }
                    $r = $service->review($r, $actor, $action === 'approve', (string) $this->option('note'), $this->option('rights'));
                }
            }
            $this->line(json_encode(['id' => $r->public_id, 'status' => $r->status, 'source_url' => $r->source_url, 'content_hash' => $r->content_hash, 'width' => $r->width, 'height' => $r->height, 'asset_id' => $r->banner_asset_id === null ? null : BannerAsset::findOrFail($r->banner_asset_id)->public_id], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (ApiException $e) {
            $this->error($e->errorCode.': '.$e->getMessage());
        } catch (ModelNotFoundException|\InvalidArgumentException) {
            $this->error('Invalid actor, record, action or preview path.');
        }

        return self::FAILURE;
    }
}
