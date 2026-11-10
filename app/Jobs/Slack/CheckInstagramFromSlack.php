<?php

namespace App\Jobs\Slack;

use App\Services\Instagram\InstagramSessionCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckInstagramFromSlack implements ShouldQueue
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

    public function handle(InstagramSessionCheck $check): void
    {
        $result = $check->run();

        $this->reply($result->isOk()
            ? ":white_check_mark: Instagram check ok: {$result->message}"
            : ":x: Instagram check failed (`{$result->status->value}`): {$result->message}");
    }

    public function failed(Throwable $e): void
    {
        Log::error('slack.instagram_check.job_failed', ['exception' => $e->getMessage()]);

        $this->reply(':x: Instagram check crashed. See `/n0glemand logs`.');
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
            Log::warning('slack.instagram_check.reply_failed', ['exception' => $e->getMessage()]);
        }
    }
}
