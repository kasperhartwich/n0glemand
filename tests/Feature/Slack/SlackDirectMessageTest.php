<?php

use App\Jobs\Slack\CheckInstagramFromSlack;
use App\Jobs\Slack\PostPingReply;
use App\Jobs\Slack\RefreshInstagramCookiesFromSlack;
use App\Services\Instagram\InstagramCookieJar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Cache::flush();
    Log::spy();

    config()->set('services.slack.signing_secret', 'test-secret');
    config()->set('services.slack.signature_tolerance', 300);
    config()->set('services.slack.bot_token', 'xoxb-test');
    config()->set('services.slack.admin_user_ids', ['UADMIN']);
    config()->set('services.instagram.username', 'kasper');
    config()->set('services.instagram.password', 'pw');
    config()->set('services.instagram.cookies_ttl', 86400);

    File::delete(app(InstagramCookieJar::class)->cookiesPath());
    Http::fake(['https://slack.com/api/chat.postMessage' => Http::response(['ok' => true])]);
});

function postDm(string $text, string $user = 'UADMIN', string $eventId = 'EvDm1'): TestResponse
{
    $body = json_encode([
        'type' => 'event_callback',
        'event_id' => $eventId,
        'event' => [
            'type' => 'message',
            'channel_type' => 'im',
            'text' => $text,
            'channel' => 'D123',
            'user' => $user,
            'ts' => '1700000000.000100',
        ],
    ]);
    $ts = time();

    return test()->call('POST', '/slack/events', [], [], [], [
        'HTTP_X-Slack-Request-Timestamp' => (string) $ts,
        'HTTP_X-Slack-Signature' => 'v0='.hash_hmac('sha256', "v0:{$ts}:{$body}", 'test-secret'),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

function assertDmReply(callable $check): void
{
    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
        && $request['channel'] === 'D123'
        && $check((string) $request['text']));
}

it('queues a refresh or a check for an admin DM and acks in the DM', function (string $text, string $job) {
    Queue::fake();

    postDm($text)->assertNoContent();

    Queue::assertPushed($job, fn ($j) => $j->dmChannel === 'D123' && $j->responseUrl === '');
    assertDmReply(fn ($t) => str_contains($t, ':hourglass'));
})->with([
    'plain refresh' => ['instagram refresh', RefreshInstagramCookiesFromSlack::class],
    'slash word, caps' => ['/n0glemand Instagram Refresh', RefreshInstagramCookiesFromSlack::class],
    'check' => ['instagram check', CheckInstagramFromSlack::class],
    'without slash' => ['n0glemand instagram check', CheckInstagramFromSlack::class],
]);

it('answers instagram status in the DM without secrets', function () {
    Queue::fake();

    postDm('instagram status')->assertNoContent();

    assertDmReply(fn ($t) => str_contains($t, 'Cookie file: missing') && str_contains($t, 'Credentials: configured'));
    Queue::assertNothingPushed();
});

it('refuses a DM from someone who is not an admin and does nothing', function () {
    Queue::fake();

    postDm('instagram refresh', 'UOTHER')->assertNoContent();

    Queue::assertNothingPushed();
    assertDmReply(fn ($t) => str_contains($t, 'Only n0glemand admins'));
});

it('does not run channel handlers on a DM', function () {
    Queue::fake();

    postDm('ping')->assertNoContent();

    Queue::assertNotPushed(PostPingReply::class);
    assertDmReply(fn ($t) => str_contains($t, 'direct messages (admins only)'));
});

it('posts the job result into the DM', function () {
    Http::fake([
        'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
        'https://www.instagram.com/accounts/login/' => Http::response('', 200, [
            'Set-Cookie' => ['csrftoken=tok; Path=/; Domain=.instagram.com'],
        ]),
        'https://www.instagram.com/accounts/login/ajax/' => Http::response(
            ['authenticated' => true, 'user' => true],
            200,
            ['Set-Cookie' => ['sessionid=FRESH; Path=/; Domain=.instagram.com; Secure']],
        ),
    ]);

    postDm('instagram refresh')->assertNoContent();

    assertDmReply(fn ($t) => str_contains($t, 'login ok'));
});

it('DMs the admins once when a login ends in a checkpoint', function () {
    config()->set('services.slack.admin_user_ids', ['UADMIN', 'UADMIN2']);
    Http::fake([
        'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
        'https://www.instagram.com/accounts/login/' => Http::response('', 200, [
            'Set-Cookie' => ['csrftoken=tok; Path=/; Domain=.instagram.com'],
        ]),
        'https://www.instagram.com/accounts/login/ajax/' => Http::response(
            ['message' => 'checkpoint_required', 'checkpoint_url' => '/challenge/x/'],
        ),
    ]);
    $jar = app(InstagramCookieJar::class);

    $jar->refreshStatus();
    Cache::forget('ig:login:cooldown');
    $jar->refreshStatus();

    $sent = Http::recorded(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage');
    expect($sent)->toHaveCount(2)
        ->and($sent->map(fn ($pair) => $pair[0]['channel'])->values()->all())->toBe(['UADMIN', 'UADMIN2'])
        ->and($sent->values()->first()[0]['text'])->toContain('reply here with the code');
});

it('keeps an admin code in the cache and says it cannot submit it', function () {
    Cache::put('ig:checkpoint:awaiting', true, 600);

    postDm('123456')->assertNoContent();

    expect(Cache::get('ig:checkpoint:code'))->toBe('123456');
    assertDmReply(fn ($t) => str_contains($t, "can't submit it to Instagram") && str_contains($t, 'instagram refresh'));
});

it('ignores a code when no checkpoint is waiting for one', function () {
    postDm('123456')->assertNoContent();

    expect(Cache::get('ig:checkpoint:code'))->toBeNull();
    assertDmReply(fn ($t) => str_contains($t, 'No Instagram checkpoint is waiting'));
});

it('does not take a non-admin number as a code', function () {
    Cache::put('ig:checkpoint:awaiting', true, 600);

    postDm('123456', 'UOTHER')->assertNoContent();

    expect(Cache::get('ig:checkpoint:code'))->toBeNull();
});

it('does not take digits of the wrong length as a code', function () {
    Cache::put('ig:checkpoint:awaiting', true, 600);

    postDm('123')->assertNoContent();
    postDm('123456789', eventId: 'EvDm2')->assertNoContent();

    expect(Cache::get('ig:checkpoint:code'))->toBeNull();
});
