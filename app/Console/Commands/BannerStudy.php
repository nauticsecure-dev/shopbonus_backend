<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Models\BannerCampaign;
use App\Models\User;
use App\Services\Banners\ExperimentAnalysis;
use App\Services\Banners\ExperimentMeasurementProof;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class BannerStudy extends Command
{
    protected $signature = 'shopbonus:banner-study {action : fingerprint|register|finalize} {id? : Campaign ULID for register, experiment ULID for finalize} {--file= : Protocol JSON or closing {measurement_evidence_ids:[...]} JSON} {--actor= : Active Super Admin public ID}';

    protected $description = 'Register a reviewed experiment protocol or finalize its one fixed-horizon native-evidence readout';

    public function handle(ExperimentAnalysis $analysis, ExperimentMeasurementProof $proof): int
    {
        $action = (string) $this->argument('action');
        if ($action === 'fingerprint') {
            $this->line($proof->fingerprint());

            return self::SUCCESS;
        }
        $id = strtolower((string) $this->argument('id'));
        $actorId = strtolower((string) $this->option('actor'));
        if (! in_array($action, ['register', 'finalize'], true) || ! Str::isUlid($id) || ! Str::isUlid($actorId)) {
            $this->error('Provide a supported action, public record ID and --actor.');

            return self::FAILURE;
        }
        try {
            $actor = User::where('public_id', $actorId)->firstOrFail();
            $file = (string) $this->option('file');
            if (! is_file($file) || filesize($file) > 65536) {
                throw new \RuntimeException('Invalid protocol/proof file.');
            }
            $input = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($input)) {
                throw new \RuntimeException('Invalid protocol/proof.');
            }
            if ($action === 'register') {
                $campaign = BannerCampaign::where('public_id', $id)->firstOrFail();
                $protocol = $analysis->register($campaign, $input, $actor);
                $this->line(json_encode(['protocol' => $protocol->public_id, 'protocol_hash' => $protocol->protocol_hash], JSON_THROW_ON_ERROR));
            } else {
                $experiment = DB::table('banner_experiments')->where('public_id', $id)->firstOrFail();
                $ids = $input['measurement_evidence_ids'] ?? [];
                if (! is_array($ids) || ! array_is_list($ids) || count($ids) > 50 || collect($ids)->contains(fn ($value) => ! is_string($value) || ! Str::isUlid($value))) {
                    throw new \RuntimeException('Invalid closing evidence.');
                }
                $readout = $analysis->finalize($experiment->id, $actor, $ids);
                $this->line(json_encode(['readout' => $readout->public_id, 'result' => $readout->result], JSON_THROW_ON_ERROR));
            }

            return self::SUCCESS;
        } catch (ApiException $e) {
            $this->error($e->errorCode.': '.$e->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Protocol/proof input or record could not be read.');

            return self::FAILURE;
        }
    }
}
