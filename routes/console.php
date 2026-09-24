<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('report-sale-scope-cache:prune')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('report-sale-business-dates:sync-recent --days=45')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('report-sale-scopes:warm-common --days=30 --per-outlet=1')
    ->hourly()
    ->withoutOverlapping();

// ERP POS Console I03: Daily/Hourly/Monthly reporting schedules are registered
// only once via ReportingScheduleRegistry in bootstrap/app.php.

// HR ITERATION 10: expire announcements and purge private attachment binaries.
Schedule::command('hr:announcement-expiry-sweep --limit=200')
    ->everyTenMinutes()
    ->withoutOverlapping();

// HR ITERATION 11: effective-dated Contract/SK lifecycle and expiry reminders.
Schedule::command('hr:contract-lifecycle-sweep --limit=300')
    ->dailyAt('00:10')
    ->withoutOverlapping();

// ERP FINANCE V7 I01: submitted Stock Request fallback approval on the next business day at 06:00 WIB.
Schedule::command('warehouse:stock-request-auto-approve')
    ->dailyAt('06:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();
