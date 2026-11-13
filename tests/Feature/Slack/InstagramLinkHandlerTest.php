<?php

use App\Jobs\Slack\CheckInstagramFromSlack;
use App\Jobs\Slack\RefreshInstagramCookiesFromSlack;
use App\Jobs\Slack\RefreshInstagramLogin;
use App\Jobs\Slack\UploadInstagramMediaToThread;
use App\Services\Instagram\InstagramCookieJar;
use App\Services\Slack\Handlers\InstagramLinkHandler;
use App\Services\Slack\SlackClient;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('services.slack.bot_token', 'xoxb-test');
    config()->set('services.slack.api_base', 'https://slack.test/api');
    config()->set('services.slack.max_upload_bytes', 900 * 1024 * 1024);
    config()->set('services.slack.ytdlp_binary', 'yt-dlp');
    config()->set('services.instagram.username', null);
    config()->set('services.instagram.password', null);

    Cache::flush();
});

afterEach(function () {
    Str::createUuidsNormally();
});

it('detects instagram URLs across supported shapes', function (string $text, bool $expected) {
    $handler = app(InstagramLinkHandler::class);

    expect($handler->matches(['text' => $text]))->toBe($expected);
})->with([
    'reel' => ['https://www.instagram.com/reel/ABCdef123/', true],
    'reels plural' => ['https://instagram.com/reels/XYZ_abc-7/', true],
    'post' => ['https://instagram.com/p/Cabc123_-/', true],
    'tv' => ['https://www.instagram.com/tv/Tabc456/', true],
    'slack-wrapped' => ['<https://www.instagram.com/reel/ABC/|Instagram>', true],
    'reel with ?igsh= share param' => ['https://www.instagram.com/reel/DXJYt_4DW-y/?igsh=c3NlNTI3OGgycnhi', true],
    'slack-wrapped with ?igsh= share param' => ['<https://www.instagram.com/reel/DXJYt_4DW-y/?igsh=c3NlNTI3OGgycnhi>', true],
    'scheme-less reels' => ['instagram.com/reels/DfjiO_fCeaq', true],
    'scheme-less with www' => ['look www.instagram.com/p/Cabc123_-/', true],
    'slack italics' => ['_<https://www.instagram.com/reel/ABC/>_', true],
    'lookalike host' => ['notinstagram.com/reel/abc', false],
    'non-ig' => ['https://example.com/reel/abc', false],
    'ig stories (unsupported)' => ['https://instagram.com/stories/someone/1234', false],
    'no URL' => ['hi', false],
]);

it('dispatches a job per unique instagram shortcode', function () {
    Queue::fake();

    $handler = app(InstagramLinkHandler::class);
    $handler->handle([
        'text' => 'dupe https://www.instagram.com/reel/ABC123/ and https://instagram.com/reel/ABC123/ and https://instagram.com/p/ZZZ/',
    ], channel: 'C1', threadTs: '111.222', userId: 'U1');

    Queue::assertPushed(UploadInstagramMediaToThread::class, 2);
});

it('downloads via yt-dlp and uploads to slack on success', function () {
    Str::createUuidsUsing(fn () => 'fixed-uuid-happy');
    $tmpDir = storage_path('app/slack-ig/fixed-uuid-happy');
    File::ensureDirectoryExists($tmpDir);
    File::put($tmpDir.'/ABCdef123.mp4', str_repeat('x', 1024));

    Process::fake();
    Http::fake([
        'slack.test/api/files.getUploadURLExternal*' => Http::response([
            'ok' => true,
            'upload_url' => 'https://files.test/upload/1',
            'file_id' => 'F1',
        ]),
        'https://files.test/upload/1' => Http::response('OK'),
        'slack.test/api/files.completeUploadExternal' => Http::response(['ok' => true]),
    ]);

    (new UploadInstagramMediaToThread(
        instagramUrl: 'https://www.instagram.com/reel/ABCdef123/',
        channel: 'C42',
        threadTs: '1700000000.000100',
    ))->handle(app(SlackClient::class), app(InstagramCookieJar::class));

    Process::assertRan(
        fn ($process) => is_array($process->command) && in_array($process->command[0], ['yt-dlp'], true),
    );

    Http::assertSent(fn (Request $req) => str_contains($req->url(), 'files.getUploadURLExternal'));
    Http::assertSent(fn (Request $req) => $req->url() === 'https://files.test/upload/1');
    Http::assertSent(fn (Request $req) => str_contains($req->url(), 'files.completeUploadExternal'));

    expect(File::exists($tmpDir))->toBeFalse();
});

it('aborts without calling slack when yt-dlp exits non-zero', function () {
    Str::createUuidsUsing(fn () => 'fixed-uuid-fail');

    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'login required', exitCode: 1),
    ]);
    Http::fake();

    (new UploadInstagramMediaToThread(
        instagramUrl: 'https://www.instagram.com/reel/nope/',
        channel: 'C42',
        threadTs: '1700000000.000100',
    ))->handle(app(SlackClient::class), app(InstagramCookieJar::class));

    Http::assertNothingSent();
});

it('rejects files larger than the configured max', function () {
    Str::createUuidsUsing(fn () => 'fixed-uuid-big');
    $tmpDir = storage_path('app/slack-ig/fixed-uuid-big');
    File::ensureDirectoryExists($tmpDir);
    File::put($tmpDir.'/huge.mp4', str_repeat('x', 2048));
    config()->set('services.slack.max_upload_bytes', 1024);

    Process::fake();
    Http::fake();

    (new UploadInstagramMediaToThread(
        instagramUrl: 'https://www.instagram.com/reel/big/',
        channel: 'C42',
        threadTs: '1700000000.000100',
    ))->handle(app(SlackClient::class), app(InstagramCookieJar::class));

    Http::assertNothingSent();
});

it('passes the jar-supplied cookies file to yt-dlp and leaves it in place', function () {
    Str::createUuidsUsing(fn () => 'fixed-uuid-jar');
    $tmpDir = storage_path('app/slack-ig/fixed-uuid-jar');
    File::ensureDirectoryExists($tmpDir);
    File::put($tmpDir.'/ABC.mp4', str_repeat('x', 128));

    $jar = app(InstagramCookieJar::class);
    $cookiesPath = $jar->cookiesPath();
    File::ensureDirectoryExists(dirname($cookiesPath));
    File::put($cookiesPath, "# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t0\tsessionid\tseeded\n");

    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    config()->set('services.slack.ytdlp_cookies', '/should/not/be/used.txt');

    Process::fake();
    Http::fake([
        'slack.test/api/files.getUploadURLExternal*' => Http::response([
            'ok' => true, 'upload_url' => 'https://files.test/upload/jar', 'file_id' => 'FJ',
        ]),
        'https://files.test/upload/jar' => Http::response('OK'),
        'slack.test/api/files.completeUploadExternal' => Http::response(['ok' => true]),
    ]);

    (new UploadInstagramMediaToThread(
        instagramUrl: 'https://www.instagram.com/reel/ABC/',
        channel: 'C42',
        threadTs: '1700000000.000100',
    ))->handle(app(SlackClient::class), $jar);

    Process::assertRan(function ($process) use ($cookiesPath) {
        $idx = array_search('--cookies', $process->command, true);

        return $idx !== false && ($process->command[$idx + 1] ?? null) === $cookiesPath;
    });

    expect(File::exists($cookiesPath))->toBeTrue();
});

function seedJarCookies(string $session = 'stale'): string
{
    $path = app(InstagramCookieJar::class)->cookiesPath();
    File::ensureDirectoryExists(dirname($path));
    File::put($path, "# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t0\tsessionid\t{$session}\n");

    return $path;
}

function uploadJobOnAttempt(int $attempt, ?int $expectedReleaseDelay = null): UploadInstagramMediaToThread
{
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn($attempt);

    if ($expectedReleaseDelay === null) {
        $queueJob->shouldNotReceive('release');
    } else {
        $queueJob->shouldReceive('release')->once()->with($expectedReleaseDelay);
    }

    $job = new UploadInstagramMediaToThread('https://www.instagram.com/reel/stale/', 'C42', '1700000000.000100');
    $job->setJob($queueJob);

    return $job;
}

it('never logs in: on an auth failure it invalidates, queues one refresh and releases itself', function () {
    Queue::fake();
    Http::fake();
    Process::fake(['*' => Process::result(output: '', errorOutput: 'ERROR: [Instagram] login_required: ...', exitCode: 1)]);
    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    $cookiesPath = seedJarCookies();

    uploadJobOnAttempt(1, 120)->handle(app(SlackClient::class), app(InstagramCookieJar::class));

    expect(File::exists($cookiesPath))->toBeFalse();
    Queue::assertPushed(RefreshInstagramLogin::class, 1);
    Queue::assertPushedOn('instagram', RefreshInstagramLogin::class);
    Http::assertNothingSent();
    Process::assertRanTimes(fn () => true, 1);
});

it('does not loop: on the retry attempt an auth failure only logs', function () {
    Queue::fake();
    Http::fake();
    Process::fake(['*' => Process::result(output: '', errorOutput: 'ERROR: [Instagram] login_required', exitCode: 1)]);
    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    $cookiesPath = seedJarCookies();

    uploadJobOnAttempt(2)->handle(app(SlackClient::class), app(InstagramCookieJar::class));

    Queue::assertNothingPushed();
    expect(File::exists($cookiesPath))->toBeTrue();
    Http::assertNothingSent();
});

it('queues one refresh for many failing reels', function () {
    Queue::fake();
    Process::fake(['*' => Process::result(output: '', errorOutput: 'login_required', exitCode: 1)]);
    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    config()->set('services.instagram.refresh_retry_delay', 120);

    // Each failing reel dispatches the refresh; ShouldBeUnique makes the queue keep one (the fake honors it).
    expect(RefreshInstagramLogin::class)->toImplement(ShouldBeUnique::class);

    foreach (range(1, 3) as $i) {
        seedJarCookies();
        uploadJobOnAttempt(1, 120)->handle(app(SlackClient::class), app(InstagramCookieJar::class));
    }

    Queue::assertPushed(RefreshInstagramLogin::class, 1);
});

it('does not queue a refresh while the login cooldown is active', function () {
    Queue::fake();
    Process::fake(['*' => Process::result(output: '', errorOutput: 'login_required', exitCode: 1)]);
    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    Cache::put('ig:login:cooldown', 'checkpoint_required', 300);
    seedJarCookies();

    uploadJobOnAttempt(1)->handle(app(SlackClient::class), app(InstagramCookieJar::class));

    Queue::assertNothingPushed();
});

it('does not touch cookies a refresh replaced while yt-dlp ran, and only retries', function () {
    Queue::fake();
    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');
    $cookiesPath = seedJarCookies();

    Process::fake(function () use ($cookiesPath) {
        File::put($cookiesPath, "sessionid\tFRESH\n");
        touch($cookiesPath, time() + 50);

        return Process::result(output: '', errorOutput: 'login_required', exitCode: 1);
    });

    uploadJobOnAttempt(1, 120)->handle(app(SlackClient::class), app(InstagramCookieJar::class));

    expect(File::get($cookiesPath))->toContain('FRESH');
    Queue::assertNothingPushed();
});

it('has nothing to refresh without credentials and just logs the failure', function () {
    Queue::fake();
    Process::fake(['*' => Process::result(output: '', errorOutput: 'login_required', exitCode: 1)]);

    uploadJobOnAttempt(1)->handle(app(SlackClient::class), app(InstagramCookieJar::class));

    Queue::assertNothingPushed();
});

it('fits the worst case of one attempt inside the job timeout with 30 s to spare, and the timeout below retry_after', function () {
    $ytdlp = config('services.slack.ytdlp_timeout');
    $upload = 10 + config('services.slack.upload_timeout') + 10;

    $timeout = (new UploadInstagramMediaToThread('u', 'C1', '1.2'))->timeout;

    expect($ytdlp + $upload)->toBeLessThanOrEqual($timeout - 30)
        ->and($timeout)->toBeLessThan(config('queue.connections.redis.retry_after'));
});

it('fits a contended login inside the refresh jobs timeouts with 30 s to spare, and below retry_after', function (object $job) {
    $browserLogin = config('services.instagram.browser_timeout') + config('services.instagram.code_wait') + 10;
    $worst = config('services.instagram.login_lock_wait') + $browserLogin + 10;

    expect($worst)->toBeLessThanOrEqual($job->timeout - 30)
        ->and($job->timeout)->toBeLessThan(config('horizon.defaults.supervisor-instagram.timeout'))
        ->and(config('horizon.defaults.supervisor-instagram.timeout'))->toBeLessThan(config('queue.connections.redis.retry_after'))
        ->and($job->timeout)->toBeLessThan(config('queue.connections.redis.retry_after'))
        ->and($job->queue)->toBe('instagram');
})->with([
    'refresh job' => [fn () => new RefreshInstagramLogin],
    'slack refresh' => [fn () => new RefreshInstagramCookiesFromSlack('https://hooks.slack.test/x')],
]);

it('fits the session check worst case inside the CheckInstagramFromSlack timeout with 30 s to spare', function () {
    $http = (3 + 1) * config('services.instagram.login_timeout') + config('services.slack.ytdlp_timeout');
    $login = config('services.instagram.login_lock_wait') + max(2 * config('services.instagram.login_timeout'), config('services.instagram.browser_timeout') + 10);

    $timeout = (new CheckInstagramFromSlack('https://hooks.slack.test/x'))->timeout;

    expect(max($http, $login))->toBeLessThanOrEqual($timeout - 30)
        ->and($timeout)->toBeLessThan(config('queue.connections.redis.retry_after'));
});

it('prunes download directories older than the max age and keeps fresh ones', function () {
    $old = storage_path('app/slack-ig/old-uuid');
    $fresh = storage_path('app/slack-ig/fresh-uuid');
    File::ensureDirectoryExists($old);
    File::ensureDirectoryExists($fresh);
    File::put($old.'/leftover.mp4', 'x');
    touch($old, time() - 2 * 60 * 60);

    $this->artisan('slack:prune-ig-downloads')->assertSuccessful();

    expect(File::exists($old))->toBeFalse()
        ->and(File::exists($fresh))->toBeTrue();
});

it('treats shortcodes that differ only in case as different posts', function () {
    Queue::fake();

    app(InstagramLinkHandler::class)->handle([
        'text' => 'https://www.instagram.com/reel/AbC123/ and https://www.instagram.com/reel/abc123/',
    ], channel: 'C1', threadTs: '111.222', userId: 'U1');

    Queue::assertPushed(UploadInstagramMediaToThread::class, 2);
});

it('hands yt-dlp a /reel/ URL for a /reels/ input, with or without a scheme', function (string $text, string $expected) {
    Queue::fake();

    app(InstagramLinkHandler::class)->handle(['text' => $text], channel: 'C1', threadTs: '111.222', userId: 'U1');

    Queue::assertPushed(
        UploadInstagramMediaToThread::class,
        fn (UploadInstagramMediaToThread $job) => $job->instagramUrl === $expected,
    );
})->with([
    'reels with scheme' => ['https://www.instagram.com/reels/DfjiO_fCeaq', 'https://www.instagram.com/reel/DfjiO_fCeaq/'],
    'reels without scheme' => ['instagram.com/reels/DfjiO_fCeaq', 'https://www.instagram.com/reel/DfjiO_fCeaq/'],
    'reels with share param' => ['<https://www.instagram.com/reels/DfjiO_fCeaq/?igsh=abc>', 'https://www.instagram.com/reel/DfjiO_fCeaq/'],
    'post keeps its type' => ['https://instagram.com/p/Cabc123/', 'https://www.instagram.com/p/Cabc123/'],
]);
