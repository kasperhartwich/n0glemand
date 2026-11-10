<?php

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
    config()->set('services.slack.admin_user_ids', ['UADMIN']);
    config()->set('services.instagram.username', 'kasper');
    config()->set('services.instagram.password', 'pw');
    config()->set('services.instagram.cookies_ttl', 86400);
    config()->set('services.instagram.login_cooldown', 300);

    File::delete(app(InstagramCookieJar::class)->cookiesPath());
});

function postInstagramCommand(string $text, string $userId = 'UADMIN'): TestResponse
{
    $form = [
        'command' => '/n0glemand',
        'text' => $text,
        'user_id' => $userId,
        'response_url' => 'https://hooks.slack.test/response/abc',
    ];
    $body = http_build_query($form);
    $ts = time();
    $signature = 'v0='.hash_hmac('sha256', "v0:{$ts}:{$body}", 'test-secret');

    return test()->call('POST', '/slack/commands', $form, [], [], [
        'HTTP_X-Slack-Request-Timestamp' => (string) $ts,
        'HTTP_X-Slack-Signature' => $signature,
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ], $body);
}

it('refuses instagram commands for users who are not admins', function (string $text) {
    Queue::fake();

    postInstagramCommand($text, 'UOTHER')
        ->assertOk()
        ->assertJsonPath('response_type', 'ephemeral')
        ->assertJsonPath('text', 'Only n0glemand admins can run `instagram` commands.');

    Queue::assertNothingPushed();
})->with(['instagram refresh', 'instagram status']);

it('queues a refresh for an admin and answers at once', function () {
    Queue::fake();

    postInstagramCommand('instagram refresh')->assertOk();

    Queue::assertPushed(
        RefreshInstagramCookiesFromSlack::class,
        fn ($job) => $job->responseUrl === 'https://hooks.slack.test/response/abc',
    );
});

it('posts a classified failure to the response url', function () {
    Http::fake([
        'https://www.instagram.com/accounts/login/' => Http::response('', 200, [
            'Set-Cookie' => ['csrftoken=tok; Path=/; Domain=.instagram.com'],
        ]),
        'https://www.instagram.com/accounts/login/ajax/' => Http::response([
            'message' => 'checkpoint_required',
            'checkpoint_url' => '/challenge/xyz/',
            'status' => 'fail',
        ]),
        'https://hooks.slack.test/*' => Http::response('ok'),
    ]);

    postInstagramCommand('instagram refresh')->assertOk();

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.test/response/abc'
        && str_contains($request['text'], 'checkpoint_required')
        && $request['response_type'] === 'ephemeral');
    expect(app(InstagramCookieJar::class)->cooldownStatus())->toBe('checkpoint_required');
});

it('posts success to the response url and clears the cooldown', function () {
    Cache::put('ig:login:cooldown', 'checkpoint_required', 300);
    Http::fake([
        'https://www.instagram.com/accounts/login/' => Http::response('', 200, [
            'Set-Cookie' => ['csrftoken=tok; Path=/; Domain=.instagram.com'],
        ]),
        'https://www.instagram.com/accounts/login/ajax/' => Http::response(
            ['authenticated' => true, 'user' => true, 'status' => 'ok'],
            200,
            ['Set-Cookie' => ['sessionid=FRESH; Path=/; Domain=.instagram.com; Secure']],
        ),
        'https://hooks.slack.test/*' => Http::response('ok'),
    ]);

    postInstagramCommand('instagram refresh')->assertOk();

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.test/response/abc'
        && str_contains($request['text'], 'login ok'));
    expect(app(InstagramCookieJar::class)->cooldownStatus())->toBeNull();
    expect(app(InstagramCookieJar::class)->cookiesAgeSeconds())->not->toBeNull();
});

it('reports a missing cookie file and no cooldown', function () {
    $text = postInstagramCommand('instagram status')->assertOk()->json('text');

    expect($text)->toContain('Cookie file: missing')
        ->toContain('Login cooldown: not active')
        ->toContain('Credentials: configured');
});

it('reports cookie age and an active cooldown without secrets', function () {
    $jar = app(InstagramCookieJar::class);
    File::ensureDirectoryExists(dirname($jar->cookiesPath()));
    File::put($jar->cookiesPath(), "sessionid\tSECRETVALUE\n");
    touch($jar->cookiesPath(), time() - 3 * 3600);
    Cache::put('ig:login:cooldown', 'invalid_credentials', 300);

    $text = postInstagramCommand('instagram status')->assertOk()->json('text');

    expect($text)->toContain('present, 3 h old')
        ->toContain('active after `invalid_credentials`')
        ->not->toContain('SECRETVALUE')
        ->not->toContain('pw');
});

it('shows usage for an unknown instagram action and lists the commands in help', function () {
    expect(postInstagramCommand('instagram nope')->json('text'))->toContain('Usage:');
    expect(postInstagramCommand('help')->json('text'))->toContain('instagram refresh');
});

it('tells the admin when another login holds the lock', function () {
    config()->set('services.instagram.login_lock_wait', 0);
    Http::fake(['https://hooks.slack.test/*' => Http::response('ok')]);

    $lock = Cache::lock('ig:login', 60);
    $lock->get();

    postInstagramCommand('instagram refresh')->assertOk();

    $lock->release();

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.test/response/abc'
        && str_contains($request['text'], 'already running'));
});
