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

    /**
     * Must cover the worst case of one attempt: one yt-dlp run (it never logs
     * in: on an auth failure it queues RefreshInstagramLogin and releases) and
     * the three upload requests (100 s with the config in services.php), leave
     * at least 30 s for filesystem and PHP overhead, and stay below the
     * queue's retry_after. A worker that hits it exits without running `finally`, so `slack:prune-ig-downloads` cleans up.
     */
    public int $timeout = 240;

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
            $result = $this->runYtDlpWithRetry($jar, $tmpDir);

            if ($result === null) {
                Log::info('slack.ig.released_for_refresh', ['url' => $this->instagramUrl]);

                return;
            }

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

    /**
     * Runs yt-dlp once with the cookies that exist. When it fails like an
     * expired session and a login can fix it, this attempt ends by queueing
     * the (unique) refresh and releasing itself once: no login happens here.
     * Returns null when the job was released.
     */
    private function runYtDlpWithRetry(InstagramCookieJar $jar, string $tmpDir): ?ProcessResult
    {
        $jarPath = $jar->currentCookiesPath();
        $seenModifiedAt = $jarPath !== null ? $jar->cookiesModifiedAt() : null;
        $cookiesPath = $jarPath ?? (string) config('services.slack.ytdlp_cookies', '');

        $result = $this->runYtDlp($cookiesPath, $tmpDir);

        if ($result->successful() || ! $this->looksLikeAuthFailure($result->errorOutput())) {
            return $result;
        }

        if ($jar->credentials() === null || $this->attempts() > 1) {
            return $result;
        }

        if ($jar->cooldownStatus() !== null) {
            Log::warning('slack.ig.refresh_cooldown', ['url' => $this->instagramUrl, 'status' => $jar->cooldownStatus()]);

            return $result;
        }

        $reason = 'yt-dlp: '.trim(Str::limit($result->errorOutput(), 200));

        // A login that finished meanwhile already replaced the cookies this run used: retry only.
        if ($jarPath === null || $jar->markInvalid($reason, $seenModifiedAt)) {
            RefreshInstagramLogin::dispatch();
        }

        $this->release((int) config('services.instagram.refresh_retry_delay', 120));

        return null;
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
            ->timeout((int) config('services.slack.ytdlp_timeout', 40))
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
