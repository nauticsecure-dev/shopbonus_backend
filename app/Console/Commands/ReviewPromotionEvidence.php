<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Models\AffiliateProgram;
use App\Models\AffiliatePromotionReview;
use App\Models\Offer;
use App\Models\User;
use App\Services\Affiliate\PromotionEvidenceReview;
use App\Support\ExactJson;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ReviewPromotionEvidence extends Command
{
    protected $signature = 'shopbonus:promotion-evidence {action : propose|show|approve|reject} {id : Offer public ID for propose; review public ID otherwise} {--actor=} {--program=} {--file= : Local JSON source mapping} {--note=}';

    protected $description = 'Normalize and explicitly review stored Daisycon conditions without granting permission';

    public function handle(PromotionEvidenceReview $service): int
    {
        try {
            $actor = User::where('public_id', $this->option('actor'))->firstOrFail();
            $service->authorize($actor);
            $action = (string) $this->argument('action');
            if ($action === 'propose') {
                $file = (string) $this->option('file');
                if (! is_file($file) || ! is_readable($file)) {
                    throw new \InvalidArgumentException;
                }
                $bytes = file_get_contents($file, false, null, 0, 16385);
                if ($bytes === false || strlen($bytes) > 16384) {
                    throw new \InvalidArgumentException;
                }
                $mapping = ExactJson::decode($bytes);
                if (! is_array($mapping) || array_is_list($mapping)) {
                    throw new \InvalidArgumentException;
                }
                $r = $service->propose(Offer::where('public_id', $this->argument('id'))->firstOrFail(), AffiliateProgram::where('public_id', $this->option('program'))->firstOrFail(), $mapping, $actor);
            } else {
                $r = AffiliatePromotionReview::where('public_id', $this->argument('id'))->firstOrFail();
                if ($action !== 'show') {
                    if (! in_array($action, ['approve', 'reject'], true)) {
                        throw new \InvalidArgumentException;
                    }
                    $r = $service->review($r, $actor, $action === 'approve', (string) $this->option('note'));
                }
            }
            $this->line(json_encode(['id' => $r->public_id, 'status' => $r->status, 'normalized' => $r->normalized, 'evidence' => $r->evidence, 'permission_granted' => false], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (ApiException $e) {
            $this->error($e->errorCode.': '.$e->getMessage());
        } catch (ModelNotFoundException|\InvalidArgumentException|\JsonException) {
            $this->error('Invalid actor, record or local mapping file.');
        }

        return self::FAILURE;
    }
}
