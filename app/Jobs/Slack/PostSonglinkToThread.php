<?php

namespace App\Jobs\Slack;

use App\Services\Slack\SlackClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
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
        return [2, 10, 30];
    }

    public function handle(SlackClient $slack): void
    {
        try {
            $response = Http::acceptJson()
                ->timeout(8)
                ->retry(2, 250)
                ->get('https://api.song.link/v1-alpha.1/links', [
                    'url' => $this->spotifyUrl,
                ]);
        } catch (RequestException $e) {
            Log::warning('slack.songlink.http_failed', [
                'url' => $this->spotifyUrl,
                'status' => $e->response?->status(),
            ]);

            if ($e->response && $e->response->clientError()) {
                return;
            }

            throw $e;
        }

        if ($response->clientError()) {
            Log::warning('slack.songlink.client_error', [
                'url' => $this->spotifyUrl,
                'status' => $response->status(),
            ]);

            return;
        }

        if ($response->serverError()) {
            $response->throw();
        }

        $pageUrl = $response->json('pageUrl');

        if (! is_string($pageUrl) || $pageUrl === '') {
            Log::warning('slack.songlink.missing_page_url', ['url' => $this->spotifyUrl]);

            return;
        }

        $slack->postMessage($this->channel, $pageUrl, $this->threadTs);
    }

    public function failed(Throwable $e): void
    {
        Log::error('slack.songlink.job_failed', [
            'url' => $this->spotifyUrl,
            'exception' => $e->getMessage(),
        ]);
    }
}
