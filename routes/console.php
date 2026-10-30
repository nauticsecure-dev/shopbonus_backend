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

// Scheduled offers open, and active/paused/scheduled offers close, on their dates.
Schedule::command('shopbonus:advance-offer-lifecycle')->everyMinute()->withoutOverlapping();

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

// B22a: threshold evaluation/retention; telemetry stays opt-in.
Schedule::command('shopbonus:monitor-api')->everyMinute()->withoutOverlapping();

// Durable bounded projection scans; unfinished runs drain before another hourly run.
Schedule::command('shopbonus:reconcile-financials')->everyMinute()->withoutOverlapping();

Schedule::command('shopbonus:observe-operations')->everyMinute()->withoutOverlapping();

Schedule::command('shopbonus:backup')->hourly()->when(fn () => (bool) config('recovery.enabled'))->withoutOverlapping(60);

Schedule::command('shopbonus:verify-latest-backup')->weeklyOn(0, '05:00')->when(fn () => (bool) config('recovery.enabled'))->withoutOverlapping(60);

// Existing operator-requested native recoveries only; never discovers or contacts arbitrary stores.
Schedule::command('shopbonus:recover-integration-order --drain')->everyMinute()->withoutOverlapping();

Schedule::command('shopbonus:sync-integration-orders --drain')->everyMinute()->withoutOverlapping();
Schedule::command('shopbonus:sync-integration-orders --auto')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('shopbonus:sync-affiliates')->hourly()->withoutOverlapping();

Schedule::command('shopbonus:enrich-merchants')->dailyAt('02:30')->when(fn () => (bool) config('enrichment.enabled'))->withoutOverlapping();
