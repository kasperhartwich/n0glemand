<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function scheduledEvent(string $needle): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains($event->command, $needle));
}

it('schedules the join-all-public safety net daily without overlap', function () {
    $event = scheduledEvent('slack:join-all-public');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 3 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->runInBackground)->toBeTrue()
        ->and($event->command)->toContain('--sleep=1');
});

it('schedules the instagram check daily at 09:00 Copenhagen time on one server', function () {
    $event = scheduledEvent('instagram:check');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 9 * * *')
        ->and($event->timezone)->toBe('Europe/Copenhagen')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->command)->toContain('--notify');
});

it('schedules the instagram refresh daily at 03:30 UTC on one server without overlap', function () {
    $event = scheduledEvent('instagram:refresh');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 3 * * *')
        ->and($event->timezone)->toBe('UTC')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->command)->toContain('--if-older-than=72');
});

it('keeps the existing scheduled commands', function () {
    expect(scheduledEvent('horizon:snapshot'))->not->toBeNull()
        ->and(scheduledEvent('slack:prune-ig-downloads'))->not->toBeNull();
});
