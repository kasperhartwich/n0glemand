<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CommandsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $command = (string) $request->input('command', '');

        Log::info('slack.command.received', [
            'command' => $command,
            'text' => $request->input('text'),
            'user_id' => $request->input('user_id'),
            'channel_id' => $request->input('channel_id'),
        ]);

        if ($command !== '/n0glemand') {
            return $this->ephemeral("Unknown command `{$command}`.");
        }

        $text = trim((string) $request->input('text', ''));
        [$subcommand, $args] = $this->splitText($text);

        return match ($subcommand) {
            '', 'help' => $this->help(),
            'logs' => $this->logs($args),
            default => $this->ephemeral("Unknown subcommand `{$subcommand}`. Try `/n0glemand help`."),
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitText(string $text): array
    {
        if ($text === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $text, 2);

        return [strtolower($parts[0] ?? ''), $parts[1] ?? ''];
    }

    protected function help(): JsonResponse
    {
        $lines = [
            '*n0glemand commands*',
            '• `/n0glemand logs [N]` — tail the last N lines (default 50, max 200) of `laravel.log`',
            '• `/n0glemand help` — show this message',
            '',
            '*In-channel (no slash command):*',
            '• Send `ping` — quick liveness check (bot replies `pong`)',
            '• Paste an Instagram reel/post URL — the bot downloads the video and posts it in-thread',
            '• Paste a Spotify URL — the bot replies in-thread with a song.link URL',
        ];

        return $this->ephemeral(implode("\n", $lines));
    }

    protected function logs(string $args): JsonResponse
    {
        $requested = (int) ($args !== '' ? $args : 50);
        $lines = max(1, min($requested, 200));

        $path = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            return $this->ephemeral('No log file found at `storage/logs/laravel.log`.');
        }

        $tail = $this->tailFile($path, $lines);

        if ($tail === '') {
            return $this->ephemeral('Log file is empty.');
        }

        $maxPayload = 3900;
        $truncated = false;

        if (strlen($tail) > $maxPayload) {
            $tail = substr($tail, -$maxPayload);
            $truncated = true;
        }

        $header = "Last {$lines} lines from `laravel.log`".($truncated ? ' (truncated)' : '').':';

        return $this->ephemeral("{$header}\n```\n{$tail}\n```");
    }

    protected function tailFile(string $path, int $lines): string
    {
        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $last = $file->key();

        $start = max(0, $last - $lines);
        $file->seek($start);

        $buffer = '';

        while (! $file->eof()) {
            $buffer .= $file->fgets();
        }

        return rtrim($buffer, "\n");
    }

    protected function ephemeral(string $text): JsonResponse
    {
        return response()->json([
            'response_type' => 'ephemeral',
            'text' => $text,
        ]);
    }
}
