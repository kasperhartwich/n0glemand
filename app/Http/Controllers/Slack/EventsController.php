<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use App\Services\Slack\SlackEventRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class EventsController extends Controller
{
    public function __invoke(Request $request, SlackEventRouter $router): Response|JsonResponse
    {
        $payload = $request->json()->all();

        if (($payload['type'] ?? null) === 'url_verification') {
            return response()->json(['challenge' => $payload['challenge'] ?? '']);
        }

        $eventId = $payload['event_id'] ?? null;

        if (is_string($eventId) && $eventId !== '') {
            $ttl = (int) config('services.slack.event_dedupe_ttl', 600);

            if (! Cache::add("slack:event:{$eventId}", 1, $ttl)) {
                return response()->noContent();
            }
        }

        $router->route($payload);

        return response()->noContent();
    }
}
