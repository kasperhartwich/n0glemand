<?php

namespace App\Services\Slack\Handlers\Concerns;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

trait DispatchesOncePerEvent
{
    /**
     * Run a dispatch at most once per Slack event and action, so a replay of
     * the event after a partial failure skips what was already dispatched. If
     * the dispatch throws, its key is released so the replay can retry it.
     *
     * @param  array<string, mixed>  $event
     */
    protected function dispatchOnce(array $event, string $action, Closure $dispatch): void
    {
        $eventId = $event['_event_id'] ?? null;

        if (! is_string($eventId) || $eventId === '') {
            $dispatch();

            return;
        }

        $key = 'slack:dispatch:'.$eventId.':'.sha1($action);

        if (! Cache::add($key, 1, (int) config('services.slack.event_dedupe_ttl', 600))) {
            return;
        }

        try {
            $dispatch();
        } catch (Throwable $e) {
            Cache::forget($key);

            throw $e;
        }
    }
}
