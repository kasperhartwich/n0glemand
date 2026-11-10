<?php

namespace App\Services\Slack\Handlers;

use App\Jobs\Slack\UploadInstagramMediaToThread;
use App\Services\Slack\Handlers\Concerns\DispatchesOncePerEvent;
use App\Services\Slack\Handlers\Contracts\MessageHandler;
use Illuminate\Support\Collection;

class InstagramLinkHandler implements MessageHandler
{
    use DispatchesOncePerEvent;

    public const URL_REGEX = '~(?<![A-Za-z0-9.@/-])(?:https?://)?(?:www\.)?instagram\.com/(reel|reels|p|tv)/([A-Za-z0-9_-]+)~i';

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
            $this->dispatchOnce($event, 'ig:'.$url, fn () => UploadInstagramMediaToThread::dispatch($url, $channel, $threadTs));
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
            ->unique(fn (array $m) => $m[2])
            ->map(fn (array $m) => $this->canonicalUrl($m[1], $m[2]))
            ->values();
    }

    /**
     * yt-dlp only knows /reel/, not Instagram's /reels/ alias, and needs a scheme.
     */
    protected function canonicalUrl(string $type, string $shortcode): string
    {
        $type = strtolower($type);

        return 'https://www.instagram.com/'.($type === 'reels' ? 'reel' : $type).'/'.$shortcode.'/';
    }

    protected function stripSlackLinkBrackets(string $text): string
    {
        return (string) preg_replace('/<(https?:\/\/[^|>]+)(?:\|[^>]*)?>/i', '$1', $text);
    }
}
