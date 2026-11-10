<?php

namespace App\Jobs\Slack;

use App\Services\Instagram\InstagramCookieJar;
use App\Services\Instagram\InstagramLoginStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshInstagramCookiesFromSlack implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public string $responseUrl,
    ) {}

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

    protected function reply(string $text): void
    {
        try {
            Http::timeout(10)->post($this->responseUrl, [
                'response_type' => 'ephemeral',
                'replace_original' => false,
                'text' => $text,
            ]);
        } catch (Throwable $e) {
            Log::warning('slack.instagram_refresh.reply_failed', ['exception' => $e->getMessage()]);
        }
    }
}
