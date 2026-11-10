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

    public function __construct(
        public string $spotifyUrl,
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
        $slack->postMessage($this->channel, 'https://song.link/'.$this->spotifyUrl, $this->threadTs);
    }

    public function failed(Throwable $e): void
    {
        Log::error('slack.songlink.job_failed', [
            'url' => $this->spotifyUrl,
            'exception' => $e->getMessage(),
        ]);
    }
}
