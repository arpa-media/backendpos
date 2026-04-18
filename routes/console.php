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

Schedule::command('report-daily-summaries:refresh-dirty --limit=80 --outlet-chunk=4 --date-chunk=2')
    ->everyFiveMinutes()
    ->withoutOverlapping();
