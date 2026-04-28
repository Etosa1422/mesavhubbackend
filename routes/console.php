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
    ->appendOutputTo(storage_path('logs/cron-orders.log'));

Schedule::command('orders:check-refill-status')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/cron-refills.log'));

Schedule::command('orders:check-refills')
    ->everySixHours()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/cron-refill-check.log'));

Schedule::command('provider:sync-orders')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/cron-sync.log'));

Schedule::command('orders:process-refunds')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/cron-refunds.log'));

Schedule::command('notifications:send')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/cron-notifications.log'));

Schedule::command('database:cleanup')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/cron-cleanup.log'));

Schedule::command('tickets:auto-close')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/cron-tickets-close.log'));

Schedule::command('payments:recover-pending --minutes=15')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/cron-payments-recover.log'));
