<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('watching:refresh')
    ->weeklyOn(1, '06:00')
    ->timezone('America/Toronto')
    ->withoutOverlapping();

Schedule::command('fx:refresh')
    ->dailyAt('05:00')
    ->timezone('America/Toronto');

Schedule::command('campaigns:check-listing-down')
    ->everyThirtyMinutes()
    ->timezone('America/Toronto')
    ->withoutOverlapping();

Schedule::command('campaigns:check-bleeders')
    ->hourly()
    ->timezone('America/Toronto')
    ->withoutOverlapping();
