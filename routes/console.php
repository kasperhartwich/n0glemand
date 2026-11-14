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
// Queues a login while the cookies are over 3 days old, so a reel rarely waits on one. It runs
// after the 09:00 check, when the admins are awake to answer the code request (the login waits
// 10 minutes for it).
Schedule::command('instagram:refresh --if-older-than=72')
    ->dailyAt('09:30')
    ->timezone('Europe/Copenhagen')
    ->onOneServer()
    ->withoutOverlapping(10);
Schedule::command('instagram:check --notify')
    ->dailyAt('09:00')
    ->timezone('Europe/Copenhagen')
    ->onOneServer()
    ->withoutOverlapping(10);
