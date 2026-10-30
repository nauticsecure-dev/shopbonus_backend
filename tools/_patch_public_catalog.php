<?php
$f = __DIR__ . "/../app/Services/Discovery/PublicCatalog.php";
$c = file_get_contents($f);
$c = str_replace(
    "use App\Models\MerchantCommissionAgreement;",
    "use App\Models\AffiliateRewardRule;\nuse App\Models\MerchantCommissionAgreement;",
    $c
);
$c = str_replace(
    "use App\Models\WebshopPublicProfile;",
    "use App\Models\Webshop;\nuse App\Models\WebshopPublicProfile;\nuse App\Services\Affiliate\ProgramSelector;\nuse Illuminate\Support\Collection;",
    $c
);
$c = str_replace(
    "class PublicCatalog\n{",
    "class PublicCatalog\n{\n    public function __construct(private readonly ProgramSelector \$programSelector) {}\n",
    $c
);
file_put_contents($f, $c);
echo "ok\n";
