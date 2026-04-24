<?php

namespace App\Jobs\Slack;

use App\Services\Instagram\InstagramCookieJar;
use App\Services\Slack\SlackClient;
use Illuminate\Contracts\Process\ProcessResult;
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

    public function handle(SlackClient $slack, InstagramCookieJar $jar): void
    {
        $tmpDir = storage_path('app/slack-ig/'.(string) Str::uuid());

        File::makeDirectory($tmpDir, 0755, true, true);

        try {
            [$result, $pickedUsername] = $this->runYtDlpWithRotation($jar, $tmpDir);

            if ($result === null || $result->failed()) {
                Log::warning('slack.ig.ytdlp_failed', [
                    'url' => $this->instagramUrl,
                    'exit_code' => $result?->exitCode(),
                    'stderr' => $result?->errorOutput(),
                    'ig_username' => $pickedUsername,
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

    /**
     * @return array{0: ProcessResult|null, 1: string|null}
     */
    private function runYtDlpWithRotation(InstagramCookieJar $jar, string $tmpDir): array
    {
        $excluded = null;
        $lastResult = null;
        $lastUsername = null;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $pick = $jar->pickCookies($excluded);
            $cookiesPath = $pick['path'] ?? (string) config('services.slack.ytdlp_cookies', '');
            $username = $pick['username'] ?? null;

            $result = $this->runYtDlp($cookiesPath, $tmpDir);

            $lastResult = $result;
            $lastUsername = $username;

            if ($result->successful()) {
                return [$result, $username];
            }

            if ($username !== null && $this->looksLikeAuthFailure($result->errorOutput())) {
                $jar->markInvalid($username, 'yt-dlp: '.trim(Str::limit($result->errorOutput(), 200)));
                $excluded = $username;

                continue;
            }

            break;
        }

        return [$lastResult, $lastUsername];
    }

    private function runYtDlp(string $cookiesPath, string $tmpDir): ProcessResult
    {
        $command = [
            (string) config('services.slack.ytdlp_binary', 'yt-dlp'),
            '--no-playlist',
            '--no-warnings',
            '--quiet',
            '--max-filesize', '900M',
            '--output', '%(id)s.%(ext)s',
            '--restrict-filenames',
        ];

        if ($cookiesPath !== '' && is_file($cookiesPath)) {
            $command[] = '--cookies';
            $command[] = $cookiesPath;
        }

        $command[] = $this->instagramUrl;

        return Process::path($tmpDir)
            ->timeout((int) config('services.slack.ytdlp_timeout', 120))
            ->run($command);
    }

    private function looksLikeAuthFailure(string $stderr): bool
    {
        return (bool) preg_match('/login[\s_-]?required|rate[\s_-]?limit|Requested content is not available|Restricted Video|challenge/i', $stderr);
    }

    public function failed(Throwable $e): void
    {
        Log::error('slack.ig.job_failed', [
            'url' => $this->instagramUrl,
            'exception' => $e->getMessage(),
        ]);
    }
}
