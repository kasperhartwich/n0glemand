<?php

namespace App\Jobs\Slack;

use App\Jobs\Slack\Concerns\RepliesToSlack;
use App\Services\Instagram\InstagramCookieJar;
use App\Services\Instagram\InstagramLoginStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshInstagramCookiesFromSlack implements ShouldQueue
{
    use Queueable;
    use RepliesToSlack;

    private const REPLY_FAILED_LOG = 'slack.instagram_refresh.reply_failed';

    public int $tries = 1;

    /**
     * A forced login that waits for a contended login lock and then for a
     * code: the lock wait, the longest login (browser timeout plus the code
     * wait plus its 10 s stop grace) and the Slack reply, at least 30 s to
     * spare, below Horizon's timeout and the queue's retry_after (see the
     * budget test).
     */
    public int $timeout = 800;

    public function __construct(
        public string $responseUrl = '',
        public ?string $dmChannel = null,
    ) {
        $this->onQueue((string) config('services.instagram.queue', 'instagram'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [0];
    }

    public function handle(InstagramCookieJar $jar): void
    {
        $this->reply($this->outcome($jar));
    }

    public function failed(Throwable $e): void
    {
        Log::error('slack.instagram_refresh.job_failed', ['exception' => $e->getMessage()]);

        $this->reply(':x: Instagram refresh crashed. See `/n0glemand logs`.');
    }

    protected function outcome(InstagramCookieJar $jar): string
    {
        if ($jar->credentials() === null) {
            return ':warning: `INSTAGRAM_USERNAME` and `INSTAGRAM_PASSWORD` are not set, nothing to refresh.';
        }

        $status = $jar->refreshStatus();

        return match ($status) {
            InstagramLoginStatus::Ok => ':white_check_mark: Instagram login ok, fresh cookies written.',
            null => ':hourglass: Another Instagram login is already running. Try again in a minute.',
            default => ":x: Instagram login failed: `{$status->value}`. Logins are paused for a few minutes.",
        };
    }
}
