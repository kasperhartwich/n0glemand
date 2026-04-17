<?php

use App\Http\Controllers\Slack\CommandsController;
use App\Http\Controllers\Slack\EventsController;
use App\Http\Middleware\VerifySlackSignature;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/slack/events', EventsController::class)
    ->middleware(VerifySlackSignature::class)
    ->name('slack.events');

Route::post('/slack/commands', CommandsController::class)
    ->middleware(VerifySlackSignature::class)
    ->name('slack.commands');
