<?php

namespace App\Services\Slack;

use App\Jobs\Slack\JoinPublicChannel;
use App\Services\Slack\Handlers\Contracts\MessageHandler;

class SlackEventRouter
{
    /**
     * @param  iterable<MessageHandler>  $handlers
     */
    public function __construct(protected iterable $handlers) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function route(array $payload): void
    {
        if (($payload['type'] ?? null) !== 'event_callback') {
            return;
        }

        $event = $payload['event'] ?? [];

        match ($event['type'] ?? null) {
            'message' => $this->routeMessage($event),
            'channel_created' => $this->routeChannelCreated($event),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function routeMessage(array $event): void
    {
        if (isset($event['bot_id']) || ($event['subtype'] ?? null) === 'bot_message') {
            return;
        }

        $channel = (string) ($event['channel'] ?? '');
        $threadTs = (string) ($event['thread_ts'] ?? $event['ts'] ?? '');
        $userId = (string) ($event['user'] ?? '');

        if ($channel === '' || $threadTs === '') {
            return;
        }

        foreach ($this->handlers as $handler) {
            if ($handler->matches($event)) {
                $handler->handle($event, $channel, $threadTs, $userId);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function routeChannelCreated(array $event): void
    {
        $channelId = (string) ($event['channel']['id'] ?? '');

        if ($channelId === '') {
            return;
        }

        JoinPublicChannel::dispatch($channelId);
    }
}
