<?php

// Run from any directory. Only reviewed source files enter archives; never package a merchant installation.
$root = dirname(__DIR__);
$source = $root.'/merchant-packages';
$output = $root.'/public/downloads/integrations';
if (! is_dir($output)) {
    mkdir($output, 0755, true);
}
$packages = [
    'shopbonus-adobe-appbuilder-source.zip' => ['directory' => 'adobe-appbuilder'],
    'shopbonus-magento2-async.zip' => ['directory' => 'magento2', 'delivery' => 'Shared/Delivery.php'],
    'shopbonus-prestashop9.zip' => ['presta' => true, 'directory' => 'prestashop', 'delivery' => 'shopbonus/Delivery.php'],
    'shopbonus.ocmod.zip' => ['directory' => 'opencart4', 'delivery' => 'system/library/Delivery.php'],
    'shopbonus-woocommerce-banner.zip' => ['directory' => 'woocommerce'],
    'shopbonus-shopify-theme-source.zip' => ['directory' => 'shopify'],
];
$manifest = [];
foreach ($packages as $name => $package) {
    $zip = new ZipArchive;
    if ($zip->open($output.'/'.$name, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create package.');
    }
    $base = $source.'/'.$package['directory'];
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $files[str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1))] = $file->getPathname();
        }
    }
    if (isset($package['delivery'])) {
        $files[$package['delivery']] = $source.'/common/Delivery.php';
    }
    $files['INSTALL.md'] = $source.'/INSTALL.md';
    ksort($files);
    foreach ($files as $path => $file) {
        $zip->addFile($file, $path);
        $zip->setMtimeName($path, 1793664000);
    }
    $zip->close();
    $manifest[$name] = ['sha256' => hash_file('sha256', $output.'/'.$name), 'bytes' => filesize($output.'/'.$name), 'live_verified' => false];
}
file_put_contents($output.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
