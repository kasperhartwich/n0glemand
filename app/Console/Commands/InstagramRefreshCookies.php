<?php

namespace App\Console\Commands;

use App\Services\Instagram\InstagramCookieJar;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('instagram:refresh')]
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

        if ($jar->refresh()) {
            $this->info(sprintf('ok     %s', $credentials['username']));

            return self::SUCCESS;
        }

        $this->error(sprintf('failed %s', $credentials['username']));

        return self::FAILURE;
    }
}
