<?php

use App\Jobs\Slack\RefreshInstagramLogin;
use App\Services\Instagram\InstagramBrowserLoginClient;
use App\Services\Instagram\InstagramCheckpointChallenge;
use App\Services\Instagram\InstagramCookieJar;
use App\Services\Instagram\InstagramLoginStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Cache::flush();
    Log::spy();
    Http::preventStrayRequests();
    Http::fake(['https://slack.com/api/chat.postMessage' => Http::response(['ok' => true])]);

    config()->set('services.slack.signing_secret', 'test-secret');
    config()->set('services.slack.signature_tolerance', 300);
    config()->set('services.slack.bot_token', 'xoxb-test');
    config()->set('services.slack.admin_user_ids', ['UADMIN', 'UADMIN2']);
    config()->set('services.instagram.username', 'kasper');
    config()->set('services.instagram.password', 'pw');
    config()->set('services.instagram.login_driver', 'browser');
    config()->set('services.instagram.browser_timeout', 50);
    config()->set('services.instagram.code_wait', 600);
    config()->set('services.instagram.challenge_method', 'sms');
    config()->set('services.instagram.browser_dir', null);

    $this->codeFile = app(InstagramCheckpointChallenge::class)->codeFilePath();
    File::delete($this->codeFile);
    File::delete(app(InstagramCookieJar::class)->cookiesPath());
});

function relayOkLine(): string
{
    return json_encode(['status' => 'ok', 'cookies' => [
        ['name' => 'sessionid', 'value' => 'SESS', 'domain' => '.instagram.com', 'path' => '/', 'secure' => true, 'expires' => 1900000000],
    ]])."\n";
}

function relayScript(array $lines, int $exitCode = 0): void
{
    $description = Process::describe();

    foreach ($lines as $line) {
        $description = $description->output($line);
    }

    Process::fake(['*' => $description->exitCode($exitCode)]);
}

function relayDms(): array
{
    return Http::recorded(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage')
        ->map(fn ($pair) => [$pair[0]['channel'], (string) $pair[0]['text']])
        ->values()
        ->all();
}

function relayCommand(string $text, string $userId = 'UADMIN'): TestResponse
{
    $form = ['command' => '/n0glemand', 'text' => $text, 'user_id' => $userId, 'response_url' => 'https://hooks.slack.test/r'];
    $body = http_build_query($form);
    $ts = time();

    return test()->call('POST', '/slack/commands', $form, [], [], [
        'HTTP_X-Slack-Request-Timestamp' => (string) $ts,
        'HTTP_X-Slack-Signature' => 'v0='.hash_hmac('sha256', "v0:{$ts}:{$body}", 'test-secret'),
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ], $body);
}

it('DMs every admin when the script asks for a code, and the outcome after it', function () {
    relayScript([
        json_encode(['event' => 'code_required', 'method' => 'sms'])."\n",
        relayOkLine(),
    ]);

    $result = app(InstagramBrowserLoginClient::class)->login('kasper', 'pw');

    expect($result->status)->toBe(InstagramLoginStatus::Ok);

    $dms = relayDms();
    expect($dms)->toHaveCount(4)
        ->and([$dms[0][0], $dms[1][0]])->toBe(['UADMIN', 'UADMIN2'])
        ->and($dms[0][1])->toContain('Instagram wants a code (SMS)')->toContain('within 10 minutes')
        ->and([$dms[2][0], $dms[3][0]])->toBe(['UADMIN', 'UADMIN2'])
        ->and($dms[2][1])->toContain('login finished: ok');
    expect(Cache::get('ig:checkpoint:awaiting'))->toBeNull();
});

it('asks for an approval in the app when the script waits on a was-this-you screen', function () {
    relayScript([
        json_encode(['event' => 'approval_required'])."\n",
        relayOkLine(),
    ]);

    app(InstagramBrowserLoginClient::class)->login('kasper', 'pw');

    expect(relayDms()[0][1])->toContain('approve this login in the Instagram app');
});

it('DMs the admins the failure when the code is wrong or nobody answers', function (string $status, string $text) {
    relayScript([
        json_encode(['event' => 'code_required', 'method' => 'sms'])."\n",
        json_encode(['status' => $status, 'message' => 'x'])."\n",
    ]);

    $result = app(InstagramBrowserLoginClient::class)->login('kasper', 'pw');

    expect($result->status)->toBe(InstagramLoginStatus::from($status))
        ->and(relayDms()[2][1])->toContain($text);
})->with([
    ['invalid_code', 'did not accept the code'],
    ['timeout', 'nobody answered in time'],
]);

it('sends no DM for a login that never waited for a human', function () {
    relayScript([relayOkLine()]);

    app(InstagramBrowserLoginClient::class)->login('kasper', 'pw');

    expect(relayDms())->toBe([]);
});

it('runs the script for the code wait on top of the login time, with the method in its environment', function () {
    config()->set('services.instagram.challenge_method', 'email');
    relayScript([relayOkLine()]);

    app(InstagramBrowserLoginClient::class)->login('kasper', 'pw');

    Process::assertRan(fn ($process) => $process->timeout === 650
        && $process->environment['INSTAGRAM_CODE_WAIT'] === '600'
        && $process->environment['INSTAGRAM_CHALLENGE_METHOD'] === 'email'
        && $process->environment['INSTAGRAM_LOGIN_TIMEOUT'] === '42');
});

it('does not wait for a code when the caller cannot', function () {
    relayScript([relayOkLine()]);

    app(InstagramBrowserLoginClient::class)->login('kasper', 'pw', waitForCode: false);

    Process::assertRan(fn ($process) => $process->timeout === 50 && $process->environment['INSTAGRAM_CODE_WAIT'] === '0');
});

it('clears the waiting state when the login process crashes', function () {
    Process::fake(['*' => function () {
        throw new RuntimeException('boom');
    }]);
    Cache::put('ig:checkpoint:awaiting', 'code', 600);

    $result = app(InstagramBrowserLoginClient::class)->login('kasper', 'pw');

    expect($result->status)->toBe(InstagramLoginStatus::Unknown)
        ->and(Cache::get('ig:checkpoint:awaiting'))->toBeNull()
        ->and(relayDms()[0][1])->toContain('crashed');
});

it('writes a handed-over code to the code file, digits only and private', function () {
    $challenge = app(InstagramCheckpointChallenge::class);
    Cache::put('ig:checkpoint:awaiting', 'code', 600);

    expect($challenge->handOverCode(' 123456 '))->toContain('Handed the code')
        ->and(file_get_contents($this->codeFile))->toBe('123456')
        ->and(substr(sprintf('%o', fileperms($this->codeFile)), -4))->toBe('0600')
        ->and(glob(dirname($this->codeFile).'/code.*.tmp'))->toBe([]);
});

it('refuses a code that is not 4 to 8 digits and writes nothing', function (string $code) {
    Cache::put('ig:checkpoint:awaiting', 'code', 600);

    expect(app(InstagramCheckpointChallenge::class)->handOverCode($code))->toContain('4 to 8 digits')
        ->and(file_exists($this->codeFile))->toBeFalse();
})->with(['123', '123456789', '12 34 56', 'abcdef', '../../etc']);

it('says so when no login waits for a code', function () {
    expect(app(InstagramCheckpointChallenge::class)->handOverCode('123456'))->toContain('No Instagram login is waiting')
        ->and(file_exists($this->codeFile))->toBeFalse();
});

it('hands a code over with the slash command for admins only', function () {
    Cache::put('ig:checkpoint:awaiting', 'code', 600);

    relayCommand('instagram code 123456', 'UOTHER')->assertJsonPath('text', 'Only n0glemand admins can run `instagram` commands.');
    expect(file_exists($this->codeFile))->toBeFalse();

    relayCommand('instagram code 123456')->assertJsonPath('response_type', 'ephemeral');
    expect(file_get_contents($this->codeFile))->toBe('123456');
});

it('answers the slash command code with the reason when nothing waits, and never logs the code', function () {
    relayCommand('instagram code 123456')->assertJsonPath('text', 'No Instagram login is waiting for a code right now, so I ignored it.');
    relayCommand('instagram code')->assertJsonPath('text', 'Usage: `/n0glemand instagram code <digits>` (4 to 8 digits).');

    Log::shouldNotHaveReceived('info', fn ($message, $context = []) => str_contains(json_encode($context), '123456'));
});

it('lists the code command in help', function () {
    relayCommand('help')->assertJsonPath('text', fn (string $text) => str_contains($text, '/n0glemand instagram code <digits>'));
});

it('shows the driver and whether a login waits in the status', function () {
    relayCommand('instagram status')->assertJsonPath('text', fn (string $text) => str_contains($text, 'Login driver: `browser`')
        && str_contains($text, 'Waiting for a human: no'));

    Cache::put('ig:checkpoint:awaiting', 'code', 600);
    relayCommand('instagram status')->assertJsonPath('text', fn (string $text) => str_contains($text, 'a login waits for a code'));

    Cache::put('ig:checkpoint:awaiting', 'approval', 600);
    relayCommand('instagram status')->assertJsonPath('text', fn (string $text) => str_contains($text, 'approval in the Instagram app'));

    config()->set('services.instagram.login_driver', 'nonsense');
    relayCommand('instagram status')->assertJsonPath('text', fn (string $text) => str_contains($text, 'Login driver: `http`'));
});

it('does not run two refresh logins at once, and a running one is not handed out again', function () {
    $job = new RefreshInstagramLogin;

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->tries)->toBe(1)
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout)
        ->and(config('queue.connections.redis.retry_after'))->toBeGreaterThan(config('horizon.defaults.supervisor-instagram.timeout'));
});

describe('instagram:refresh --if-older-than', function () {
    it('queues the login when there is no cookie file', function () {
        Queue::fake();

        $this->artisan('instagram:refresh', ['--if-older-than' => 72])->assertSuccessful();

        Queue::assertPushed(RefreshInstagramLogin::class, 1);
    });

    it('queues the login when the cookie file is older than the limit', function () {
        Queue::fake();
        $path = app(InstagramCookieJar::class)->cookiesPath();
        File::put($path, 'x');
        touch($path, time() - 73 * 3600);

        $this->artisan('instagram:refresh', ['--if-older-than' => 72])->assertSuccessful();

        Queue::assertPushed(RefreshInstagramLogin::class, 1);
    });

    it('skips a fresh cookie file', function () {
        Queue::fake();
        $path = app(InstagramCookieJar::class)->cookiesPath();
        File::put($path, 'x');
        touch($path, time() - 71 * 3600);

        $this->artisan('instagram:refresh', ['--if-older-than' => 72])->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('does nothing without credentials and rejects a bad number', function () {
        Queue::fake();

        $this->artisan('instagram:refresh', ['--if-older-than' => 'soon'])->assertFailed();

        config()->set('services.instagram.password', null);
        $this->artisan('instagram:refresh', ['--if-older-than' => 72])->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('still logs in at once without the option', function () {
        Queue::fake();
        relayScript([relayOkLine()]);

        $this->artisan('instagram:refresh')->assertSuccessful();

        Queue::assertNothingPushed();
    });
});
