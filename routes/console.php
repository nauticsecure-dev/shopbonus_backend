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

// Answer24 one-hour grouping windows.
Schedule::command('shopbonus:close-contact-windows')->everyMinute()->withoutOverlapping();

// Answer24 outbox delivery (no-op while ANSWER24_DISPATCH_ENABLED=false).
Schedule::command('shopbonus:dispatch-outbox')->everyMinute()->withoutOverlapping();

// Wallet: credit expiry and reservation timeouts; nightly ledger proof.
Schedule::command('shopbonus:wallet-expire')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('shopbonus:wallet-reconcile')->dailyAt('04:00')->withoutOverlapping();

// Voucher checkouts: late Mollie payments and abandoned reservations.
Schedule::command('shopbonus:expire-checkouts')->everyFiveMinutes()->withoutOverlapping();

// Merchant funding statements (drafts; Moneybird not connected).
Schedule::command('shopbonus:prepare-invoices')->monthlyOn(1, '05:00')->withoutOverlapping();

// Merchant notifications (Trello #8 §6).
Schedule::command('shopbonus:merchant-notifications')->everyFifteenMinutes()->withoutOverlapping();

// Banner campaigns scheduled in shop-local time.
Schedule::command('shopbonus:publish-scheduled-banners')->everyMinute()->withoutOverlapping();

// Private support history retention and recovery of stopped/timed-out research.
Schedule::command('shopbonus:prune-support')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('shopbonus:dispatch-notifications')->everyMinute()->withoutOverlapping();
