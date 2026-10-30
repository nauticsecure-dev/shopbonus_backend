<?php
$path = dirname(__DIR__) . '/tests/Feature/Affiliate/AffiliateCashbackOffersE2ETest.php';
$lines = file($path);
file_put_contents($path, implode('', array_slice($lines, 0, 175)) . "}\n");
