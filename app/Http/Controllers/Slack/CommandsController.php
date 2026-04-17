<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommandsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $command = (string) $request->input('command', '');
        $text = trim((string) $request->input('text', ''));

        return match ($command) {
            '/n0g-logs' => $this->logs($text),
            default => $this->ephemeral("Unknown command `{$command}`."),
        };
    }

    protected function logs(string $text): JsonResponse
    {
        $requested = (int) ($text !== '' ? $text : 50);
        $lines = max(1, min($requested, 200));

        $path = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            return $this->ephemeral('No log file found at `storage/logs/laravel.log`.');
        }

        $tail = $this->tailFile($path, $lines);

        if ($tail === '') {
            return $this->ephemeral('Log file is empty.');
        }

        // Slack hard-caps messages around 4000 chars; leave headroom for code fences.
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
