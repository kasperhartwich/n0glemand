<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use App\Services\Slack\SlackEventRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EventsController extends Controller
{
    public function __invoke(Request $request, SlackEventRouter $router): Response|JsonResponse
    {
        $payload = $request->json()->all();
        $type = $payload['type'] ?? null;
        $event = $payload['event'] ?? [];

        Log::info('slack.event.received', [
            'type' => $type,
            'event_type' => $event['type'] ?? null,
            'event_id' => $payload['event_id'] ?? null,
            'channel' => $event['channel'] ?? null,
            'user' => $event['user'] ?? null,
        ]);

        if ($type === 'url_verification') {
            return response()->json(['challenge' => $payload['challenge'] ?? '']);
        }

        $eventId = $payload['event_id'] ?? null;

        if (is_string($eventId) && $eventId !== '') {
            $ttl = (int) config('services.slack.event_dedupe_ttl', 600);

            if (! Cache::add("slack:event:{$eventId}", 1, $ttl)) {
                Log::info('slack.event.deduped', ['event_id' => $eventId]);

                return response()->noContent();
            }
        }

        $router->route($payload);

        return response()->noContent();
    }
}
