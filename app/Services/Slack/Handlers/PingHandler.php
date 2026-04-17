<?php

namespace App\Services\Slack\Handlers;

use App\Jobs\Slack\PostPingReply;
use App\Services\Slack\Handlers\Contracts\MessageHandler;

class PingHandler implements MessageHandler
{
    /**
     * @param  array<string, mixed>  $event
     */
    public function matches(array $event): bool
    {
        return strtolower(trim((string) ($event['text'] ?? ''))) === 'ping';
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event, string $channel, string $threadTs, string $userId): void
    {
        PostPingReply::dispatch($channel, $threadTs, 'pong :table_tennis_paddle_and_ball:');
    }
}
