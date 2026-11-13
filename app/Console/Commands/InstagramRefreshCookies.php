<?php

namespace App\Console\Commands;

use App\Jobs\Slack\RefreshInstagramLogin;
use App\Services\Instagram\InstagramCookieJar;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('instagram:refresh {--if-older-than= : Only when the cookie file is missing or older than this many hours; queues the login instead of running it}')]
#[Description('Force a fresh Instagram login and write cookies to disk for yt-dlp.')]
class InstagramRefreshCookies extends Command
{
    public function handle(InstagramCookieJar $jar): int
    {
        $credentials = $jar->credentials();

        if ($credentials === null) {
            $this->warn('INSTAGRAM_USERNAME and INSTAGRAM_PASSWORD are not set in .env.');

            return self::SUCCESS;
        }

        $olderThan = $this->option('if-older-than');

        if ($olderThan !== null) {
            return $this->queueWhenOld($jar, $olderThan);
        }

        if ($jar->refresh()) {
            $this->info(sprintf('ok     %s', $credentials['username']));

            return self::SUCCESS;
        }

        $this->error(sprintf('failed %s', $credentials['username']));

        return self::FAILURE;
    }

    /**
     * The scheduled path: a login can wait minutes for a code, so it goes to
     * the `instagram` queue (unique, one process) instead of running here.
     */
    private function queueWhenOld(InstagramCookieJar $jar, mixed $hours): int
    {
        if (! is_numeric($hours) || (float) $hours < 0) {
            $this->error('--if-older-than takes a number of hours.');

            return self::FAILURE;
        }

        $age = $jar->cookiesAgeSeconds();

        if ($age !== null && $age <= (float) $hours * 3600) {
            $this->info(sprintf('fresh  cookies are %d min old, not older than %s h', intdiv($age, 60), $hours));

            return self::SUCCESS;
        }

        RefreshInstagramLogin::dispatch();

        $this->info($age === null ? 'queued no cookie file' : sprintf('queued cookies are %d h old', intdiv($age, 3600)));

        return self::SUCCESS;
    }
}
