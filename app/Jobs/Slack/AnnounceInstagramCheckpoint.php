<?php

namespace App\Jobs\Slack;

use App\Services\Instagram\InstagramCheckpointChallenge;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DMs the admins about an Instagram checkpoint. Queued so the Slack calls
 * never sit inside the time budget of the job whose login hit the checkpoint.
 */
class AnnounceInstagramCheckpoint implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(InstagramCheckpointChallenge $challenge): void
    {
        $challenge->announce();
    }

    public function failed(Throwable $e): void
    {
        Log::error('slack.ig.checkpoint.announce_failed', ['exception' => $e->getMessage()]);
    }
}
