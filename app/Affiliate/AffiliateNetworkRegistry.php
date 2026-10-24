<?php

namespace App\Affiliate;

use App\Affiliate\Adapters\DaisyconAdapter;
use App\Affiliate\Adapters\TradeTrackerAdapter;
use App\Exceptions\ApiException;

class AffiliateNetworkRegistry
{
    public const NETWORKS = ['daisycon' => DaisyconAdapter::class, 'tradetracker' => TradeTrackerAdapter::class];

    public function for(string $network): AffiliateNetworkAdapter
    {
        $class = self::NETWORKS[$network] ?? throw new ApiException('Unknown affiliate network.', 422, 'unknown_network');

        return app($class);
    }
}
