<?php

namespace App\Services\Slack;

use App\Jobs\Slack\JoinPublicChannel;
use App\Services\Slack\Handlers\Contracts\MessageHandler;
use Illuminate\Support\Facades\Log;

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
            Log::info('slack.router.skipped_bot_message', [
                'subtype' => $event['subtype'] ?? null,
                'bot_id' => $event['bot_id'] ?? null,
            ]);

            return;
        }

        $channel = (string) ($event['channel'] ?? '');
        $threadTs = (string) ($event['thread_ts'] ?? $event['ts'] ?? '');
        $userId = (string) ($event['user'] ?? '');

        if ($channel === '' || $threadTs === '') {
            Log::info('slack.router.missing_channel_or_ts', [
                'channel' => $channel,
                'ts' => $threadTs,
            ]);

            return;
        }

        $matched = [];

        foreach ($this->handlers as $handler) {
            if ($handler->matches($event)) {
                $matched[] = $handler::class;
                $handler->handle($event, $channel, $threadTs, $userId);
            }
        }

        Log::info('slack.router.dispatched', [
            'channel' => $channel,
            'matched' => $matched,
        ]);
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
