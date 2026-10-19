<?php

use App\Jobs\Slack\UploadInstagramMediaToThread;
use App\Services\Instagram\InstagramCookieJar;
use App\Services\Slack\Handlers\InstagramLinkHandler;
use App\Services\Slack\SlackClient;
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
    config()->set('services.slack.ytdlp_timeout', 50);
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

it('deletes the cached cookies and re-logs in on retry when yt-dlp reports login_required', function () {
    Str::createUuidsUsing(fn () => 'fixed-uuid-stale');
    $tmpDir = storage_path('app/slack-ig/fixed-uuid-stale');

    $jar = app(InstagramCookieJar::class);
    $cookiesPath = $jar->cookiesPath();
    File::ensureDirectoryExists(dirname($cookiesPath));
    File::put($cookiesPath, "# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t0\tsessionid\tstale\n");

    config()->set('services.instagram.username', 'acct1');
    config()->set('services.instagram.password', 'pw');

    $attempts = 0;
    Process::fake(function () use (&$attempts, $tmpDir) {
        $attempts++;

        if ($attempts === 1) {
            return Process::result(output: '', errorOutput: 'ERROR: [Instagram] login_required: ...', exitCode: 1);
        }

        File::ensureDirectoryExists($tmpDir);
        File::put($tmpDir.'/stale.mp4', str_repeat('x', 128));

        return Process::result(output: '', exitCode: 0);
    });
    Http::fake([
        'https://www.instagram.com/accounts/login/' => Http::response('', 200, [
            'Set-Cookie' => ['csrftoken=tok; Path=/; Domain=.instagram.com'],
        ]),
        'https://www.instagram.com/accounts/login/ajax/' => Http::response(
            ['authenticated' => true, 'user' => true, 'status' => 'ok'],
            200,
            ['Set-Cookie' => ['sessionid=FRESH; Path=/; Domain=.instagram.com; Secure']],
        ),
        'slack.test/api/files.getUploadURLExternal*' => Http::response([
            'ok' => true, 'upload_url' => 'https://files.test/upload/retry', 'file_id' => 'FR',
        ]),
        'https://files.test/upload/retry' => Http::response('OK'),
        'slack.test/api/files.completeUploadExternal' => Http::response(['ok' => true]),
    ]);

    (new UploadInstagramMediaToThread(
        instagramUrl: 'https://www.instagram.com/reel/stale/',
        channel: 'C42',
        threadTs: '1700000000.000100',
    ))->handle(app(SlackClient::class), $jar);

    expect($attempts)->toBe(2);
    Http::assertSent(fn (Request $req) => $req->url() === 'https://www.instagram.com/accounts/login/ajax/');
});

it('fits the worst case of one attempt inside the job timeout, and the timeout below retry_after', function () {
    $ytdlp = 2 * config('services.slack.ytdlp_timeout');
    $logins = 2 * (config('services.instagram.login_lock_wait') + 2 * config('services.instagram.login_timeout'));
    $upload = 10 + config('services.slack.upload_timeout') + 10;

    $timeout = (new UploadInstagramMediaToThread('u', 'C1', '1.2'))->timeout;

    expect($ytdlp + $logins + $upload)->toBeLessThan($timeout)
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
