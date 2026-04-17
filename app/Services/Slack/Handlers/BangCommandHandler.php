<?php

namespace App\Services\Slack\Handlers;

use App\Jobs\Slack\PostBangCommandReply;
use App\Services\Slack\Handlers\Contracts\MessageHandler;

class BangCommandHandler implements MessageHandler
{
    public const PREFIX_REGEX = '/^!n0g(?:\s+(?<subcommand>\S+))?/i';

    /**
     * @param  array<string, mixed>  $event
     */
    public function matches(array $event): bool
    {
        return $this->resolveReply($event) !== null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event, string $channel, string $threadTs, string $userId): void
    {
        $reply = $this->resolveReply($event);

        if ($reply === null) {
            return;
        }

        PostBangCommandReply::dispatch($channel, $threadTs, $reply);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function resolveReply(array $event): ?string
    {
        $text = trim((string) ($event['text'] ?? ''));

        if (! preg_match(self::PREFIX_REGEX, $text, $matches)) {
            return null;
        }

        $subcommand = strtolower($matches['subcommand'] ?? '');

        return match ($subcommand) {
            '', 'help' => 'Hi! I respond to `!n0g ping`. Slack link handlers for Instagram and Spotify are always on.',
            'ping' => 'pong :table_tennis_paddle_and_ball:',
            default => null,
        };
    }
}
