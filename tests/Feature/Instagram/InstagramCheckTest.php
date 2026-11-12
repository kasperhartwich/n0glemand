<?php

use App\Jobs\Slack\CheckInstagramFromSlack;
use App\Services\Instagram\InstagramCheckStatus;
use App\Services\Instagram\InstagramCookieJar;
use App\Services\Instagram\InstagramSessionCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

const CURRENT_USER = 'https://www.instagram.com/api/v1/accounts/current_user/*';

beforeEach(function () {
    Cache::flush();
    Log::spy();

    config()->set('services.instagram.username', 'kasper');
    config()->set('services.instagram.password', 'pw');
    config()->set('services.instagram.cookies_ttl', 86400);
    config()->set('services.slack.ytdlp_cookies', null);
    config()->set('services.slack.admin_user_ids', ['UADMIN1', 'UADMIN2']);
    config()->set('services.slack.bot_token', 'xoxb-test');

    File::delete(app(InstagramCookieJar::class)->cookiesPath());
});

function writeCookieFile(string $sessionExpires = '0'): string
{
    $path = app(InstagramCookieJar::class)->cookiesPath();
    File::put($path, implode("\n", [
        '# Netscape HTTP Cookie File',
        "#HttpOnly_.instagram.com\tTRUE\t/\tTRUE\t{$sessionExpires}\tsessionid\tSESSION123",
        ".instagram.com\tTRUE\t/\tTRUE\t0\tcsrftoken\tCSRF456",
        ".example.com\tTRUE\t/\tTRUE\t0\tother\tNOPE",
        '',
    ]));

    return $path;
}

it('is ok when instagram accepts the cookies, and sends only instagram cookies', function () {
    writeCookieFile();
    Http::fake([CURRENT_USER => Http::response(['user' => ['username' => 'kasper'], 'status' => 'ok'])]);

    $result = app(InstagramSessionCheck::class)->run();

    expect($result->status)->toBe(InstagramCheckStatus::Ok);
    Http::assertSent(fn ($request) => $request->header('Cookie')[0] === 'sessionid=SESSION123; csrftoken=CSRF456'
        && $request->header('X-CSRFToken')[0] === 'CSRF456');
    Log::shouldHaveReceived('info')->with('slack.ig.healthcheck.ok');
});

it('classifies a rejected session as invalid cookies', function (int $status, array $body) {
    writeCookieFile();
    Http::fake([CURRENT_USER => Http::response($body, $status)]);

    $result = app(InstagramSessionCheck::class)->run();

    expect($result->status)->toBe(InstagramCheckStatus::CookiesInvalid);
    Log::shouldHaveReceived('warning')->with('slack.ig.healthcheck.failed', Mockery::type('array'));
})->with([
    '401 login required' => [401, ['message' => 'login_required', 'require_login' => true, 'status' => 'fail']],
    '403' => [403, []],
]);

it('classifies a redirect to the login page as invalid cookies', function () {
    writeCookieFile();
    Http::fake([CURRENT_USER => Http::response('', 302, ['Location' => 'https://www.instagram.com/accounts/login/'])]);

    expect(app(InstagramSessionCheck::class)->run()->status)->toBe(InstagramCheckStatus::CookiesInvalid);
});

it('follows a redirect to a canonical url with the same cookies and logs the Location', function () {
    writeCookieFile();
    Http::fake([
        'https://www.instagram.com/api/v1/accounts/current_user/?edit=true' => Http::response('', 302, ['Location' => '/api/v1/accounts/current_user/?edit=true&x=1']),
        'https://www.instagram.com/api/v1/accounts/current_user/?edit=true&x=1' => Http::response(['user' => ['username' => 'kasper']]),
    ]);

    expect(app(InstagramSessionCheck::class)->run()->status)->toBe(InstagramCheckStatus::Ok);
    Http::assertSent(fn ($request) => str_contains($request->url(), '&x=1')
        && $request->header('Cookie')[0] === 'sessionid=SESSION123; csrftoken=CSRF456'
        && $request->header('Referer')[0] === 'https://www.instagram.com/');
    Log::shouldHaveReceived('warning')->with('slack.ig.healthcheck.redirect', Mockery::on(fn ($c) => $c['location'] === '/api/v1/accounts/current_user/?edit=true&x=1'));
});

it('does not call an unexplained redirect a rejection, and names the Location', function () {
    writeCookieFile();
    Http::fake([CURRENT_USER => Http::response('', 302, ['Location' => 'https://elsewhere.example/x'])]);

    $result = app(InstagramSessionCheck::class)->run();

    expect($result->status)->toBe(InstagramCheckStatus::Inconclusive)
        ->and($result->message)->toContain('https://elsewhere.example/x');
});

it('classifies a redirect to a challenge as a checkpoint', function () {
    writeCookieFile();
    Http::fake([CURRENT_USER => Http::response('', 302, ['Location' => 'https://www.instagram.com/challenge/abc/'])]);

    expect(app(InstagramSessionCheck::class)->run()->status)->toBe(InstagramCheckStatus::CheckpointRequired);
});

it('confirms with yt-dlp on the check reel when the HTTP check cannot tell', function () {
    writeCookieFile();
    config()->set('services.instagram.check_url', 'https://www.instagram.com/reel/ABC/');
    Http::fake([CURRENT_USER => Http::response('', 302, ['Location' => 'https://www.instagram.com/accounts/login/'])]);
    Process::fake(['*' => Process::result('{}')]);

    $result = app(InstagramSessionCheck::class)->run();

    expect($result->status)->toBe(InstagramCheckStatus::Ok);
    Process::assertRan(fn ($p) => in_array('https://www.instagram.com/reel/ABC/', $p->command, true) && in_array('--skip-download', $p->command, true));
});

it('keeps the HTTP verdict when yt-dlp fails on the check reel too', function () {
    writeCookieFile();
    config()->set('services.instagram.check_url', 'https://www.instagram.com/reel/ABC/');
    Http::fake([CURRENT_USER => Http::response('', 401, ['require_login' => true])]);
    Process::fake(['*' => Process::result('', 'login required', 1)]);

    expect(app(InstagramSessionCheck::class)->run()->status)->toBe(InstagramCheckStatus::CookiesInvalid);
});

it('classifies a checkpoint', function () {
    writeCookieFile();
    Http::fake([CURRENT_USER => Http::response(['message' => 'checkpoint_required', 'checkpoint_url' => '/challenge/x/'], 400)]);

    expect(app(InstagramSessionCheck::class)->run()->status)->toBe(InstagramCheckStatus::CheckpointRequired);
});

it('is inconclusive on a server error or a transport failure', function () {
    writeCookieFile();
    Http::fake([CURRENT_USER => Http::response('', 500)]);
    expect(app(InstagramSessionCheck::class)->run()->status)->toBe(InstagramCheckStatus::Inconclusive);

    Http::fake([CURRENT_USER => fn () => throw new ConnectionException('timeout')]);
    expect(app(InstagramSessionCheck::class)->run()->status)->toBe(InstagramCheckStatus::Inconclusive);
});

it('fails without a request when the sessionid has expired', function () {
    writeCookieFile((string) (time() - 60));
    Http::fake();

    expect(app(InstagramSessionCheck::class)->run()->status)->toBe(InstagramCheckStatus::CookiesInvalid);
    Http::assertNothingSent();
});

it('falls back to the exported cookie file from YTDLP_COOKIES_PATH', function () {
    config()->set('services.instagram.username', null);
    config()->set('services.instagram.password', null);
    $exported = tempnam(sys_get_temp_dir(), 'ig');
    File::put($exported, ".instagram.com\tTRUE\t/\tTRUE\t0\tsessionid\tEXPORTED\n");
    config()->set('services.slack.ytdlp_cookies', $exported);
    Http::fake([CURRENT_USER => Http::response(['user' => ['username' => 'kasper']])]);

    $result = app(InstagramSessionCheck::class)->run();
    File::delete($exported);

    expect($result->isOk())->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request->header('Cookie')[0], 'sessionid=EXPORTED'));
});

it('reports no cookies when there is no file and no credentials', function () {
    config()->set('services.instagram.username', null);
    config()->set('services.instagram.password', null);
    Http::fake();

    expect(app(InstagramSessionCheck::class)->run()->status)->toBe(InstagramCheckStatus::NoCookies);
    Http::assertNothingSent();
});

it('logs in only when there is no cookie file and credentials are set', function () {
    config()->set('services.instagram.username', 'kasper');
    config()->set('services.instagram.password', 'pw');
    Http::fake([
        'https://www.instagram.com/accounts/login/' => Http::response('', 200, [
            'Set-Cookie' => ['csrftoken=tok; Path=/; Domain=.instagram.com'],
        ]),
        'https://www.instagram.com/accounts/login/ajax/' => Http::response(['message' => 'checkpoint_required', 'checkpoint_url' => '/c/']),
    ]);

    $result = app(InstagramSessionCheck::class)->run();

    expect($result->status)->toBe(InstagramCheckStatus::LoginFailed)
        ->and($result->message)->toContain('checkpoint_required');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'current_user'));
});

it('does not touch the login when a cookie file exists, even with credentials', function () {
    config()->set('services.instagram.username', 'kasper');
    config()->set('services.instagram.password', 'pw');
    writeCookieFile();
    Http::fake([CURRENT_USER => Http::response('', 401)]);

    app(InstagramSessionCheck::class)->run();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/accounts/login'));
});

it('stays silent in Slack when instagram:check passes', function () {
    writeCookieFile();
    Http::fake([CURRENT_USER => Http::response(['user' => ['username' => 'kasper']])]);

    $this->artisan('instagram:check --notify')->assertSuccessful();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'slack.com'));
});

it('DMs every admin when instagram:check --notify fails', function () {
    writeCookieFile();
    Http::fake([
        CURRENT_USER => Http::response('', 401),
        'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
    ]);

    $this->artisan('instagram:check --notify')->assertFailed();

    foreach (['UADMIN1', 'UADMIN2'] as $admin) {
        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat.postMessage')
            && $request['channel'] === $admin
            && str_contains($request['text'], 'cookies_invalid')
            && str_contains($request['text'], '/n0glemand instagram refresh'));
    }
});

it('does not DM without --notify, and keeps going when one DM fails', function () {
    writeCookieFile();
    Http::fake([
        CURRENT_USER => Http::response('', 401),
        'https://slack.com/api/chat.postMessage' => Http::sequence()
            ->push(['ok' => false, 'error' => 'channel_not_found'])
            ->push(['ok' => true]),
    ]);

    $this->artisan('instagram:check')->assertFailed();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'slack.com'));

    $this->artisan('instagram:check --notify')->assertFailed();
    Http::assertSentCount(4);
    Log::shouldHaveReceived('error')->with('slack.ig.healthcheck.dm_failed', Mockery::type('array'));
});

it('posts the check result to the response url from the slack job', function () {
    writeCookieFile();
    Http::fake([
        CURRENT_USER => Http::response('', 401),
        'https://hooks.slack.test/*' => Http::response('ok'),
    ]);

    dispatch_sync(new CheckInstagramFromSlack('https://hooks.slack.test/response/abc'));

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.test/response/abc'
        && str_contains($request['text'], 'cookies_invalid'));
});

it('without credentials checks only the exported file, not a leftover jar file', function () {
    config()->set('services.instagram.username', null);
    config()->set('services.instagram.password', null);
    writeCookieFile();
    $exported = tempnam(sys_get_temp_dir(), 'ig');
    File::put($exported, ".instagram.com\tTRUE\t/\tTRUE\t0\tsessionid\tEXPORTED\n");
    config()->set('services.slack.ytdlp_cookies', $exported);
    Http::fake([CURRENT_USER => Http::response('', 401)]);

    $result = app(InstagramSessionCheck::class)->run();
    File::delete($exported);

    expect($result->status)->toBe(InstagramCheckStatus::CookiesInvalid);
    Http::assertSent(fn ($request) => str_contains($request->header('Cookie')[0], 'EXPORTED')
        && ! str_contains($request->header('Cookie')[0], 'SESSION123'));
});

it('without credentials and without an exported file reports no cookies despite a leftover jar file', function () {
    config()->set('services.instagram.username', null);
    config()->set('services.instagram.password', null);
    writeCookieFile();
    Http::fake();

    expect(app(InstagramSessionCheck::class)->run()->status)->toBe(InstagramCheckStatus::NoCookies);
    Http::assertNothingSent();
});

it('with credentials treats a stale jar file as missing and logs in like the job', function () {
    config()->set('services.instagram.username', 'kasper');
    config()->set('services.instagram.password', 'pw');
    config()->set('services.instagram.cookies_ttl', 3600);
    touch(writeCookieFile(), time() - 7200);
    Http::fake([
        'https://www.instagram.com/accounts/login/' => Http::response('', 200, [
            'Set-Cookie' => ['csrftoken=tok; Path=/; Domain=.instagram.com'],
        ]),
        'https://www.instagram.com/accounts/login/ajax/' => Http::response(
            ['authenticated' => true, 'user' => true],
            200,
            ['Set-Cookie' => ['sessionid=FRESH; Path=/; Domain=.instagram.com']],
        ),
    ]);

    expect(app(InstagramSessionCheck::class)->run()->isOk())->toBeTrue();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'current_user'));
});

it('with credentials ignores the exported file and logs in when the jar has none', function () {
    config()->set('services.instagram.username', 'kasper');
    config()->set('services.instagram.password', 'pw');
    $exported = tempnam(sys_get_temp_dir(), 'ig');
    File::put($exported, ".instagram.com\tTRUE\t/\tTRUE\t0\tsessionid\tEXPORTED\n");
    config()->set('services.slack.ytdlp_cookies', $exported);
    Http::fake(['https://www.instagram.com/accounts/login/' => Http::response('', 500)]);

    $result = app(InstagramSessionCheck::class)->run();
    File::delete($exported);

    expect($result->status)->toBe(InstagramCheckStatus::LoginFailed);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'current_user'));
});

it('tells the admin to retry the check, not to refresh, when the result is inconclusive', function () {
    writeCookieFile();
    Http::fake([
        CURRENT_USER => Http::response('', 500),
        'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
    ]);

    $this->artisan('instagram:check --notify')->assertFailed();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'chat.postMessage')
        && str_contains($request['text'], '/n0glemand instagram check')
        && ! str_contains($request['text'], 'instagram refresh'));
});
