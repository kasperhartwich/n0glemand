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
 * leading `/n0glemand`, `instagram code <digits>`, and a bare 4 to 8 digit
 * message, which is taken as the code a waiting Instagram login asked for
 * (handed to its browser by `InstagramCheckpointChallenge`). Replies go to the DM. No channel handler runs.
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
            $this->say($channel, $this->checkpoint->handOverCode($text));

            return;
        }

        if (preg_match('#^(?:/?n0glemand\s+)?instagram\s+code\s+(\S+)$#i', $text, $matches) === 1) {
            $this->say($channel, $this->checkpoint->handOverCode($matches[1]));

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
                '• `instagram status`: login driver, cookie file age, login cooldown and whether a login waits for a code',
                '• `instagram check`: check that Instagram still accepts the cookies',
                '• `instagram refresh`: force a fresh Instagram login',
                '• a bare 4 to 8 digit code (or `instagram code 123456`): the code a waiting Instagram login asked for',
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
