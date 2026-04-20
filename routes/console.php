<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule all cron jobs
Schedule::command('orders:check-status')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo('/dev/stdout');

Schedule::command('orders:check-refill-status')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->appendOutputTo('/dev/stdout');

Schedule::command('orders:check-refills')
    ->everySixHours()
    ->withoutOverlapping()
    ->appendOutputTo('/dev/stdout');

Schedule::command('provider:sync-orders')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->appendOutputTo('/dev/stdout');

Schedule::command('orders:process-refunds')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo('/dev/stdout');

Schedule::command('notifications:send')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo('/dev/stdout');

Schedule::command('database:cleanup')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->appendOutputTo('/dev/stdout');

Schedule::command('tickets:auto-close')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->appendOutputTo('/dev/stdout');
