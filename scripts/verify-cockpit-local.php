<?php

use App\Models\User;
use App\Models\Voucher;
use App\Models\Wallet;
use App\Models\Webshop;
use App\Services\Admin\Cockpit;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array']);
$now = CarbonImmutable::now(config('shopbonus.business_timezone'));
$summary = app(Cockpit::class)->summary($now->subDays(29)->startOfDay()->utc(), $now->endOfDay()->utc());
$expected = [
    'active_webshops' => Webshop::where('status', 'approved')->count(),
    'consumers' => User::consumerAccounts()->where('status', 'active')->count(),
    'active_vouchers' => Voucher::usable()->count(),
    'wallet_liability' => (int) Wallet::where('kind', 'consumer_credit')->where('unit', 'eur_credit')->sum(DB::raw('available + reserved')),
];
$actual = collect($summary['cards'])->pluck('value', 'key')->all();
foreach ($expected as $key => $value) {
    if ($actual[$key] !== $value) {
        throw new RuntimeException('Mismatch: '.$key);
    }
}
$evidence = ['verified_at' => now()->toIso8601String(), 'environment' => 'Existing local MySQL shopbonus at 127.0.0.1; read-only aggregates after selective task migration',
    'expected' => $expected, 'actual' => $actual, 'warnings' => $summary['warnings'], 'sources' => $summary['sources'],
    'affiliate_channels' => $summary['affiliate_channels'], 'open_actions' => $summary['attention']['open_actions']];
file_put_contents(__DIR__.'/../docs/api/COCKPIT_LOCAL_MYSQL.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
echo json_encode($evidence);
