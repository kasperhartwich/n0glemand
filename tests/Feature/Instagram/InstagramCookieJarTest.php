<?php

use App\Services\Instagram\InstagramCookieJar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    File::deleteDirectory(storage_path('app/private/instagram-cookies'));

    config()->set('services.instagram.cookies_ttl', 60 * 60 * 24);
    config()->set('services.instagram.login_cooldown', 300);
    config()->set('services.instagram.username', null);
    config()->set('services.instagram.password', null);
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/private/instagram-cookies'));
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

    expect(app(InstagramCookieJar::class)->pickCookiesPath())->toBeNull();
    Http::assertNothingSent();
});

it('returns the cached path on a fresh-cache hit without calling Instagram', function () {
    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    $path = seedCookiesFile();
    Http::fake();

    expect(app(InstagramCookieJar::class)->pickCookiesPath())->toBe($path);
    Http::assertNothingSent();
});

it('logs in and writes a cookies file when the cache is empty', function () {
    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    fakeSuccessfulLogin();

    $path = app(InstagramCookieJar::class)->pickCookiesPath();

    expect($path)->not->toBeNull();
    expect(File::exists($path))->toBeTrue();
    expect(File::get($path))->toContain('FRESH');
});

it('treats stale cookies as a cache miss and re-logs in', function () {
    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    config()->set('services.instagram.cookies_ttl', 60);
    seedCookiesFile(-120);
    fakeSuccessfulLogin();

    $path = app(InstagramCookieJar::class)->pickCookiesPath();

    expect(File::get($path))->toContain('FRESH');
});

it('markInvalid deletes the cached file without putting the account on cooldown', function () {
    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    $path = seedCookiesFile();
    fakeSuccessfulLogin();

    $jar = app(InstagramCookieJar::class);
    $jar->markInvalid('test reason');

    expect(File::exists($path))->toBeFalse();
    expect($jar->pickCookiesPath())->toBe($path);
    expect(File::get($path))->toContain('FRESH');
});

it('returns null and skips login while the cooldown is active after a login failure', function () {
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
    expect($jar->pickCookiesPath())->toBeNull();

    Http::fake();
    expect($jar->pickCookiesPath())->toBeNull();
    Http::assertNothingSent();
});
