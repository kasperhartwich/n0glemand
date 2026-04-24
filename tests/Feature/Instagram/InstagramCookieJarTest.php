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
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/private/instagram-cookies'));
});

function seedCookiesFile(string $username, int $mtimeOffsetSeconds = 0): string
{
    $dir = storage_path('app/private/instagram-cookies');
    File::ensureDirectoryExists($dir);
    $path = $dir.'/'.$username.'.txt';
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

it('returns null when no accounts are configured', function () {
    config()->set('services.instagram.accounts', []);
    Http::fake();

    expect(app(InstagramCookieJar::class)->pickCookies())->toBeNull();
    Http::assertNothingSent();
});

it('returns the cached path on a fresh-cache hit without calling Instagram', function () {
    config()->set('services.instagram.accounts', [['username' => 'acct1', 'password' => 'pw']]);
    $path = seedCookiesFile('acct1');
    Http::fake();

    $pick = app(InstagramCookieJar::class)->pickCookies();

    expect($pick)->toMatchArray(['username' => 'acct1', 'path' => $path]);
    Http::assertNothingSent();
});

it('logs in and writes a cookies file when the cache is empty', function () {
    config()->set('services.instagram.accounts', [['username' => 'acct1', 'password' => 'pw']]);
    fakeSuccessfulLogin();

    $pick = app(InstagramCookieJar::class)->pickCookies();

    expect($pick['username'])->toBe('acct1');
    expect(File::exists($pick['path']))->toBeTrue();
    expect(File::get($pick['path']))->toContain('FRESH');
});

it('rotates LRU across fresh accounts, picking the oldest first', function () {
    config()->set('services.instagram.accounts', [
        ['username' => 'acct1', 'password' => 'pw'],
        ['username' => 'acct2', 'password' => 'pw'],
    ]);
    $old = seedCookiesFile('acct1', -120);
    $new = seedCookiesFile('acct2', -10);
    Http::fake();

    $pick = app(InstagramCookieJar::class)->pickCookies();

    expect($pick['username'])->toBe('acct1');
    expect($pick['path'])->toBe($old);
    expect(filemtime($old))->toBeGreaterThanOrEqual(filemtime($new));
});

it('excludes the username passed as argument', function () {
    config()->set('services.instagram.accounts', [
        ['username' => 'acct1', 'password' => 'pw'],
        ['username' => 'acct2', 'password' => 'pw'],
    ]);
    seedCookiesFile('acct1', -120);
    seedCookiesFile('acct2', -10);
    Http::fake();

    $pick = app(InstagramCookieJar::class)->pickCookies(excludeUsername: 'acct1');

    expect($pick['username'])->toBe('acct2');
});

it('markInvalid deletes the cached file and places the account on cooldown', function () {
    config()->set('services.instagram.accounts', [['username' => 'acct1', 'password' => 'pw']]);
    $path = seedCookiesFile('acct1');
    Http::fake();

    $jar = app(InstagramCookieJar::class);
    $jar->markInvalid('acct1', 'test reason');

    expect(File::exists($path))->toBeFalse();
    expect($jar->pickCookies())->toBeNull();
    Http::assertNothingSent();
});

it('logs in on next pick once cooldown expires', function () {
    config()->set('services.instagram.accounts', [['username' => 'acct1', 'password' => 'pw']]);
    config()->set('services.instagram.login_cooldown', 1);
    fakeSuccessfulLogin();

    $jar = app(InstagramCookieJar::class);
    $jar->markInvalid('acct1', 'stale');

    expect($jar->pickCookies())->toBeNull();

    Cache::flush();

    $pick = $jar->pickCookies();
    expect($pick['username'])->toBe('acct1');
});

it('returns null when every account fails to log in', function () {
    config()->set('services.instagram.accounts', [['username' => 'acct1', 'password' => 'pw']]);
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

    expect(app(InstagramCookieJar::class)->pickCookies())->toBeNull();
});
