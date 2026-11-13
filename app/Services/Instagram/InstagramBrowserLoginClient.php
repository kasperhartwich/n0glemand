<?php

namespace App\Services\Instagram;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Logs in through a headless Chromium: `node login.mjs` (Playwright, a
 * persistent profile under storage/app/private/instagram-browser) prints one
 * JSON line. The credentials reach the script by environment, never argv.
 */
class InstagramBrowserLoginClient implements InstagramLogin
{
    public function __construct(
        private NetscapeCookieFormatter $formatter,
        private InstagramCheckpointChallenge $challenge,
    ) {}

    public function login(string $username, string $password, bool $forceFresh = false, bool $waitForCode = true): InstagramLoginResult
    {
        $timeout = max(10, (int) config('services.instagram.browser_timeout', 50));
        $codeWait = $waitForCode ? max(0, (int) config('services.instagram.code_wait', 600)) : 0;
        $status = null;

        try {
            $profileDir = $this->profileDir();
            $browserDir = $this->browserDir();
            $buffer = '';

            $process = Process::timeout($timeout + $codeWait)
                ->env([
                    'INSTAGRAM_LOGIN_USERNAME' => $username,
                    'INSTAGRAM_LOGIN_PASSWORD' => $password,
                    'INSTAGRAM_PROFILE_DIR' => $profileDir,
                    'INSTAGRAM_BROWSER_DIR' => $browserDir,
                    // The script gives up by itself before the process timeout kills it.
                    'INSTAGRAM_LOGIN_TIMEOUT' => (string) max(5, $timeout - 8),
                    // How long it waits for a code or an approval once Instagram asks (0: not at all).
                    'INSTAGRAM_CODE_WAIT' => (string) $codeWait,
                    'INSTAGRAM_CHALLENGE_METHOD' => $this->challengeMethod(),
                    'PLAYWRIGHT_BROWSERS_PATH' => $browserDir.'/browsers',
                    // Drop the profile's sessionid first so a dead session is not handed back.
                    'INSTAGRAM_FORCE_FRESH' => $forceFresh ? '1' : '0',
                ])
                ->start([
                    (string) config('services.instagram.node_binary', 'node'),
                    base_path('resources/scripts/instagram-login/login.mjs'),
                ], function (string $type, string $chunk) use (&$buffer): void {
                    if ($type !== 'out') {
                        return;
                    }

                    // Events (a code is wanted) arrive while the process runs, a chunk may hold part of a line.
                    $buffer .= $chunk;

                    while (($newline = strpos($buffer, "\n")) !== false) {
                        $this->handleEvent(substr($buffer, 0, $newline));
                        $buffer = substr($buffer, $newline + 1);
                    }
                });

            $result = $process->wait();
        } catch (Throwable $e) {
            $this->challenge->finished(null);

            return $this->unknown('IG browser login failed to run: '.Str::limit($e->getMessage(), 200));
        }

        $payload = $this->lastJsonLine($result->output());

        if (! $result->successful() && $payload === null) {
            $login = $this->unknown(sprintf(
                'IG browser login exited with code %s: %s',
                (string) $result->exitCode(),
                Str::limit(trim($result->errorOutput()), 200),
            ));
        } elseif ($payload === null) {
            $login = $this->unknown('IG browser login printed no JSON result.');
        } else {
            $login = $this->classify($payload);
        }

        $this->challenge->finished($login->status);

        return $login;
    }

    /**
     * An event line of the script: `{"event":"code_required","method":"sms"}`
     * or `{"event":"approval_required"}`. Anything else is not an event.
     */
    private function handleEvent(string $line): void
    {
        $event = json_decode(trim($line), true);

        if (! is_array($event) || ! isset($event['event'])) {
            return;
        }

        try {
            match ($event['event']) {
                'code_required' => $this->challenge->codeRequested((string) ($event['method'] ?? 'sms')),
                'approval_required' => $this->challenge->approvalRequested(),
                default => null,
            };
        } catch (Throwable $e) {
            Log::error('slack.ig.checkpoint.event_failed', ['exception' => $e->getMessage()]);
        }
    }

    private function challengeMethod(): string
    {
        return strtolower((string) config('services.instagram.challenge_method', 'sms')) === 'email' ? 'email' : 'sms';
    }

    /**
     * The persistent browser profile, created private (0700).
     */
    public function profileDir(): string
    {
        $dir = storage_path('app/private/instagram-browser');

        File::ensureDirectoryExists($dir, 0700, true);
        @chmod($dir, 0700);

        return $dir;
    }

    /**
     * The folder with node_modules/playwright and the installed browsers.
     */
    public function browserDir(): string
    {
        $dir = config('services.instagram.browser_dir');

        return is_string($dir) && $dir !== ''
            ? rtrim($dir, '/')
            : base_path('resources/scripts/instagram-login');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function classify(array $payload): InstagramLoginResult
    {
        $status = InstagramLoginStatus::tryFrom((string) ($payload['status'] ?? ''));
        $message = isset($payload['message']) ? Str::limit((string) $payload['message'], 300) : null;

        if ($status === null) {
            return $this->unknown('IG browser login returned an unknown status.');
        }

        if ($status !== InstagramLoginStatus::Ok) {
            return new InstagramLoginResult($status, errorMessage: $message ?? 'Instagram browser login: '.$status->value.'.');
        }

        $cookies = $this->instagramCookies((array) ($payload['cookies'] ?? []));

        if (! collect($cookies)->contains(fn (array $cookie): bool => $cookie['name'] === 'sessionid' && $cookie['value'] !== '')) {
            return $this->unknown('IG browser login reported ok but returned no sessionid cookie.');
        }

        return new InstagramLoginResult(
            InstagramLoginStatus::Ok,
            cookiesTxt: $this->formatter->format($cookies),
        );
    }

    /**
     * Only cookies of instagram.com, in the shape the formatter takes.
     *
     * @param  array<int, mixed>  $raw
     * @return array<int, array{name: string, value: string, domain: string, path: string, secure: bool, expires: int}>
     */
    private function instagramCookies(array $raw): array
    {
        $cookies = [];

        foreach ($raw as $cookie) {
            if (! is_array($cookie)) {
                continue;
            }

            $name = (string) ($cookie['name'] ?? '');
            $value = (string) ($cookie['value'] ?? '');
            $domain = (string) ($cookie['domain'] ?? '');
            $host = ltrim($domain, '.');

            $isInstagram = $host === 'instagram.com' || str_ends_with($host, '.instagram.com');
            $hasControlChars = preg_match('/[\t\r\n]/', $name.$value) === 1;

            if ($name === '' || ! $isInstagram || $hasControlChars) {
                continue;
            }

            $cookies[] = [
                'name' => $name,
                'value' => $value,
                'domain' => $domain,
                'path' => (string) ($cookie['path'] ?? '/') ?: '/',
                'secure' => (bool) ($cookie['secure'] ?? true),
                'expires' => max(0, (int) ($cookie['expires'] ?? 0)),
            ];
        }

        return $cookies;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastJsonLine(string $output): ?array
    {
        $lines = array_reverse(array_filter(array_map('trim', explode("\n", $output)), fn (string $line): bool => $line !== ''));

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && isset($decoded['status'])) {
                return $decoded;
            }
        }

        return null;
    }

    private function unknown(string $message): InstagramLoginResult
    {
        return new InstagramLoginResult(InstagramLoginStatus::Unknown, errorMessage: $message);
    }
}
