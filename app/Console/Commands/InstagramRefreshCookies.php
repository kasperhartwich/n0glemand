<?php

namespace App\Console\Commands;

use App\Services\Instagram\InstagramCookieJar;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('instagram:refresh {--username=* : Only refresh cookies for these usernames}')]
#[Description('Force a fresh Instagram login for every configured account and write cookies to disk.')]
class InstagramRefreshCookies extends Command
{
    public function handle(InstagramCookieJar $jar): int
    {
        $only = array_filter((array) $this->option('username'));
        $accounts = $jar->accounts();

        if ($accounts === []) {
            $this->warn('No Instagram accounts configured. Set INSTAGRAM_ACCOUNTS in .env.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($accounts as $account) {
            if ($only !== [] && ! in_array($account['username'], $only, true)) {
                continue;
            }

            if ($jar->refresh($account['username'], $account['password'])) {
                $this->info(sprintf('ok     %s', $account['username']));
            } else {
                $failures++;
                $this->error(sprintf('failed %s', $account['username']));
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
