<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('slack:prune-ig-downloads')->hourly();
Schedule::command('slack:join-all-public --sleep=1')
    ->dailyAt('03:00')
    ->withoutOverlapping(120)
    ->runInBackground();
Schedule::command('instagram:check --notify')
    ->dailyAt('08:00')
    ->timezone('Europe/Copenhagen')
    ->onOneServer()
    ->withoutOverlapping(10);
