<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Commands & Task Scheduling
|--------------------------------------------------------------------------
|
| All automated tasks in Flow Hub are registered with the Laravel Scheduler
| below and run exclusively via an external cron-job.org HTTP trigger:
|
|   https://bottomfunnel.app/cron/run?token=hgytrsd543T4564FGHf7tgfs635GGf
|
| Do NOT configure native server crontab entries.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 1. Weekly Watching Products Full Refresh (Monday morning)
Schedule::command('watching:refresh')
    ->weeklyOn(1, '06:00')
    ->timezone('America/Toronto')
    ->withoutOverlapping();

// 1b. Weekly Watching Products Keyword Planner Search Volume Refresh (Monday morning)
Schedule::command('watching:refresh-volume')
    ->weeklyOn(1, '06:30')
    ->timezone('America/Toronto')
    ->withoutOverlapping()
    ->onOneServer();

// 2. Daily Foreign Exchange Rate Sync (Bank of Canada CAD pairs)
Schedule::command('fx:refresh')
    ->dailyAt('05:00')
    ->timezone('America/Toronto')
    ->withoutOverlapping();

// 3. Safety: Listing Down Check & Alert (every 15 minutes, consecutive failure confirmation)
Schedule::command('campaigns:check-listing-down')
    ->everyFifteenMinutes()
    ->timezone('America/Toronto')
    ->withoutOverlapping(10);

// 4. Safety: Spend-without-conversion Bleeder Check & Alert (every 30 minutes)
Schedule::command('campaigns:check-bleeders')
    ->everyThirtyMinutes()
    ->timezone('America/Toronto')
    ->withoutOverlapping(15);

// 5. Shared Hosting Queue Processing (processes pending async jobs whenever cron fires)
Schedule::command('queue:work', ['--stop-when-empty', '--max-time=50'])
    ->everyMinute()
    ->withoutOverlapping();
