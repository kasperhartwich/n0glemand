<?php

namespace App\Jobs\Slack;

use App\Services\Slack\SlackClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostBangCommandReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 15;

    public function __construct(
        public string $channel,
        public string $threadTs,
        public string $text,
    ) {}

    public function handle(SlackClient $slack): void
    {
        $slack->postMessage($this->channel, $this->text, $this->threadTs);
    }

    public function failed(Throwable $e): void
    {
        Log::error('slack.bang.job_failed', [
            'channel' => $this->channel,
            'exception' => $e->getMessage(),
        ]);
    }
}
