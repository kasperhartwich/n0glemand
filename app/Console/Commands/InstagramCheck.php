<?php

namespace App\Console\Commands;

use App\Services\Instagram\InstagramCheckResult;
use App\Services\Instagram\InstagramSessionCheck;
use App\Services\Slack\SlackClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('instagram:check {--notify : Send a Slack DM to every admin when the check fails}')]
#[Description('Check that Instagram still accepts the cookies, without forcing a login.')]
class InstagramCheck extends Command
{
    public function handle(InstagramSessionCheck $check, SlackClient $slack): int
    {
        $result = $check->run();

        if ($result->isOk()) {
            $this->info("ok     {$result->message}");

            return self::SUCCESS;
        }

        $this->error("failed {$result->status->value}: {$result->message}");

        if ($this->option('notify')) {
            $this->notifyAdmins($slack, $result);
        }

        return self::FAILURE;
    }

    private function notifyAdmins(SlackClient $slack, InstagramCheckResult $result): void
    {
        $text = ":x: *Instagram check failed* (`{$result->status->value}`)\n{$result->message}\n"
            .'Fix: run `/n0glemand instagram refresh`, or export fresh cookies to the cookie file on the server.';

        foreach ((array) config('services.slack.admin_user_ids', []) as $adminId) {
            try {
                $slack->postMessage((string) $adminId, $text);
            } catch (Throwable $e) {
                Log::error('slack.ig.healthcheck.dm_failed', [
                    'user_id' => $adminId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}
