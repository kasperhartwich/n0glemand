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
    'playlist (song.link has no page for it)' => ['https://open.spotify.com/playlist/37i9dQZEVXbLRQDuF5jeBp', false],
    'episode (song.link has no page for it)' => ['https://open.spotify.com/episode/0Q8Zl83i7WhXsaMAA1a5my', false],
    'show (song.link has no page for it)' => ['https://open.spotify.com/show/5as3aKmN2k11yfDDDSrvaZ', false],
    'intl-en' => ['https://open.spotify.com/intl-en/track/4cOdK2wGLETKBW3PvgPWqT', true],
    'spotify URI' => ['spotify:track:4cOdK2wGLETKBW3PvgPWqT', true],
    'slack-wrapped' => ['<https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT>', true],
    'track with ?si= share param' => ['https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT?si=abc123', true],
    'slack-wrapped with ?si= share param' => ['<https://open.spotify.com/artist/Foo|Artist>', false],
    'artist (song.link has no page for it)' => ['https://open.spotify.com/artist/293vC3Y0NqwjW2g3ioBYN0', false],
    'artist with ?si=' => ['https://open.spotify.com/artist/293vC3Y0NqwjW2g3ioBYN0?si=9xFmFcjiQhC4RLMQIbZWpA', false],
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

it('posts a song.link page for the Spotify URL to the thread', function () {
    Http::fake([
        'slack.test/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1.1']),
    ]);

    $job = new PostSonglinkToThread(
        spotifyUrl: 'https://open.spotify.com/track/xyz',
        channel: 'C42',
        threadTs: '1700000000.000100',
    );

    $job->handle(app(SlackClient::class));

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->url() === 'https://slack.test/api/chat.postMessage'
            && ($body['channel'] ?? null) === 'C42'
            && ($body['text'] ?? null) === 'https://song.link/https://open.spotify.com/track/xyz'
            && ($body['thread_ts'] ?? null) === '1700000000.000100';
    });
});
