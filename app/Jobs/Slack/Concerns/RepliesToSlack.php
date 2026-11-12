<?php

namespace App\Jobs\Slack\Concerns;

use App\Services\Slack\SlackClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts a job's outcome back to whoever asked: into the DM channel when the
 * job was started from a direct message, otherwise to the slash command's
 * `response_url` as an ephemeral reply.
 */
trait RepliesToSlack
{
    protected function reply(string $text): void
    {
        try {
            if ($this->dmChannel !== null) {
                app(SlackClient::class)->postMessage($this->dmChannel, $text);

                return;
            }

            Http::timeout(10)->post($this->responseUrl, [
                'response_type' => 'ephemeral',
                'replace_original' => false,
                'text' => $text,
            ]);
        } catch (Throwable $e) {
            Log::warning(static::REPLY_FAILED_LOG, ['exception' => $e->getMessage()]);
        }
    }
}
