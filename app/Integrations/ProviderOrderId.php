<?php

namespace App\Integrations;

use App\Enums\EcommercePlatform;
use App\Integrations\Http\WooCommerceApi;

final class ProviderOrderId
{
    public static function validate(EcommercePlatform $platform, string $id): string
    {
        $pattern = match ($platform) {
            EcommercePlatform::Ecwid => '/\A[1-9][0-9]{0,18}\z/D',
            EcommercePlatform::Wix => '/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/D',
            EcommercePlatform::Shopware6 => '/\A[a-f0-9]{32}\z/D',
            default => null,
        };
        if ($pattern === null) {
            return WooCommerceApi::orderId($id);
        }
        if (! preg_match($pattern, $id)) {
            throw new \InvalidArgumentException('Invalid provider order ID.');
        }

        return $id;
    }
}
