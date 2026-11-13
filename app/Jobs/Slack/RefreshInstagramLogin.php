<?php

namespace App\Jobs\Slack;

use App\Services\Instagram\InstagramCookieJar;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Logs in to Instagram on the `instagram` queue, so a download job never
 * waits for a login. Unique: many reels failing on the same dead cookies
 * queue one login. A cooldown after a failed login is respected (the Slack
 * `instagram refresh` command is the override).
 */
class RefreshInstagramLogin implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Covers a contended login that waits for a code: the lock wait, the
     * longest login (browser timeout plus the code wait plus its 10 s stop
     * grace) and the rest, at least 30 s to spare. It stays below Horizon's
     * timeout for the `instagram` queue, which stays below the queue's
     * retry_after, so Redis never hands a running login out a second time
     * (see the budget test).
     */
    public int $timeout = 800;

    /**
     * Seconds the uniqueness lock outlives a job that died without releasing
     * it: longer than the job may run, or a second login could be queued
     * while the first still waits for its code.
     */
    public int $uniqueFor = 900;

    public function __construct()
    {
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
        if ($jar->credentials() === null) {
            Log::warning('instagram.refresh.missing_credentials');

            return;
        }

        $cooldown = $jar->cooldownStatus();

        if ($cooldown !== null) {
            Log::info('instagram.refresh.skipped_cooldown', ['status' => $cooldown]);

            return;
        }

        $status = $jar->refreshStatus();

        Log::info('instagram.refresh.finished', ['status' => $status?->value ?? 'lock_timeout']);
    }

    public function failed(Throwable $e): void
    {
        Log::error('instagram.refresh.job_failed', ['exception' => $e->getMessage()]);
    }
}
