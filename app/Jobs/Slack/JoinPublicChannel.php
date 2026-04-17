<?php

namespace App\Jobs\Slack;

use App\Services\Slack\SlackClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class JoinPublicChannel implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 15;

    public function __construct(public string $channelId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(SlackClient $slack): void
    {
        $slack->joinChannel($this->channelId);
    }

    public function failed(Throwable $e): void
    {
        Log::error('slack.join.job_failed', [
            'channel' => $this->channelId,
            'exception' => $e->getMessage(),
        ]);
    }
}
