<?php

use App\Jobs\Slack\UploadInstagramMediaToThread;
use App\Services\Slack\Handlers\InstagramLinkHandler;
use App\Services\Slack\SlackClient;
use Illuminate\Http\Client\Request;
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
    config()->set('services.slack.ytdlp_timeout', 120);

    File::deleteDirectory(storage_path('app/slack-ig'));
});

afterEach(function () {
    Str::createUuidsNormally();
    File::deleteDirectory(storage_path('app/slack-ig'));
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
    ))->handle(app(SlackClient::class));

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
    ))->handle(app(SlackClient::class));

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
    ))->handle(app(SlackClient::class));

    Http::assertNothingSent();
});
