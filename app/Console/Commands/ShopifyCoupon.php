<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Models\Offer;
use App\Models\ShopifyCouponOperation;
use App\Models\User;
use App\Models\WebshopConnection;
use App\Services\Vouchers\ShopifyCouponProvisioning;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Str;

class ShopifyCoupon extends Command
{
    protected $signature = 'shopbonus:shopify-coupon {action : enable|disable|prepare|create|reconcile|repair|import|deactivate} {id : Offer public ID for enable/disable/prepare, operation public ID otherwise} {--connection= : Shopify connection public ID for enable/disable/prepare} {--actor= : Current authorized merchant public user ID} {--request-key= : Stable unique prepare key, 8–100 letters/digits/underscore/hyphen}';

    protected $description = 'Opt-in Shopify single-use discount provisioning; encrypted intents and explicit readback/import';

    public function handle(ShopifyCouponProvisioning $service): int
    {
        $action = (string) $this->argument('action');
        $id = strtolower((string) $this->argument('id'));
        $actorId = strtolower((string) $this->option('actor'));
        if (! Str::isUlid($id) || ! Str::isUlid($actorId)) {
            $this->error('Provide public resource and actor IDs.');

            return self::FAILURE;
        }
        $actor = User::where('public_id', $actorId)->first();
        if ($actor === null) {
            $this->error('Actor not found.');

            return self::FAILURE;
        }
        try {
            if (in_array($action, ['enable', 'disable', 'prepare'], true)) {
                $connection = WebshopConnection::where('public_id', strtolower((string) $this->option('connection')))->first();
                $offer = Offer::where('public_id', $id)->first();
                if ($connection === null || $offer === null) {
                    throw new \InvalidArgumentException('Offer or connection not found.');
                }
                if ($action !== 'prepare') {
                    $service->configure($offer, $connection, $actor, $action === 'enable');
                    $this->line(json_encode(['offer' => $id, 'enabled' => $action === 'enable'], JSON_THROW_ON_ERROR));

                    return self::SUCCESS;
                }
                $operation = $service->prepare($offer, $connection, $actor, (string) $this->option('request-key'));
            } else {
                $operation = ShopifyCouponOperation::where('public_id', $id)->first();
                if ($operation === null) {
                    throw new \InvalidArgumentException('Operation not found.');
                }
                $operation = $service->execute($operation, $actor, $action);
            }
            // Never print the code, input, credentials or raw provider response.
            $this->line(json_encode(['operation' => $operation->public_id, 'state' => $operation->state, 'remote_id' => $operation->remote_id,
                'inventory_imported' => $operation->voucher_code_id !== null], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (ApiException $e) {
            $this->error($e->errorCode.': '.$e->getMessage());
        } catch (LockTimeoutException) {
            $this->error('Another coupon operation is running for this connection.');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
        }

        return self::FAILURE;
    }
}
