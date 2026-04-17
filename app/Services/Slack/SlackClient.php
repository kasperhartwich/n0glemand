<?php

namespace App\Services\Slack;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class SlackClient
{
    public function __construct(
        protected string $botToken,
        protected string $apiBase,
    ) {}

    public function postMessage(string $channel, string $text, ?string $threadTs = null): array
    {
        $payload = array_filter([
            'channel' => $channel,
            'text' => $text,
            'thread_ts' => $threadTs,
        ], fn ($value) => $value !== null);

        $json = Http::withToken($this->botToken)
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->post("{$this->apiBase}/chat.postMessage", $payload)
            ->throw()
            ->json();

        $this->assertOk($json, 'chat.postMessage');

        return $json;
    }

    public function filesUploadV2(string $channel, string $threadTs, string $localPath, string $title): array
    {
        $filename = basename($localPath);
        $length = filesize($localPath);

        $urlJson = Http::withToken($this->botToken)
            ->acceptJson()
            ->timeout(10)
            ->get("{$this->apiBase}/files.getUploadURLExternal", [
                'filename' => $filename,
                'length' => $length,
            ])
            ->throw()
            ->json();

        $this->assertOk($urlJson, 'files.getUploadURLExternal');

        $uploadUrl = $urlJson['upload_url'];
        $fileId = $urlJson['file_id'];

        $uploadHandle = fopen($localPath, 'r');

        try {
            Http::withToken($this->botToken)
                ->withBody($uploadHandle, 'application/octet-stream')
                ->timeout(120)
                ->post($uploadUrl)
                ->throw();
        } finally {
            if (is_resource($uploadHandle)) {
                fclose($uploadHandle);
            }
        }

        $completeJson = Http::withToken($this->botToken)
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->post("{$this->apiBase}/files.completeUploadExternal", [
                'files' => [['id' => $fileId, 'title' => $title]],
                'channel_id' => $channel,
                'thread_ts' => $threadTs,
            ])
            ->throw()
            ->json();

        $this->assertOk($completeJson, 'files.completeUploadExternal');

        return $completeJson;
    }

    public function joinChannel(string $channelId): array
    {
        $json = Http::withToken($this->botToken)
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->post("{$this->apiBase}/conversations.join", ['channel' => $channelId])
            ->throw()
            ->json();

        if (($json['ok'] ?? false) !== true && ($json['error'] ?? null) !== 'already_in_channel') {
            $this->assertOk($json, 'conversations.join');
        }

        return $json;
    }

    /**
     * @return array{channels: array<int, array<string, mixed>>, next_cursor: string}
     */
    public function listPublicChannels(?string $cursor = null, int $limit = 200): array
    {
        $query = array_filter([
            'types' => 'public_channel',
            'exclude_archived' => 'true',
            'limit' => $limit,
            'cursor' => $cursor,
        ], fn ($value) => $value !== null && $value !== '');

        $json = Http::withToken($this->botToken)
            ->acceptJson()
            ->timeout(15)
            ->get("{$this->apiBase}/conversations.list", $query)
            ->throw()
            ->json();

        $this->assertOk($json, 'conversations.list');

        return [
            'channels' => $json['channels'] ?? [],
            'next_cursor' => $json['response_metadata']['next_cursor'] ?? '',
        ];
    }

    public function verifyAuth(): array
    {
        $json = Http::withToken($this->botToken)
            ->acceptJson()
            ->timeout(10)
            ->post("{$this->apiBase}/auth.test")
            ->throw()
            ->json();

        $this->assertOk($json, 'auth.test');

        return $json;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    protected function assertOk(array $json, string $endpoint): void
    {
        if (($json['ok'] ?? false) !== true) {
            $error = $json['error'] ?? 'unknown';

            throw new RuntimeException("slack.{$endpoint} failed: {$error}");
        }
    }
}
