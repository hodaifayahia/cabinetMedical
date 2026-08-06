<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('medismart:uploads:reconcile')
    ->hourly()
    ->withoutOverlapping(10);

Schedule::command('medismart:license:refresh')
    ->everySixHours()
    ->withoutOverlapping(20);

Schedule::command('medismart:backup:scheduled')
    ->everyMinute()
    ->withoutOverlapping(60);

Schedule::command('medismart:backup:drive-retention')
    ->dailyAt('04:15')
    ->withoutOverlapping(60);

Schedule::command('medismart:oauth-attempts:prune')
    ->hourly()
    ->withoutOverlapping(10);

Schedule::command('medismart:restore:prune-preparations')
    ->dailyAt('03:30')
    ->withoutOverlapping(60);
