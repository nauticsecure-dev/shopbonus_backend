<?php

namespace App\Console\Commands;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\WebshopConnection;
use App\Services\Banners\EcwidAppPublisher;
use App\Services\Banners\LightspeedScriptPublisher;
use App\Services\Banners\NativeBannerPublisher;
use App\Services\Banners\SquarespaceInstallationVerifier;
use App\Support\ExactJson;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class NativeBanner extends Command
{
    protected $signature = 'shopbonus:banner-native {action : readiness|install|upgrade|reconcile|bind|remove} {id : Platform for readiness; connection public ID otherwise} {--actor= : Current authorized public user ID} {--file= : Reviewed JSON target for install} {--step= : template|widget|placement for bind (BigCommerce)} {--remote-id= : Native UUID (BigCommerce) or shop script ID (Lightspeed C) for bind}';

    protected $description = 'Explicit native banner provisioning, reconciliation and removal; no blind write retries';

    public function handle(NativeBannerPublisher $publisher): int
    {
        $action = (string) $this->argument('action');
        $id = strtolower((string) $this->argument('id'));
        if ($action === 'readiness') {
            $platform = EcommercePlatform::tryFrom($id);
            if ($platform === null) {
                $this->error('Unknown platform.');

                return self::FAILURE;
            }
            $this->line(json_encode($publisher->readiness($platform), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $actorId = strtolower((string) $this->option('actor'));
        if (! in_array($action, ['install', 'upgrade', 'reconcile', 'bind', 'remove'], true) || ! Str::isUlid($id) || ! Str::isUlid($actorId)) {
            $this->error('Provide a supported action, connection public ID and --actor public ID.');

            return self::FAILURE;
        }
        $connection = WebshopConnection::where('public_id', $id)->first();
        $actor = User::where('public_id', $actorId)->first();
        if ($connection === null || $actor === null) {
            $this->error('Connection or actor not found.');

            return self::FAILURE;
        }
        try {
            if (in_array($connection->platform, [EcommercePlatform::Ecwid, EcommercePlatform::LightspeedE, EcommercePlatform::Squarespace], true)) {
                // Ecwid app config / Squarespace verified manual snippet: no target file, nothing to bind or upgrade.
                $service = $connection->platform === EcommercePlatform::Squarespace ? app(SquarespaceInstallationVerifier::class) : app(EcwidAppPublisher::class);
                $result = match ($action) {
                    'install' => $service->install($connection, $actor),
                    'reconcile' => $service->reconcile($connection, $actor),
                    'remove' => $service->remove($connection, $actor),
                    default => throw new \InvalidArgumentException,
                };
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }
            if ($connection->platform === EcommercePlatform::LightspeedC && $action === 'upgrade') {
                throw new \InvalidArgumentException('Legacy cart upgrade applies to BigCommerce only.');
            }
            if ($connection->platform === EcommercePlatform::LightspeedC) {
                // One owned shop script; no target file, bind takes the numeric script ID.
                $lightspeed = app(LightspeedScriptPublisher::class);
                $remoteId = (string) $this->option('remote-id');
                $result = match ($action) {
                    'install' => $lightspeed->install($connection, $actor),
                    'reconcile' => $lightspeed->reconcile($connection, $actor),
                    'bind' => preg_match('/\A[1-9][0-9]{0,18}\z/D', $remoteId) === 1 ? $lightspeed->bind($connection, (int) $remoteId, $actor) : throw new \InvalidArgumentException,
                    'remove' => $lightspeed->remove($connection, $actor),
                };
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            $target = [];
            if ($action === 'install') {
                $file = (string) $this->option('file');
                if (! is_file($file) || ! is_readable($file)) {
                    throw new \InvalidArgumentException('Provide a readable local JSON target file.');
                }
                $bytes = file_get_contents($file, false, null, 0, 65537);
                if ($bytes === false || strlen($bytes) > 65536) {
                    throw new \InvalidArgumentException('Target file must be at most 64 KiB.');
                }
                $target = ExactJson::decode($bytes);
                if (! is_array($target) || array_is_list($target)) {
                    throw new \InvalidArgumentException('Target must be a JSON object.');
                }
            }
            $result = match ($action) {
                'install' => $publisher->install($connection, $target, $actor),
                'upgrade' => $publisher->upgradeCart($connection, $actor),
                'reconcile' => $publisher->reconcile($connection, $actor),
                'bind' => $publisher->bind($connection, (string) $this->option('step'), (string) $this->option('remote-id'), $actor),
                'remove' => $publisher->remove($connection, $actor),
            };
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (ApiException $e) {
            $this->error($e->errorCode.': '.$e->getMessage());

            return self::FAILURE;
        } catch (\InvalidArgumentException|\JsonException) {
            $this->error('Invalid native publication arguments or target JSON.');

            return self::FAILURE;
        }
    }
}
