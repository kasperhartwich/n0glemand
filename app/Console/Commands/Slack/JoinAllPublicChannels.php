<?php

namespace App\Console\Commands\Slack;

use App\Services\Slack\SlackClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('slack:join-all-public {--sleep=1 : Seconds to sleep between join calls to respect rate limits}')]
#[Description('Join every non-archived public channel in the workspace.')]
class JoinAllPublicChannels extends Command
{
    public function handle(SlackClient $slack): int
    {
        $cursor = null;
        $joined = 0;
        $already = 0;
        $failed = 0;
        $sleep = max(0, (int) $this->option('sleep'));

        do {
            $page = $slack->listPublicChannels($cursor);

            foreach ($page['channels'] as $channel) {
                $id = (string) ($channel['id'] ?? '');
                $name = (string) ($channel['name'] ?? $id);

                if ($id === '') {
                    continue;
                }

                try {
                    $result = $slack->joinChannel($id);

                    if (($result['error'] ?? null) === 'already_in_channel') {
                        $already++;
                        $this->line("  ∘ already in #{$name}");
                    } else {
                        $joined++;
                        $this->info("  ✓ joined #{$name}");
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $this->warn("  ✗ #{$name}: {$e->getMessage()}");
                }

                if ($sleep > 0) {
                    sleep($sleep);
                }
            }

            $cursor = $page['next_cursor'] !== '' ? $page['next_cursor'] : null;
        } while ($cursor !== null);

        $this->newLine();
        $this->info("Joined: {$joined}, already in: {$already}, failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
