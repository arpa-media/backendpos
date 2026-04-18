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
