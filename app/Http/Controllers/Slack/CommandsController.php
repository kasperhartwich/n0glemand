<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use App\Jobs\Slack\CheckInstagramFromSlack;
use App\Jobs\Slack\RefreshInstagramCookiesFromSlack;
use App\Services\Instagram\InstagramCheckpointChallenge;
use App\Services\Instagram\InstagramStatusReport;
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
            'text' => $this->redacted((string) $request->input('text', '')),
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
     * The text for the log: a login code is never written down.
     */
    protected function redacted(string $text): string
    {
        return preg_replace('/^(\s*instagram\s+code\s+).*$/i', '$1[redacted]', $text) ?? $text;
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
            '• `/n0glemand logs [N]`: tail the last N lines (default 50, max 200) of `laravel.log`',
            '• `/n0glemand instagram status` (admins only): login driver, cookie file age, login cooldown and whether a login waits for a code',
            '• `/n0glemand instagram check` (admins only): check that Instagram still accepts the cookies',
            '• `/n0glemand instagram refresh` (admins only): force a fresh Instagram login',
            '• `/n0glemand instagram code <digits>` (admins only): hand Instagram the code (SMS, email or authenticator) a waiting login asked for',
            '• `/n0glemand help`: show this message',
            '• Admins can send the same `instagram status|check|refresh|code` words to the bot as a direct message, or just the digits of a code',
            '',
            '*In-channel (no slash command):*',
            '• Send `ping`: quick liveness check (bot replies `pong`)',
            '• Paste an Instagram reel/post URL: the bot downloads the video and posts it in-thread',
            '• Paste a track or album link (Spotify, Apple Music, YouTube Music, Tidal, Deezer, Amazon Music, SoundCloud): the bot replies in-thread with song.link URLs',
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

        if (preg_match('/^code(?:\s+(\S+))?$/', $action, $matches) === 1) {
            return $this->ephemeral(isset($matches[1])
                ? app(InstagramCheckpointChallenge::class)->handOverCode($matches[1])
                : 'Usage: `/n0glemand instagram code <digits>` (4 to 8 digits).');
        }

        return match ($action) {
            'status' => $this->instagramStatus(app(InstagramStatusReport::class)),
            'check' => $this->instagramCheck((string) $request->input('response_url', '')),
            'refresh' => $this->instagramRefresh((string) $request->input('response_url', '')),
            default => $this->ephemeral('Usage: `/n0glemand instagram status`, `instagram check`, `instagram refresh` or `instagram code <digits>`.'),
        };
    }

    protected function instagramStatus(InstagramStatusReport $report): JsonResponse
    {
        return $this->ephemeral($report->text());
    }

    protected function instagramCheck(string $responseUrl): JsonResponse
    {
        if ($responseUrl === '') {
            return $this->ephemeral('Slack sent no `response_url`, so the result cannot be posted back.');
        }

        CheckInstagramFromSlack::dispatch($responseUrl);

        return $this->ephemeral(':hourglass_flowing_sand: Checking the Instagram cookies, the result follows here.');
    }

    protected function instagramRefresh(string $responseUrl): JsonResponse
    {
        if ($responseUrl === '') {
            return $this->ephemeral('Slack sent no `response_url`, so the result cannot be posted back.');
        }

        RefreshInstagramCookiesFromSlack::dispatch($responseUrl);

        return $this->ephemeral(':hourglass_flowing_sand: Refreshing the Instagram login, the result follows here.');
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
