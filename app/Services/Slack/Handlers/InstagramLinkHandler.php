<?php

namespace App\Services\Slack\Handlers;

use App\Jobs\Slack\UploadInstagramMediaToThread;
use App\Services\Slack\Handlers\Contracts\MessageHandler;
use Illuminate\Support\Collection;

class InstagramLinkHandler implements MessageHandler
{
    public const URL_REGEX = '~https?://(?:www\.)?instagram\.com/(?:reel|reels|p|tv)/([A-Za-z0-9_-]+)~i';

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
            UploadInstagramMediaToThread::dispatch($url, $channel, $threadTs);
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
            ->unique(fn (array $m) => strtolower($m[1]))
            ->map(fn (array $m) => $m[0])
            ->values();
    }

    protected function stripSlackLinkBrackets(string $text): string
    {
        return (string) preg_replace('/<(https?:\/\/[^|>]+)(?:\|[^>]*)?>/i', '$1', $text);
    }
}
