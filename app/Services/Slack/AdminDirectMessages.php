<?php

namespace App\Services\Slack;

use App\Jobs\Slack\CheckInstagramFromSlack;
use App\Jobs\Slack\RefreshInstagramCookiesFromSlack;
use App\Services\Instagram\InstagramCheckpointChallenge;
use App\Services\Instagram\InstagramStatusReport;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Direct messages to the bot. Only admins (`services.slack.admin_user_ids`)
 * may talk to it here: `instagram status|check|refresh`, with or without a
 * leading `/n0glemand`, and a bare 4 to 8 digit message, which is taken as an
 * Instagram checkpoint code. Replies go to the DM. No channel handler runs.
 */
class AdminDirectMessages
{
    public function __construct(
        private SlackClient $slack,
        private InstagramStatusReport $status,
        private InstagramCheckpointChallenge $checkpoint,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): void
    {
        $channel = (string) ($event['channel'] ?? '');
        $userId = (string) ($event['user'] ?? '');

        if ($channel === '') {
            return;
        }

        if ($userId === '' || ! in_array($userId, (array) config('services.slack.admin_user_ids', []), true)) {
            Log::warning('slack.dm.refused', ['user_id' => $userId]);
            $this->say($channel, 'Only n0glemand admins can talk to me in a direct message.');

            return;
        }

        $text = trim((string) ($event['text'] ?? ''));

        if (preg_match('/^\d{4,8}$/', $text) === 1) {
            $this->say($channel, $this->code($text));

            return;
        }

        $action = $this->instagramAction($text);

        Log::info('slack.dm.received', ['user_id' => $userId, 'action' => $action]);

        match ($action) {
            'status' => $this->say($channel, $this->status->text()),
            'check' => $this->dispatchAndAck(
                $channel,
                ':hourglass_flowing_sand: Checking the Instagram cookies, the result follows here.',
                fn () => CheckInstagramFromSlack::dispatch('', $channel),
            ),
            'refresh' => $this->dispatchAndAck(
                $channel,
                ':hourglass_flowing_sand: Refreshing the Instagram login, the result follows here.',
                fn () => RefreshInstagramCookiesFromSlack::dispatch('', $channel),
            ),
            default => $this->say($channel, implode("\n", [
                '*n0glemand direct messages (admins only)*',
                '• `instagram status`: cookie file age and login cooldown',
                '• `instagram check`: check that Instagram still accepts the cookies',
                '• `instagram refresh`: force a fresh Instagram login',
                '• a bare 4 to 8 digit code: the Instagram checkpoint code, when I asked for one',
            ])),
        };
    }

    private function instagramAction(string $text): ?string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('#^/?n0glemand\s+#', '', $text) ?? $text;

        if (preg_match('/^instagram\s+(status|check|refresh)$/', $text, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function code(string $code): string
    {
        if (! $this->checkpoint->isAwaitingCode()) {
            return 'No Instagram checkpoint is waiting for a code right now, so I ignored it.';
        }

        $this->checkpoint->receiveCode($code);

        return ":warning: Got the code, but I can't submit it to Instagram: the checkpoint needs a browser. "
            .'Confirm the challenge on instagram.com, then send `instagram refresh`. I only keep the code for 10 minutes.';
    }

    private function dispatchAndAck(string $channel, string $ack, callable $dispatch): void
    {
        $dispatch();
        $this->say($channel, $ack);
    }

    private function say(string $channel, string $text): void
    {
        try {
            $this->slack->postMessage($channel, $text);
        } catch (Throwable $e) {
            Log::error('slack.dm.reply_failed', ['exception' => $e->getMessage()]);
        }
    }
}
