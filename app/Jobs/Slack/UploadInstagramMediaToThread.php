<?php

namespace App\Jobs\Slack;

use App\Services\Slack\SlackClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

class UploadInstagramMediaToThread implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public string $instagramUrl,
        public string $channel,
        public string $threadTs,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(SlackClient $slack): void
    {
        $tmpDir = storage_path('app/slack-ig/'.(string) Str::uuid());

        File::makeDirectory($tmpDir, 0755, true, true);

        try {
            $command = [
                (string) config('services.slack.ytdlp_binary', 'yt-dlp'),
                '--no-playlist',
                '--no-warnings',
                '--quiet',
                '--max-filesize', '900M',
                '--output', '%(id)s.%(ext)s',
                '--restrict-filenames',
            ];

            $cookies = (string) config('services.slack.ytdlp_cookies', '');

            if ($cookies !== '' && is_file($cookies)) {
                $command[] = '--cookies';
                $command[] = $cookies;
            }

            $command[] = $this->instagramUrl;

            $result = Process::path($tmpDir)
                ->timeout((int) config('services.slack.ytdlp_timeout', 120))
                ->run($command);

            if ($result->failed()) {
                Log::warning('slack.ig.ytdlp_failed', [
                    'url' => $this->instagramUrl,
                    'exit_code' => $result->exitCode(),
                    'stderr' => $result->errorOutput(),
                ]);

                return;
            }

            $files = glob($tmpDir.'/*') ?: [];
            $path = collect($files)->first(fn ($p) => is_file($p));

            if ($path === null) {
                Log::warning('slack.ig.no_output_file', ['url' => $this->instagramUrl]);

                return;
            }

            $maxBytes = (int) config('services.slack.max_upload_bytes', 900 * 1024 * 1024);

            if (filesize($path) > $maxBytes) {
                Log::warning('slack.ig.file_too_large', [
                    'url' => $this->instagramUrl,
                    'bytes' => filesize($path),
                    'max_bytes' => $maxBytes,
                ]);

                return;
            }

            $slack->filesUploadV2(
                channel: $this->channel,
                threadTs: $this->threadTs,
                localPath: $path,
                title: basename($path),
            );
        } finally {
            File::deleteDirectory($tmpDir);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('slack.ig.job_failed', [
            'url' => $this->instagramUrl,
            'exception' => $e->getMessage(),
        ]);
    }
}
