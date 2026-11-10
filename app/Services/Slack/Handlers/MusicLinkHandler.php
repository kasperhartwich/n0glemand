<?php

namespace App\Services\Slack\Handlers;

use App\Jobs\Slack\PostSonglinkToThread;
use App\Services\Slack\Handlers\Concerns\DispatchesOncePerEvent;
use App\Services\Slack\Handlers\Contracts\MessageHandler;
use Illuminate\Support\Collection;

/**
 * Replies once per message with a song.link page for every distinct track or
 * album link of a supported music service. Only the link types that song.link
 * resolves to a real page are matched: playlists, artists, shows and episodes
 * redirect to https://undefined or a not-found page, so they are ignored.
 */
class MusicLinkHandler implements MessageHandler
{
    use DispatchesOncePerEvent;

    /**
     * A link starts at a scheme that is not glued to a preceding path or query,
     * so a song.link URL that wraps a service URL, or a redirect URL that
     * carries one in a parameter, is not matched.
     */
    private const START = '(?<![A-Za-z0-9\/=.?#&-])https?://';

    private const SPOTIFY = '~(?<![A-Za-z0-9\/=.?#&-])(?:https?://open\.spotify\.com/(?:intl-[a-z]{2}/)?(track|album)/([A-Za-z0-9]+)|spotify:(track|album):([A-Za-z0-9]+))~i';

    private const SOUNDCLOUD_RESERVED_USER = 'discover|you|stream|upload|search|charts|settings|pages|terms-of-use|jobs|messages|notifications|feed|tags|popular|people|stations|creators|mobile';

    private const SOUNDCLOUD_RESERVED_ITEM = 'tracks|albums|sets|likes|reposts|popular-tracks|following|followers|comments|spotlight|playlists';

    /**
     * @param  array<string, mixed>  $event
     */
    public function matches(array $event): bool
    {
        return $this->extractUrls($event)->isNotEmpty();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event, string $channel, string $threadTs, string $userId): void
    {
        $urls = $this->extractUrls($event);

        if ($urls->isEmpty()) {
            return;
        }

        $this->dispatchOnce(
            $event,
            'music:'.$urls->implode(' '),
            fn () => PostSonglinkToThread::dispatch($urls->all(), $channel, $threadTs),
        );
    }

    /**
     * The canonical URL of every distinct supported link, in order of appearance.
     *
     * @param  array<string, mixed>  $event
     * @return Collection<int, string>
     */
    protected function extractUrls(array $event): Collection
    {
        $text = $this->stripSlackLinkBrackets((string) ($event['text'] ?? ''));

        return collect($this->patterns())
            ->flatMap(function (array $service) use ($text): array {
                preg_match_all($service['regex'], $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

                $found = [];

                foreach ($matches as $match) {
                    $groups = array_map(fn (array $group): string => $group[0], $match);
                    $url = $service['normalise']($groups);

                    if ($url !== null) {
                        $found[] = ['offset' => $match[0][1], 'url' => $url];
                    }
                }

                return $found;
            })
            ->sortBy('offset')
            ->pluck('url')
            ->unique()
            ->values();
    }

    /**
     * @return array<string, array{regex: string, normalise: callable(array<int, string>): ?string}>
     */
    protected function patterns(): array
    {
        $start = self::START;

        return [
            'spotify' => [
                'regex' => self::SPOTIFY,
                'normalise' => function (array $m): ?string {
                    $type = strtolower($m[1] !== '' ? $m[1] : ($m[3] ?? ''));
                    $id = $m[2] !== '' ? $m[2] : ($m[4] ?? '');

                    return $type !== '' && $id !== '' ? "https://open.spotify.com/{$type}/{$id}" : null;
                },
            ],
            'apple' => [
                'regex' => "~{$start}(?:geo\\.)?music\\.apple\\.com/([a-z]{2})/(album|song)/(?:[^/?#\\s]+/)?(\\d+)(?![A-Za-z0-9])(?:\\?([^\\s#]*))?~i",
                'normalise' => function (array $m): string {
                    $url = 'https://music.apple.com/'.strtolower($m[1]).'/'.strtolower($m[2]).'/'.$m[3];

                    if (strtolower($m[2]) === 'album' && preg_match('/(?:^|&)i=(\d+)/', $m[4] ?? '', $track) === 1) {
                        $url .= '?i='.$track[1];
                    }

                    return $url;
                },
            ],
            'youtube_music' => [
                'regex' => "~{$start}music\\.youtube\\.com/watch\\?(?:[^\\s#]*?&)?v=([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~i",
                'normalise' => fn (array $m): string => 'https://music.youtube.com/watch?v='.$m[1],
            ],
            'tidal' => [
                'regex' => "~{$start}(?:listen\\.|www\\.)?tidal\\.com/(?:browse/)?(track|album)/(\\d+)(?![A-Za-z0-9])~i",
                'normalise' => fn (array $m): string => 'https://tidal.com/'.strtolower($m[1]).'/'.$m[2],
            ],
            'deezer' => [
                'regex' => "~{$start}(?:www\\.)?deezer\\.com/(?:[a-z]{2}/)?(track|album)/(\\d+)(?![A-Za-z0-9])~i",
                'normalise' => fn (array $m): string => 'https://www.deezer.com/'.strtolower($m[1]).'/'.$m[2],
            ],
            'amazon' => [
                'regex' => "~{$start}music\\.amazon\\.(?:com|co\\.uk|de|fr|es|it|co\\.jp|com\\.au|ca|in|com\\.br|com\\.mx)/(albums|tracks)/(B0[0-9A-Z]{8})(?![A-Za-z0-9])(?:\\?([^\\s#]*))?~i",
                'normalise' => function (array $m): string {
                    $type = strtolower($m[1]);
                    $url = "https://music.amazon.com/{$type}/".strtoupper($m[2]);

                    if ($type === 'albums' && preg_match('/(?:^|&)trackAsin=(B0[0-9A-Z]{8})/i', $m[3] ?? '', $track) === 1) {
                        $url .= '?trackAsin='.strtoupper($track[1]);
                    }

                    return $url;
                },
            ],
            'soundcloud' => [
                'regex' => "~{$start}(?:www\\.|m\\.)?soundcloud\\.com/(?!(?:".self::SOUNDCLOUD_RESERVED_USER.')/)([A-Za-z0-9_-]+)/(sets/)?(?!(?:'.self::SOUNDCLOUD_RESERVED_ITEM.')(?![A-Za-z0-9_-]))([A-Za-z0-9_-]++)(?!/s-)~i',
                'normalise' => fn (array $m): string => 'https://soundcloud.com/'.$m[1].'/'.strtolower($m[2] ?? '').$m[3],
            ],
        ];
    }

    /**
     * Unwrap Slack's <url|label> syntax (padded with spaces so italic or bold markers around a link do not stick to it) and decode the &amp; it escapes in URLs.
     */
    protected function stripSlackLinkBrackets(string $text): string
    {
        $text = (string) preg_replace('/<(https?:\/\/[^|>]+|spotify:[^|>]+)(?:\|[^>]*)?>/i', ' $1 ', $text);

        return str_replace('&amp;', '&', $text);
    }
}
