<?php

use App\Jobs\Slack\PostSonglinkToThread;
use App\Services\Slack\Handlers\MusicLinkHandler;
use App\Services\Slack\SlackClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('services.slack.bot_token', 'xoxb-test');
    config()->set('services.slack.api_base', 'https://slack.test/api');
});

/**
 * @return array<int, string>
 */
function musicUrls(string $text): array
{
    Queue::fake();

    app(MusicLinkHandler::class)->handle(['text' => $text], channel: 'C1', threadTs: '1.1', userId: 'U1');

    $urls = [];
    Queue::assertPushed(PostSonglinkToThread::class, function ($job) use (&$urls) {
        $urls = $job->urls;

        return true;
    });

    return $urls;
}

it('normalises resolvable links of every service', function (string $text, string $expected) {
    expect(musicUrls($text))->toBe([$expected]);
})->with([
    'spotify track' => ['https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT', 'https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT'],
    'spotify album' => ['https://open.spotify.com/album/2noRn2Aes5aoNVsU6iWThc', 'https://open.spotify.com/album/2noRn2Aes5aoNVsU6iWThc'],
    'spotify intl and ?si=' => ['https://open.spotify.com/intl-da/track/4cOdK2wGLETKBW3PvgPWqT?si=abc', 'https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT'],
    'spotify URI' => ['spotify:album:2noRn2Aes5aoNVsU6iWThc', 'https://open.spotify.com/album/2noRn2Aes5aoNVsU6iWThc'],
    'apple album' => ['https://music.apple.com/us/album/abbey-road-remastered/1441164426', 'https://music.apple.com/us/album/1441164426'],
    'apple track via ?i= keeps the track' => ['https://music.apple.com/dk/album/come-together-remastered/1441164426?i=1441164430&uo=4&app=music', 'https://music.apple.com/dk/album/1441164426?i=1441164430'],
    'apple album without slug' => ['https://music.apple.com/gb/album/1441164426', 'https://music.apple.com/gb/album/1441164426'],
    'apple song' => ['https://music.apple.com/us/song/come-together-remastered/1441164430', 'https://music.apple.com/us/song/1441164430'],
    'apple geo host' => ['https://geo.music.apple.com/dk/album/_/1474815798?i=1474815799&mt=1', 'https://music.apple.com/dk/album/1474815798?i=1474815799'],
    'youtube music' => ['https://music.youtube.com/watch?v=dQw4w9WgXcQ&si=abc&list=RDAMVMdQw4w9WgXcQ', 'https://music.youtube.com/watch?v=dQw4w9WgXcQ'],
    'youtube music v not first' => ['https://music.youtube.com/watch?feature=share&v=dQw4w9WgXcQ', 'https://music.youtube.com/watch?v=dQw4w9WgXcQ'],
    'tidal browse track' => ['https://tidal.com/browse/track/55130631', 'https://tidal.com/track/55130631'],
    'tidal listen album' => ['https://listen.tidal.com/album/55130630', 'https://tidal.com/album/55130630'],
    'deezer track' => ['https://www.deezer.com/track/3135556', 'https://www.deezer.com/track/3135556'],
    'deezer language album' => ['https://www.deezer.com/en/album/302127', 'https://www.deezer.com/album/302127'],
    'amazon album' => ['https://music.amazon.de/albums/B07FSXMG6Q', 'https://music.amazon.com/albums/B07FSXMG6Q'],
    'amazon track via trackAsin' => ['https://music.amazon.co.uk/albums/B07FSXMG6Q?trackAsin=B07FSSGBJV&ref=x', 'https://music.amazon.com/albums/B07FSXMG6Q?trackAsin=B07FSSGBJV'],
    'amazon tracks' => ['https://music.amazon.com/tracks/B07FSSGBJV', 'https://music.amazon.com/tracks/B07FSSGBJV'],
    'soundcloud track' => ['https://soundcloud.com/rick-astley-official/never-gonna-give-you-up-4?utm_source=x', 'https://soundcloud.com/rick-astley-official/never-gonna-give-you-up-4'],
    'soundcloud set' => ['https://soundcloud.com/rick-astley-official/sets/whenever-you-need-somebody', 'https://soundcloud.com/rick-astley-official/sets/whenever-you-need-somebody'],
    'soundcloud mobile host' => ['https://m.soundcloud.com/rick-astley-official/never-gonna-give-you-up-4', 'https://soundcloud.com/rick-astley-official/never-gonna-give-you-up-4'],
    'slack-wrapped with label' => ['<https://music.apple.com/us/album/x/1441164426?i=1441164430|Come Together>', 'https://music.apple.com/us/album/1441164426?i=1441164430'],
    'slack-escaped ampersand' => ['<https://music.youtube.com/watch?si=a&amp;v=dQw4w9WgXcQ>', 'https://music.youtube.com/watch?v=dQw4w9WgXcQ'],
    'italic slack link (tidal)' => ['_<https://tidal.com/track/55130631>_', 'https://tidal.com/track/55130631'],
    'italic slack link (spotify)' => ['_<https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT>_', 'https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT'],
    'italic slack link (soundcloud)' => ['_<https://soundcloud.com/rick-astley-official/never-gonna-give-you-up-4>_', 'https://soundcloud.com/rick-astley-official/never-gonna-give-you-up-4'],
    'bold slack link (youtube music)' => ['*<https://music.youtube.com/watch?v=dQw4w9WgXcQ>*', 'https://music.youtube.com/watch?v=dQw4w9WgXcQ'],
    'in a sentence with a closing bracket' => ['listen (https://tidal.com/browse/track/55130631) now', 'https://tidal.com/track/55130631'],
]);

it('ignores links that song.link cannot resolve and lookalikes', function (string $text) {
    expect(app(MusicLinkHandler::class)->matches(['text' => $text]))->toBeFalse();
})->with([
    'spotify playlist' => 'https://open.spotify.com/playlist/37i9dQZEVXbLRQDuF5jeBp',
    'spotify episode' => 'https://open.spotify.com/episode/0Q8Zl83i7WhXsaMAA1a5my',
    'spotify show' => 'https://open.spotify.com/show/5as3aKmN2k11yfDDDSrvaZ',
    'spotify artist' => '<https://open.spotify.com/artist/293vC3Y0NqwjW2g3ioBYN0?si=9xFm|Artist>',
    'apple playlist' => 'https://music.apple.com/us/playlist/todays-hits/pl.f4d106fed2bd41149aaacabb233eb5eb',
    'apple artist' => 'https://music.apple.com/us/artist/the-beatles/136975',
    'youtube music playlist' => 'https://music.youtube.com/playlist?list=OLAK5uy_kmvRzgTOEh7bI5K6kgKkcyqlHlCEeYDDI',
    'youtube music channel' => 'https://music.youtube.com/channel/UCuAXFkgsw1L7xaCfnd5JJOw',
    'plain youtube' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'tidal playlist' => 'https://tidal.com/browse/playlist/36ea71a8-445e-41a4-82ab-6628c581535d',
    'tidal artist' => 'https://tidal.com/browse/artist/3566483',
    'deezer playlist' => 'https://www.deezer.com/en/playlist/1111143121',
    'deezer short link' => 'https://deezer.page.link/xyz',
    'amazon artist' => 'https://music.amazon.com/artists/B000QJNQ5K/the-beatles',
    'amazon playlist' => 'https://music.amazon.com/playlists/B07XYZ1234',
    'soundcloud profile' => 'https://soundcloud.com/rick-astley-official',
    'soundcloud user sets list' => 'https://soundcloud.com/rick-astley-official/sets',
    'soundcloud user tracks tab' => 'https://soundcloud.com/rick-astley-official/tracks',
    'soundcloud discover' => 'https://soundcloud.com/discover/sets/charts-top:all-music',
    'song.link wrapping a spotify url' => 'https://song.link/https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT',
    'song.link page' => 'https://song.link/i/1441164430',
    'album.link page' => 'https://album.link/d/302127',
    'odesli page' => 'https://odesli.co/abc',
    'redirect carrying a url' => 'https://example.com/r?u=https://tidal.com/browse/track/55130631',
    'lookalike spotify host' => 'https://open.spotify.com.evil.example/track/4cOdK2wGLETKBW3PvgPWqT',
    'lookalike prefix host' => 'https://notmusic.apple.com/us/album/1441164426',
    'lookalike suffix host' => 'https://music.apple.com.evil.example/us/album/1441164426',
    'lookalike tidal' => 'https://faketidal.com/track/55130631',
    'lookalike deezer' => 'https://deezer.com.evil.example/track/3135556',
    'lookalike amazon' => 'https://music.amazon.com.evil.example/albums/B07FSXMG6Q',
    'lookalike soundcloud' => 'https://soundcloud.com.evil.example/a/b',
    'soundcloud stations' => 'https://soundcloud.com/stations/track/foo',
    'soundcloud private link (secret token would be lost)' => 'https://soundcloud.com/rick-astley-official/never-gonna-give-you-up-4/s-AbCd1234',
    'amazon unknown tld' => 'https://music.amazon.xyz/albums/B07FSXMG6Q',
    'non-music' => 'https://example.com/track/abc',
    'no URL' => 'hello there',
]);

it('dispatches one job with every distinct normalised URL in order of appearance', function () {
    expect(musicUrls(
        'a https://tidal.com/browse/track/55130631 b https://open.spotify.com/intl-en/track/4cOdK2wGLETKBW3PvgPWqT'
        .' dupe spotify:track:4cOdK2wGLETKBW3PvgPWqT and https://www.deezer.com/en/album/302127 and https://tidal.com/track/55130631',
    ))->toBe([
        'https://tidal.com/track/55130631',
        'https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT',
        'https://www.deezer.com/album/302127',
    ]);

    Queue::assertPushed(PostSonglinkToThread::class, 1);
});

it('does not dispatch when only song.link URLs are present', function () {
    Queue::fake();

    app(MusicLinkHandler::class)->handle(
        ['text' => 'https://song.link/s/4cOdK2wGLETKBW3PvgPWqT'],
        channel: 'C1',
        threadTs: '1.1',
        userId: 'U1',
    );

    Queue::assertNothingPushed();
});

it('posts one message with a song.link line per URL to the thread', function () {
    Http::fake([
        'slack.test/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1.1']),
    ]);

    $job = new PostSonglinkToThread(
        urls: ['https://open.spotify.com/track/xyz', 'https://tidal.com/track/55130631'],
        channel: 'C42',
        threadTs: '1700000000.000100',
    );

    $job->handle(app(SlackClient::class));

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->url() === 'https://slack.test/api/chat.postMessage'
            && ($body['channel'] ?? null) === 'C42'
            && ($body['text'] ?? null) === "https://song.link/https://open.spotify.com/track/xyz\nhttps://song.link/https://tidal.com/track/55130631"
            && ($body['thread_ts'] ?? null) === '1700000000.000100';
    });
});
