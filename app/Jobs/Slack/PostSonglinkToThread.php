<?php

namespace App\Jobs\Slack;

use App\Services\Slack\SlackClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostSonglinkToThread implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    /**
     * @param  array<int, string>  $urls  Canonical music URLs, one song.link line each.
     */
    public function __construct(
        public array $urls,
        public string $channel,
        public string $threadTs,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [2, 10];
    }

    public function handle(SlackClient $slack): void
    {
        $text = collect($this->urls)
            ->map(fn (string $url): string => 'https://song.link/'.$url)
            ->implode("\n");

        $slack->postMessage($this->channel, $text, $this->threadTs);
    }

    public function failed(Throwable $e): void
    {
        Log::error('slack.songlink.job_failed', [
            'urls' => $this->urls,
            'exception' => $e->getMessage(),
        ]);
    }
}
