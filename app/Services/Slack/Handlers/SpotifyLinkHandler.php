<?php

namespace App\Services\Slack\Handlers;

use App\Jobs\Slack\PostSonglinkToThread;
use App\Services\Slack\Handlers\Contracts\MessageHandler;
use Illuminate\Support\Collection;

class SpotifyLinkHandler implements MessageHandler
{
    public const URL_REGEX = '~(?:https?://open\.spotify\.com/(?:intl-[a-z]{2}/)?(track|album|playlist|episode|show)/([A-Za-z0-9]+)|spotify:(track|album|playlist|episode|show):([A-Za-z0-9]+))~i';

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
        foreach ($this->extractUrls($event) as $url) {
            PostSonglinkToThread::dispatch($url, $channel, $threadTs);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @return Collection<int, string>
     */
    protected function extractUrls(array $event): Collection
    {
        $text = $this->stripSlackLinkBrackets((string) ($event['text'] ?? ''));

        preg_match_all(self::URL_REGEX, $text, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(function (array $m): array {
                $type = strtolower($m[1] !== '' ? $m[1] : ($m[3] ?? ''));
                $id = $m[2] !== '' ? $m[2] : ($m[4] ?? '');

                return ['type' => $type, 'id' => $id];
            })
            ->filter(fn (array $item) => $item['type'] !== '' && $item['id'] !== '')
            ->unique(fn (array $item) => $item['type'].':'.$item['id'])
            ->map(fn (array $item) => "https://open.spotify.com/{$item['type']}/{$item['id']}")
            ->values();
    }

    protected function stripSlackLinkBrackets(string $text): string
    {
        return (string) preg_replace('/<(https?:\/\/[^|>]+|spotify:[^|>]+)(?:\|[^>]*)?>/i', '$1', $text);
    }
}
