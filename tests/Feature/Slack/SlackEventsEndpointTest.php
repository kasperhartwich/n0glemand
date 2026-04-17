<?php

use App\Jobs\Slack\JoinPublicChannel;
use App\Jobs\Slack\PostSonglinkToThread;
use App\Jobs\Slack\UploadInstagramMediaToThread;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('services.slack.signing_secret', 'test-secret');
    config()->set('services.slack.signature_tolerance', 300);
    config()->set('services.slack.event_dedupe_ttl', 600);
    Cache::flush();
});

function postSlackEvent(array $payload, string $secret = 'test-secret', ?int $ts = null): TestResponse
{
    $body = json_encode($payload);
    $ts ??= time();
    $signature = 'v0='.hash_hmac('sha256', "v0:{$ts}:{$body}", $secret);

    return test()->call(
        method: 'POST',
        uri: '/slack/events',
        parameters: [],
        cookies: [],
        files: [],
        server: [
            'HTTP_X-Slack-Request-Timestamp' => (string) $ts,
            'HTTP_X-Slack-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $body,
    );
}

function messageEvent(string $text, string $eventId = 'Ev001', string $channel = 'C123', string $ts = '1700000000.000100'): array
{
    return [
        'type' => 'event_callback',
        'event_id' => $eventId,
        'event' => [
            'type' => 'message',
            'text' => $text,
            'channel' => $channel,
            'user' => 'U123',
            'ts' => $ts,
        ],
    ];
}

it('responds to url_verification challenge', function () {
    postSlackEvent([
        'type' => 'url_verification',
        'challenge' => 'abc123',
    ])->assertOk()->assertJson(['challenge' => 'abc123']);
});

it('rejects events with an invalid signature', function () {
    $this->call(
        method: 'POST',
        uri: '/slack/events',
        parameters: [],
        cookies: [],
        files: [],
        server: [
            'HTTP_X-Slack-Request-Timestamp' => (string) time(),
            'HTTP_X-Slack-Signature' => 'v0=bogus',
            'CONTENT_TYPE' => 'application/json',
        ],
        content: json_encode(['type' => 'url_verification']),
    )->assertStatus(401);
});

it('dedupes duplicate event_id', function () {
    Queue::fake();

    $payload = messageEvent('https://open.spotify.com/track/abc123', eventId: 'Ev999');

    postSlackEvent($payload)->assertNoContent();
    postSlackEvent($payload)->assertNoContent();

    Queue::assertPushed(PostSonglinkToThread::class, 1);
});

it('ignores bot_message events', function () {
    Queue::fake();

    $payload = messageEvent('https://open.spotify.com/track/abc123', eventId: 'Ev-bot');
    $payload['event']['subtype'] = 'bot_message';
    $payload['event']['bot_id'] = 'B1';

    postSlackEvent($payload)->assertNoContent();

    Queue::assertNothingPushed();
});

it('dispatches instagram job for IG link', function () {
    Queue::fake();

    postSlackEvent(messageEvent('look at this https://www.instagram.com/reel/ABCdef123/ cool', eventId: 'Ev-ig'))
        ->assertNoContent();

    Queue::assertPushed(UploadInstagramMediaToThread::class, fn ($job) => str_contains($job->instagramUrl, 'ABCdef123')
        && $job->channel === 'C123'
        && $job->threadTs === '1700000000.000100',
    );
});

it('dispatches songlink job for Spotify link', function () {
    Queue::fake();

    postSlackEvent(messageEvent('<https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT>', eventId: 'Ev-sp'))
        ->assertNoContent();

    Queue::assertPushed(PostSonglinkToThread::class, fn ($job) => $job->spotifyUrl === 'https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT'
        && $job->channel === 'C123'
        && $job->threadTs === '1700000000.000100',
    );
});

it('dispatches both jobs for a message with both link types', function () {
    Queue::fake();

    $text = 'IG https://instagram.com/p/XYZ/ and SP https://open.spotify.com/album/abcDEF';
    postSlackEvent(messageEvent($text, eventId: 'Ev-both'))->assertNoContent();

    Queue::assertPushed(UploadInstagramMediaToThread::class, 1);
    Queue::assertPushed(PostSonglinkToThread::class, 1);
});

it('dispatches a join job when a channel_created event arrives', function () {
    Queue::fake();

    postSlackEvent([
        'type' => 'event_callback',
        'event_id' => 'Ev-created',
        'event' => [
            'type' => 'channel_created',
            'channel' => ['id' => 'C-NEW', 'name' => 'announcements'],
        ],
    ])->assertNoContent();

    Queue::assertPushed(JoinPublicChannel::class, fn ($job) => $job->channelId === 'C-NEW');
});

it('uses thread_ts when the message is inside a thread', function () {
    Queue::fake();

    $payload = messageEvent('https://open.spotify.com/track/abc', eventId: 'Ev-thread');
    $payload['event']['thread_ts'] = '1699999999.000000';

    postSlackEvent($payload)->assertNoContent();

    Queue::assertPushed(PostSonglinkToThread::class, fn ($job) => $job->threadTs === '1699999999.000000');
});
