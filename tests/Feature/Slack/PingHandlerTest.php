<?php

use App\Jobs\Slack\PostPingReply;
use App\Services\Slack\Handlers\PingHandler;
use Illuminate\Support\Facades\Queue;

it('matches messages that are exactly "ping" (case- and whitespace-insensitive)', function (string $text, bool $expected) {
    expect(app(PingHandler::class)->matches(['text' => $text]))->toBe($expected);
})->with([
    'plain ping' => ['ping', true],
    'uppercase' => ['PING', true],
    'padded' => ['  ping  ', true],
    'ping with extra' => ['ping please', false],
    'embedded' => ['hey ping there', false],
    'bang ping' => ['!n0g ping', false],
    'empty' => ['', false],
]);

it('dispatches a reply job with pong', function () {
    Queue::fake();

    app(PingHandler::class)->handle(
        ['text' => 'ping'],
        channel: 'C42',
        threadTs: '1700000000.000100',
        userId: 'U1',
    );

    Queue::assertPushed(PostPingReply::class, fn ($job) => $job->channel === 'C42'
        && $job->threadTs === '1700000000.000100'
        && str_contains($job->text, 'pong'),
    );
});
