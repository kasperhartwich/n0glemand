<?php

use App\Jobs\Slack\PostSonglinkToThread;
use App\Services\Slack\Handlers\SpotifyLinkHandler;
use App\Services\Slack\SlackClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('services.slack.bot_token', 'xoxb-test');
    config()->set('services.slack.api_base', 'https://slack.test/api');
});

it('detects spotify URLs across supported shapes', function (string $text, bool $expected) {
    $handler = app(SpotifyLinkHandler::class);

    expect($handler->matches(['text' => $text]))->toBe($expected);
})->with([
    'track' => ['https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT', true],
    'album' => ['https://open.spotify.com/album/2noRn2Aes5aoNVsU6iWThc', true],
    'playlist' => ['https://open.spotify.com/playlist/37i9dQZEVXbLRQDuF5jeBp', true],
    'episode' => ['https://open.spotify.com/episode/0Q8Zl83i7WhXsaMAA1a5my', true],
    'artist' => ['https://open.spotify.com/artist/6sFIWsNpZYqfjUpaCgueju', true],
    'intl-en' => ['https://open.spotify.com/intl-en/track/4cOdK2wGLETKBW3PvgPWqT', true],
    'spotify URI' => ['spotify:track:4cOdK2wGLETKBW3PvgPWqT', true],
    'slack-wrapped' => ['<https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT>', true],
    'non-spotify' => ['https://example.com/track/abc', false],
    'no URL' => ['hello there', false],
]);

it('dispatches a job per unique normalized URL', function () {
    Queue::fake();

    $handler = app(SpotifyLinkHandler::class);
    $handler->handle([
        'text' => 'dupes: https://open.spotify.com/intl-en/track/4cOdK2wGLETKBW3PvgPWqT and spotify:track:4cOdK2wGLETKBW3PvgPWqT and a new one https://open.spotify.com/album/2noRn2Aes5aoNVsU6iWThc',
    ], channel: 'C1', threadTs: '111.222', userId: 'U1');

    Queue::assertPushed(PostSonglinkToThread::class, 2);
    Queue::assertPushed(
        PostSonglinkToThread::class,
        fn ($job) => $job->spotifyUrl === 'https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT',
    );
    Queue::assertPushed(
        PostSonglinkToThread::class,
        fn ($job) => $job->spotifyUrl === 'https://open.spotify.com/album/2noRn2Aes5aoNVsU6iWThc',
    );
});

it('posts the songlink pageUrl to the thread on success', function () {
    Http::fake([
        'api.song.link/*' => Http::response(['pageUrl' => 'https://song.link/s/abc']),
        'slack.test/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1.1']),
    ]);

    $job = new PostSonglinkToThread(
        spotifyUrl: 'https://open.spotify.com/track/xyz',
        channel: 'C42',
        threadTs: '1700000000.000100',
    );

    $job->handle(app(SlackClient::class));

    Http::assertSent(function (Request $request) {
        if ($request->url() !== 'https://slack.test/api/chat.postMessage') {
            return false;
        }

        $body = $request->data();

        return ($body['channel'] ?? null) === 'C42'
            && ($body['text'] ?? null) === 'https://song.link/s/abc'
            && ($body['thread_ts'] ?? null) === '1700000000.000100';
    });
});

it('silently gives up on songlink 4xx', function () {
    Http::fake([
        'api.song.link/*' => Http::response(['error' => 'not found'], 404),
    ]);

    $job = new PostSonglinkToThread(
        spotifyUrl: 'https://open.spotify.com/track/xyz',
        channel: 'C42',
        threadTs: '1700000000.000100',
    );

    $job->handle(app(SlackClient::class));

    Http::assertNotSent(
        fn (Request $request) => str_contains($request->url(), 'slack.test/api/chat.postMessage'),
    );
});
