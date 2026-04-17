<?php

use App\Jobs\Slack\PostBangCommandReply;
use App\Services\Slack\Handlers\BangCommandHandler;
use Illuminate\Support\Facades\Queue;

it('matches supported subcommands and ignores others', function (string $text, bool $expected) {
    $handler = app(BangCommandHandler::class);

    expect($handler->matches(['text' => $text]))->toBe($expected);
})->with([
    'ping' => ['!n0g ping', true],
    'ping with extra' => ['  !n0g ping please', true],
    'plain prefix' => ['!n0g', true],
    'help' => ['!n0g help', true],
    'unknown subcommand' => ['!n0g foo', false],
    'wrong prefix' => ['hey n0g', false],
    'empty' => ['', false],
]);

it('dispatches a reply job with pong for ping', function () {
    Queue::fake();

    app(BangCommandHandler::class)->handle(
        ['text' => '!n0g ping'],
        channel: 'C42',
        threadTs: '1700000000.000100',
        userId: 'U1',
    );

    Queue::assertPushed(PostBangCommandReply::class, fn ($job) => $job->channel === 'C42'
        && $job->threadTs === '1700000000.000100'
        && str_contains($job->text, 'pong'),
    );
});

it('dispatches a help reply for plain prefix', function () {
    Queue::fake();

    app(BangCommandHandler::class)->handle(
        ['text' => '!n0g'],
        channel: 'C1',
        threadTs: '1.2',
        userId: 'U1',
    );

    Queue::assertPushed(PostBangCommandReply::class, fn ($job) => str_contains($job->text, '!n0g ping'));
});
