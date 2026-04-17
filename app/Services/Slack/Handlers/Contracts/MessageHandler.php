<?php

namespace App\Services\Slack\Handlers\Contracts;

interface MessageHandler
{
    /**
     * @param  array<string, mixed>  $event
     */
    public function matches(array $event): bool;

    /**
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event, string $channel, string $threadTs, string $userId): void;
}
