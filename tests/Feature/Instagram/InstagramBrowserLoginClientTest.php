<?php

use App\Services\Instagram\InstagramBrowserLoginClient;
use App\Services\Instagram\InstagramCookieJar;
use App\Services\Instagram\InstagramLogin;
use App\Services\Instagram\InstagramLoginClient;
use App\Services\Instagram\InstagramLoginResult;
use App\Services\Instagram\InstagramLoginStatus;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeout;
use Symfony\Component\Process\Process as SymfonyProcess;

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('services.instagram.browser_dir', null);
    config()->set('services.instagram.node_binary', 'node');
    config()->set('services.instagram.browser_timeout', 50);
});

function browserLogin(): InstagramLoginResult
{
    return app(InstagramBrowserLoginClient::class)->login('kasper', 'hunter2');
}

function okLine(?array $cookies = null): string
{
    return json_encode([
        'status' => 'ok',
        'cookies' => $cookies ?? [
            ['name' => 'sessionid', 'value' => 'SESS', 'domain' => '.instagram.com', 'path' => '/', 'secure' => true, 'expires' => 1900000000],
            ['name' => 'csrftoken', 'value' => 'CSRF', 'domain' => '.instagram.com', 'path' => '/', 'secure' => true, 'expires' => 0],
            ['name' => 'ig_did', 'value' => 'DID', 'domain' => 'www.instagram.com', 'path' => '/', 'secure' => true, 'expires' => -1],
            ['name' => 'other', 'value' => 'NOPE', 'domain' => '.facebook.com', 'path' => '/', 'secure' => true, 'expires' => 0],
            ['name' => 'evil', 'value' => 'NOPE', 'domain' => '.notinstagram.com', 'path' => '/', 'secure' => true, 'expires' => 0],
        ],
    ])."\n";
}

it('turns an ok line into Netscape cookies for instagram.com only', function () {
    Process::fake(['*' => Process::result("warning noise\n".okLine())]);

    $result = browserLogin();

    expect($result->status)->toBe(InstagramLoginStatus::Ok)
        ->and($result->cookiesTxt)
        ->toContain("\tsessionid\tSESS")
        ->toContain("\tcsrftoken\tCSRF")
        ->toContain("www.instagram.com\tFALSE\t/\tTRUE\t0\tig_did\tDID")
        ->not->toContain('facebook')
        ->not->toContain('notinstagram');
});

it('maps every status the script can print', function (string $status, InstagramLoginStatus $expected) {
    Process::fake(['*' => Process::result(json_encode(['status' => $status, 'message' => 'because'])."\n")]);

    $result = browserLogin();

    expect($result->status)->toBe($expected)
        ->and($result->cookiesTxt)->toBeNull()
        ->and($result->errorMessage)->toBe('because');
})->with([
    ['invalid_credentials', InstagramLoginStatus::InvalidCredentials],
    ['checkpoint_required', InstagramLoginStatus::CheckpointRequired],
    ['two_factor_required', InstagramLoginStatus::TwoFactorRequired],
    ['unknown', InstagramLoginStatus::Unknown],
]);

it('treats ok without a sessionid as unknown', function () {
    Process::fake(['*' => Process::result(okLine([
        ['name' => 'csrftoken', 'value' => 'CSRF', 'domain' => '.instagram.com'],
    ]))]);

    expect(browserLogin()->status)->toBe(InstagramLoginStatus::Unknown);
});

it('treats an unknown status word as unknown', function () {
    Process::fake(['*' => Process::result(json_encode(['status' => 'banana'])."\n")]);

    expect(browserLogin()->status)->toBe(InstagramLoginStatus::Unknown);
});

it('treats garbage output as unknown', function () {
    Process::fake(['*' => Process::result("not json at all\n{broken")]);

    $result = browserLogin();

    expect($result->status)->toBe(InstagramLoginStatus::Unknown)
        ->and($result->errorMessage)->toContain('no JSON');
});

it('treats a non-zero exit without a result as unknown, with the stderr tail', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'Error: browserType.launch: missing libs', exitCode: 1)]);

    $result = browserLogin();

    expect($result->status)->toBe(InstagramLoginStatus::Unknown)
        ->and($result->errorMessage)->toContain('code 1')->toContain('missing libs');
});

it('still reads the result line when the script exits non-zero after printing one', function () {
    Process::fake(['*' => Process::result(json_encode(['status' => 'unknown', 'message' => 'Browser login failed: x'])."\n", exitCode: 1)]);

    expect(browserLogin()->errorMessage)->toBe('Browser login failed: x');
});

it('treats a process timeout as unknown', function () {
    Process::fake(['*' => function () {
        throw new ProcessTimedOutException(
            new SymfonyTimeout(new SymfonyProcess(['node']), SymfonyTimeout::TYPE_GENERAL),
            Process::result(),
        );
    }]);

    $result = browserLogin();

    expect($result->status)->toBe(InstagramLoginStatus::Unknown)
        ->and($result->errorMessage)->toContain('failed to run');
});

it('passes the credentials in the environment and never in argv', function () {
    Process::fake(['*' => Process::result(okLine())]);

    browserLogin();

    Process::assertRan(function ($process) {
        $command = $process->command;
        $argv = is_array($command) ? implode(' ', $command) : $command;

        return str_ends_with($argv, 'resources/scripts/instagram-login/login.mjs')
            && ! str_contains($argv, 'hunter2')
            && ! in_array('kasper', (array) $command, true)
            && $process->environment['INSTAGRAM_LOGIN_USERNAME'] === 'kasper'
            && $process->environment['INSTAGRAM_LOGIN_PASSWORD'] === 'hunter2'
            && $process->environment['INSTAGRAM_PROFILE_DIR'] === storage_path('app/private/instagram-browser')
            && $process->timeout === 50;
    });
});

it('uses the configured node binary and browser folder', function () {
    config()->set('services.instagram.node_binary', '/opt/node/bin/node');
    config()->set('services.instagram.browser_dir', '/home/forge/n0glemand-browser/');
    Process::fake(['*' => Process::result(okLine())]);

    browserLogin();

    Process::assertRan(fn ($process) => $process->command[0] === '/opt/node/bin/node'
        && $process->environment['INSTAGRAM_BROWSER_DIR'] === '/home/forge/n0glemand-browser'
        && $process->environment['PLAYWRIGHT_BROWSERS_PATH'] === '/home/forge/n0glemand-browser/browsers'
        && $process->environment['INSTAGRAM_LOGIN_TIMEOUT'] === '42');
});

it('keeps the browser profile private', function () {
    Process::fake(['*' => Process::result(okLine())]);

    browserLogin();

    $dir = storage_path('app/private/instagram-browser');

    expect(is_dir($dir))->toBeTrue()
        ->and(substr(sprintf('%o', fileperms($dir)), -4))->toBe('0700');
});

it('binds the http client by default and the browser client when asked', function (?string $driver, string $expected) {
    config()->set('services.instagram.login_driver', $driver);

    expect(app(InstagramLogin::class))->toBeInstanceOf($expected);
})->with([
    [null, InstagramLoginClient::class],
    ['http', InstagramLoginClient::class],
    ['nonsense', InstagramLoginClient::class],
    ['browser', InstagramBrowserLoginClient::class],
]);

it('feeds the cookie jar with the browser login and writes the cookie file', function () {
    config()->set('services.instagram.login_driver', 'browser');
    config()->set('services.instagram.username', 'kasper');
    config()->set('services.instagram.password', 'hunter2');
    Process::fake(['*' => Process::result(okLine())]);

    $jar = app(InstagramCookieJar::class);
    @unlink($jar->cookiesPath());

    expect($jar->refreshStatus())->toBe(InstagramLoginStatus::Ok)
        ->and(file_get_contents($jar->cookiesPath()))->toContain("\tsessionid\tSESS");

    @unlink($jar->cookiesPath());
});
