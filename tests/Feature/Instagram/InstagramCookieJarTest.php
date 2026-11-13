<?php

use App\Services\Instagram\InstagramCookieJar;
use App\Services\Instagram\InstagramLogin;
use App\Services\Instagram\InstagramLoginResult;
use App\Services\Instagram\InstagramLoginStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();

    config()->set('services.instagram.cookies_ttl', 60 * 60 * 24);
    config()->set('services.instagram.login_cooldown', 300);
    config()->set('services.instagram.username', null);
    config()->set('services.instagram.password', null);
});

function seedCookiesFile(int $mtimeOffsetSeconds = 0): string
{
    $path = app(InstagramCookieJar::class)->cookiesPath();
    File::ensureDirectoryExists(dirname($path));
    File::put($path, "# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t0\tsessionid\tseeded\n");

    if ($mtimeOffsetSeconds !== 0) {
        @touch($path, time() + $mtimeOffsetSeconds);
    }

    return $path;
}

function fakeSuccessfulLogin(): void
{
    Http::fake([
        'https://www.instagram.com/accounts/login/' => Http::response('', 200, [
            'Set-Cookie' => ['csrftoken=tok; Path=/; Domain=.instagram.com'],
        ]),
        'https://www.instagram.com/accounts/login/ajax/' => Http::response(
            ['authenticated' => true, 'user' => true, 'status' => 'ok'],
            200,
            ['Set-Cookie' => ['sessionid=FRESH; Path=/; Domain=.instagram.com; Secure']],
        ),
    ]);
}

it('returns null when credentials are not configured', function () {
    Http::fake();
    seedCookiesFile();

    expect(app(InstagramCookieJar::class)->currentCookiesPath())->toBeNull();
    Http::assertNothingSent();
});

it('returns the current file whatever its age and never logs in or touches it', function () {
    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    config()->set('services.instagram.cookies_ttl', 60);
    $path = seedCookiesFile(-3600);
    $mtime = filemtime($path);
    Http::fake();

    expect(app(InstagramCookieJar::class)->currentCookiesPath())->toBe($path)
        ->and(filemtime($path))->toBe($mtime);
    Http::assertNothingSent();
});

it('returns null without a file and does not log in to make one', function () {
    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    Http::fake();

    expect(app(InstagramCookieJar::class)->currentCookiesPath())->toBeNull();
    Http::assertNothingSent();
});

it('a forced login writes a cookies file', function () {
    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    fakeSuccessfulLogin();

    $jar = app(InstagramCookieJar::class);

    expect($jar->refresh())->toBeTrue()
        ->and(File::get($jar->currentCookiesPath()))->toContain('FRESH');
});

it('markInvalid deletes the file without a cooldown and the next login is told to start fresh', function () {
    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    $path = seedCookiesFile();
    $flags = [];
    app()->instance(InstagramLogin::class, new class($flags) implements InstagramLogin
    {
        public function __construct(private array &$flags) {}

        public function login(string $username, string $password, bool $forceFresh = false, bool $waitForCode = true): InstagramLoginResult
        {
            $this->flags[] = $forceFresh;

            return new InstagramLoginResult(InstagramLoginStatus::Ok, cookiesTxt: "sessionid\tFRESH\n");
        }
    });

    $jar = app(InstagramCookieJar::class);

    expect($jar->markInvalid('test reason'))->toBeTrue()
        ->and(File::exists($path))->toBeFalse()
        ->and($jar->cooldownStatus())->toBeNull();

    $jar->refresh();
    $jar->refresh();

    expect($flags)->toBe([true, false]);
});

it('markInvalid leaves cookies that a login replaced since they were used', function () {
    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    $path = seedCookiesFile();
    $seen = filemtime($path) - 100;

    $jar = app(InstagramCookieJar::class);

    expect($jar->markInvalid('late', $seen))->toBeFalse()
        ->and(File::exists($path))->toBeTrue();
    expect($jar->markInvalid('same file', (int) filemtime($path)))->toBeTrue()
        ->and(File::exists($path))->toBeFalse();
});

it('keeps the cooldown after a failed login and does not write a file', function () {
    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    Http::fake([
        'https://www.instagram.com/accounts/login/' => Http::response('', 200, [
            'Set-Cookie' => ['csrftoken=tok; Path=/; Domain=.instagram.com'],
        ]),
        'https://www.instagram.com/accounts/login/ajax/' => Http::response([
            'authenticated' => false,
            'error_type' => 'bad_password',
            'status' => 'fail',
        ]),
    ]);

    $jar = app(InstagramCookieJar::class);

    expect($jar->refresh())->toBeFalse()
        ->and($jar->cooldownStatus())->not->toBeNull()
        ->and($jar->currentCookiesPath())->toBeNull();
});

it('writes the cookies file with mode 0600 and leaves no temp file behind', function () {
    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    fakeSuccessfulLogin();

    $jar = app(InstagramCookieJar::class);
    $jar->refresh();
    $path = $jar->cookiesPath();

    expect(fileperms($path) & 0777)->toBe(0600)
        ->and(glob(dirname($path).'/*.tmp'))->toBe([]);
});

it('skips the login and returns null while another worker holds the login lock', function () {
    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    config()->set('services.instagram.login_lock_wait', 0);
    Http::fake();

    Cache::lock('ig:login', 60)->get();

    $jar = app(InstagramCookieJar::class);

    expect($jar->refresh())->toBeFalse();
    Http::assertNothingSent();
});

it('forces a fresh password login at most once per interval however often cookies are invalidated', function () {
    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    $flags = [];
    app()->instance(InstagramLogin::class, new class($flags) implements InstagramLogin
    {
        public function __construct(private array &$flags) {}

        public function login(string $username, string $password, bool $forceFresh = false, bool $waitForCode = true): InstagramLoginResult
        {
            $this->flags[] = $forceFresh;

            return new InstagramLoginResult(InstagramLoginStatus::Ok, cookiesTxt: "sessionid\tFRESH\n");
        }
    });
    $jar = app(InstagramCookieJar::class);

    seedCookiesFile();
    $jar->markInvalid('rate limit');
    $jar->refresh();
    seedCookiesFile();
    $jar->markInvalid('private reel');
    $jar->refresh();

    expect($flags)->toBe([true, false]);

    Cache::forget('ig:login:force_fresh_throttle');
    seedCookiesFile();
    $jar->markInvalid('later');
    $jar->refresh();

    expect($flags)->toBe([true, false, true]);
});
