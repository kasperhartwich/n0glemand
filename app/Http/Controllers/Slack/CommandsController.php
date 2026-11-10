<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use App\Jobs\Slack\RefreshInstagramCookiesFromSlack;
use App\Services\Instagram\InstagramCookieJar;
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
            'instagram' => $this->instagram($request, $args),
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
            '• `/n0glemand instagram status` — admins only: cookie file age and login cooldown',
            '• `/n0glemand instagram refresh` — admins only: force a fresh Instagram login',
            '• `/n0glemand help` — show this message',
            '',
            '*In-channel (no slash command):*',
            '• Send `ping` — quick liveness check (bot replies `pong`)',
            '• Paste an Instagram reel/post URL — the bot downloads the video and posts it in-thread',
            '• Paste a Spotify URL — the bot replies in-thread with a song.link URL (tracks and albums)',
        ];

        return $this->ephemeral(implode("\n", $lines));
    }

    protected function instagram(Request $request, string $args): JsonResponse
    {
        $userId = (string) $request->input('user_id', '');
        $admins = (array) config('services.slack.admin_user_ids', []);

        if ($userId === '' || ! in_array($userId, $admins, true)) {
            Log::warning('slack.command.instagram_refused', ['user_id' => $userId]);

            return $this->ephemeral('Only n0glemand admins can run `instagram` commands.');
        }

        $action = strtolower(trim($args));

        return match ($action) {
            'status' => $this->instagramStatus(app(InstagramCookieJar::class)),
            'refresh' => $this->instagramRefresh((string) $request->input('response_url', '')),
            default => $this->ephemeral('Usage: `/n0glemand instagram status` or `/n0glemand instagram refresh`.'),
        };
    }

    protected function instagramStatus(InstagramCookieJar $jar): JsonResponse
    {
        $age = $jar->cookiesAgeSeconds();
        $ttl = (int) config('services.instagram.cookies_ttl', 60 * 60 * 24 * 7);
        $cooldown = $jar->cooldownStatus();

        $lines = [
            '*Instagram status*',
            '• Credentials: '.($jar->credentials() === null ? 'not configured' : 'configured'),
            '• Cookie file: '.($age === null
                ? 'missing'
                : 'present, '.$this->humanSeconds($age).' old'.($age > $ttl ? ' (expired)' : '')),
            '• Login cooldown: '.($cooldown === null ? 'not active' : "active after `{$cooldown}`"),
        ];

        return $this->ephemeral(implode("\n", $lines));
    }

    protected function instagramRefresh(string $responseUrl): JsonResponse
    {
        if ($responseUrl === '') {
            return $this->ephemeral('Slack sent no `response_url`, so the result cannot be posted back.');
        }

        RefreshInstagramCookiesFromSlack::dispatch($responseUrl);

        return $this->ephemeral(':hourglass_flowing_sand: Refreshing the Instagram login, the result follows here.');
    }

    protected function humanSeconds(int $seconds): string
    {
        return match (true) {
            $seconds < 120 => "{$seconds} s",
            $seconds < 7200 => intdiv($seconds, 60).' min',
            $seconds < 172800 => intdiv($seconds, 3600).' h',
            default => intdiv($seconds, 86400).' days',
        };
    }

    protected function logs(string $args): JsonResponse
    {
        $requested = (int) ($args !== '' ? $args : 50);
        $lines = max(1, min($requested, 200));

        $path = $this->newestLogFile();

        if ($path === null) {
            return $this->ephemeral('No log file found in `storage/logs`.');
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

        $header = "Last {$lines} lines from `".basename($path).'`'.($truncated ? ' (truncated)' : '').':';

        return $this->ephemeral("{$header}\n```\n{$tail}\n```");
    }

    /**
     * The single channel writes laravel.log, the daily one laravel-YYYY-MM-DD.log.
     */
    protected function newestLogFile(): ?string
    {
        $files = glob(storage_path('logs/laravel*.log')) ?: [];

        return collect($files)
            ->filter(fn (string $file) => is_file($file))
            ->sortByDesc(fn (string $file) => filemtime($file))
            ->first();
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
