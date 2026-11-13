<?php

use App\Jobs\Slack\RefreshInstagramLogin;
use App\Services\Instagram\InstagramCookieJar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Cache::flush();

    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    config()->set('services.instagram.login_cooldown', 300);

    File::delete(app(InstagramCookieJar::class)->cookiesPath());
});

function fakeInstagramLogin(bool $success): void
{
    Http::fake([
        'https://www.instagram.com/accounts/login/' => Http::response('', 200, [
            'Set-Cookie' => ['csrftoken=tok; Path=/; Domain=.instagram.com'],
        ]),
        'https://www.instagram.com/accounts/login/ajax/' => $success
            ? Http::response(['authenticated' => true, 'user' => true, 'status' => 'ok'], 200, [
                'Set-Cookie' => ['sessionid=FRESH; Path=/; Domain=.instagram.com; Secure'],
            ])
            : Http::response(['authenticated' => false, 'error_type' => 'bad_password', 'status' => 'fail']),
    ]);
}

it('logs in and writes the cookie file', function () {
    fakeInstagramLogin(true);

    (new RefreshInstagramLogin)->handle(app(InstagramCookieJar::class));

    expect(File::get(app(InstagramCookieJar::class)->cookiesPath()))->toContain('FRESH');
});

it('skips the login while the cooldown is active', function () {
    Http::fake();
    Cache::put('ig:login:cooldown', 'checkpoint_required', 300);

    (new RefreshInstagramLogin)->handle(app(InstagramCookieJar::class));

    Http::assertNothingSent();
});

it('starts the cooldown when the login fails', function () {
    fakeInstagramLogin(false);

    (new RefreshInstagramLogin)->handle($jar = app(InstagramCookieJar::class));

    expect($jar->cooldownStatus())->not->toBeNull();
});

it('does nothing without credentials', function () {
    config()->set('services.instagram.username', null);
    Http::fake();

    (new RefreshInstagramLogin)->handle(app(InstagramCookieJar::class));

    Http::assertNothingSent();
});

it('runs once on the instagram queue and logs when it fails', function () {
    $job = new RefreshInstagramLogin;

    expect($job->tries)->toBe(1)
        ->and($job->queue)->toBe('instagram')
        ->and($job->backoff())->toBe([0]);

    Log::spy();
    $job->failed(new RuntimeException('boom'));

    Log::shouldHaveReceived('error')->with('instagram.refresh.job_failed', ['exception' => 'boom']);
});

it('has its own single-process Horizon supervisor and leaves the default one alone', function () {
    $instagram = config('horizon.defaults.supervisor-instagram');
    $default = config('horizon.defaults.supervisor-1');

    expect($instagram['queue'])->toBe(['instagram'])
        ->and($instagram['processes'])->toBe(1)
        ->and($instagram['balance'])->toBeFalse()
        ->and($instagram['timeout'])->toBe(900)
        ->and($default['queue'])->toBe(['default'])
        ->and($default['timeout'])->toBe(240)
        ->and($default['maxProcesses'])->toBe(3);

    foreach (['production', 'local'] as $environment) {
        expect(config("horizon.environments.$environment"))->toHaveKey('supervisor-instagram');
    }
});

it('keeps the login lock longer than the longest login', function () {
    expect(config('services.instagram.login_lock_ttl'))
        ->toBeGreaterThan(config('services.instagram.browser_timeout') + config('services.instagram.code_wait') + 10);
});

it('keeps the uniqueness lock longer than the job may run, so a waiting login is never queued twice', function () {
    $job = new RefreshInstagramLogin;

    expect($job->uniqueFor)->toBeGreaterThan($job->timeout)
        ->and($job->tries)->toBe(1);
});
