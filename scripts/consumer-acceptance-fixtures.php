<?php

// Invoked by the sibling frontend's isolated local acceptance launcher only.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\WalletUnit;
use App\Models\Offer;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Services\Offers\OfferService;
use App\Services\Wallet\WalletService;
use Illuminate\Contracts\Console\Kernel;

try {
    $expected = realpath(dirname(__DIR__).'/../shopbonus_frontend/.consumer-acceptance/consumer.sqlite');
    if (! $expected || realpath(getenv('CONSUMER_ACCEPTANCE_DATABASE') ?: '') !== $expected) {
        throw new RuntimeException('Dedicated acceptance database is required.');
    }
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('local') || $app->configurationIsCached()
        || config('database.default') !== 'sqlite'
        || realpath(config('database.connections.sqlite.database')) !== $expected) {
        throw new RuntimeException('Refusing to seed outside the isolated local environment.');
    }
    $wallets = app(WalletService::class);
    $consumer = User::where('email', 'consumer@shopbonus.local')->sole();
    $points = $wallets->consumerWallet($consumer, WalletUnit::Points);
    $wallets->credit($points, 1000, 'consumer-acceptance:points', neverExpires: true);
    $wallets->addPending($points, 80, 'consumer-acceptance:pending', ['description' => 'Local pending reward fixture']);
    $wallets->credit($wallets->consumerWallet($consumer), 1220, 'consumer-acceptance:eur', neverExpires: true);
    $shop = Webshop::whereHas('publicProfile', fn ($query) => $query->where('slug', 'woon-en-co-demo'))->sole();
    $offers = app(OfferService::class);
    $offer = Offer::where('webshop_id', $shop->id)->where('name', 'Consumer acceptance points voucher')->first();
    if (! $offer) {
        $offer = $offers->transition($offers->create($shop->memberships()->sole(), OfferAcquisition::WalletPurchase, [
            'name' => 'Consumer acceptance points voucher', 'discount_type' => 'fixed', 'discount_value' => 1000,
            'price_points' => 250, 'redemption_validity_days' => 30,
        ]), OfferStatus::Active);
        for ($i = 1; $i <= 20; $i++) {
            $code = 'LOCAL-POINTS-'.$i;
            VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $offer->id, 'code' => $code,
                'code_hash' => VoucherCode::hashFor($shop->id, $code), 'code_last4' => substr($code, -4)]);
        }
    }
    file_put_contents(dirname($expected).'/fixtures.json', json_encode(['points_offer_slug' => $offer->slug], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Consumer points fixtures ready; existing balances and purchases preserved.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Acceptance fixtures failed: '.$error->getMessage()."\n");
    exit(1);
}
