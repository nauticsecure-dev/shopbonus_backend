<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Trello #1: raw webhook payloads are not kept indefinitely.
Schedule::command('shopbonus:prune-webhook-payloads')->dailyAt('03:15')->withoutOverlapping();

// Voucher claim windows (90-day Answer24 rule) and redemption validity.
Schedule::command('shopbonus:expire-vouchers')->hourly()->withoutOverlapping();
