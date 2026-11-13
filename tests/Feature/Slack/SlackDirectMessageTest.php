<?php

use App\Jobs\Slack\CheckInstagramFromSlack;
use App\Jobs\Slack\PostPingReply;
use App\Jobs\Slack\RefreshInstagramCookiesFromSlack;
use App\Services\Instagram\InstagramCheckpointChallenge;
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
    $this->codeFile = app(InstagramCheckpointChallenge::class)->codeFilePath();
    File::delete($this->codeFile);
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
        ->and($sent->values()->first()[0]['text'])->toContain('wants a checkpoint');
});

it('hands an admin code to the waiting login through the code file', function () {
    Cache::put('ig:checkpoint:awaiting', 'code', 600);

    postDm('123456')->assertNoContent();

    expect(file_get_contents($this->codeFile))->toBe('123456')
        ->and(substr(sprintf('%o', fileperms($this->codeFile)), -4))->toBe('0600');
    assertDmReply(fn ($t) => str_contains($t, 'Handed the code'));
});

it('accepts `instagram code 123456` in a DM too', function () {
    Cache::put('ig:checkpoint:awaiting', 'code', 600);

    postDm('/n0glemand instagram code 654321')->assertNoContent();

    expect(file_get_contents($this->codeFile))->toBe('654321');
});

it('ignores a code when no login is waiting for one', function () {
    postDm('123456')->assertNoContent();

    expect(file_exists($this->codeFile))->toBeFalse();
    assertDmReply(fn ($t) => str_contains($t, 'No Instagram login is waiting'));
});

it('says an approval is not a code', function () {
    Cache::put('ig:checkpoint:awaiting', 'approval', 600);

    postDm('123456')->assertNoContent();

    expect(file_exists($this->codeFile))->toBeFalse();
    assertDmReply(fn ($t) => str_contains($t, 'approval'));
});

it('does not take a non-admin number as a code', function () {
    Cache::put('ig:checkpoint:awaiting', 'code', 600);

    postDm('123456', 'UOTHER')->assertNoContent();

    expect(file_exists($this->codeFile))->toBeFalse();
});

it('does not take digits of the wrong length as a code', function () {
    Cache::put('ig:checkpoint:awaiting', 'code', 600);

    postDm('123')->assertNoContent();
    postDm('instagram code 123456789', eventId: 'EvDm2')->assertNoContent();

    expect(file_exists($this->codeFile))->toBeFalse();
});
