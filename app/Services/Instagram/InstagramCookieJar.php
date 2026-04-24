<?php

namespace App\Services\Instagram;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class InstagramCookieJar
{
    public function __construct(
        private InstagramLoginClient $client,
    ) {}

    /**
     * @return array{username: string, path: string}|null
     */
    public function pickCookies(?string $excludeUsername = null): ?array
    {
        $accounts = $this->accounts();

        if ($accounts === []) {
            return null;
        }

        $ttl = (int) config('services.instagram.cookies_ttl', 60 * 60 * 24 * 7);
        $now = time();

        $fresh = [];
        $stale = [];

        foreach ($accounts as $account) {
            $username = (string) ($account['username'] ?? '');

            if ($username === '' || $username === $excludeUsername) {
                continue;
            }

            if (Cache::has($this->cooldownKey($username))) {
                continue;
            }

            $path = $this->pathFor($username);
            $mtime = is_file($path) ? (int) filemtime($path) : 0;
            $size = is_file($path) ? (int) filesize($path) : 0;

            $candidate = [
                'username' => $username,
                'password' => (string) ($account['password'] ?? ''),
                'path' => $path,
                'mtime' => $mtime,
            ];

            if ($mtime > 0 && $size > 0 && ($now - $mtime) <= $ttl) {
                $fresh[] = $candidate;
            } else {
                $stale[] = $candidate;
            }
        }

        if ($fresh !== []) {
            usort($fresh, fn (array $a, array $b) => $a['mtime'] <=> $b['mtime']);
            $pick = $fresh[0];

            @touch($pick['path']);

            return ['username' => $pick['username'], 'path' => $pick['path']];
        }

        foreach ($stale as $candidate) {
            if ($this->refresh($candidate['username'], $candidate['password'])) {
                return ['username' => $candidate['username'], 'path' => $candidate['path']];
            }
        }

        return null;
    }

    public function markInvalid(string $username, string $reason): void
    {
        $path = $this->pathFor($username);

        if (is_file($path)) {
            @unlink($path);
        }

        Cache::put(
            $this->cooldownKey($username),
            $reason,
            (int) config('services.instagram.login_cooldown', 60 * 5),
        );

        Log::warning('instagram.cookies.invalidated', [
            'username' => $username,
            'reason' => $reason,
        ]);
    }

    public function refresh(string $username, string $password): bool
    {
        if ($password === '') {
            Log::warning('instagram.login.missing_password', ['username' => $username]);

            return false;
        }

        $result = $this->client->login($username, $password);

        if (! $result->isOk() || $result->cookiesTxt === null) {
            Cache::put(
                $this->cooldownKey($username),
                $result->status->value,
                (int) config('services.instagram.login_cooldown', 60 * 5),
            );

            Log::warning('instagram.login.failed', [
                'username' => $username,
                'status' => $result->status->value,
                'message' => $result->errorMessage,
            ]);

            return false;
        }

        $this->writeCookies($username, $result->cookiesTxt);

        Log::info('instagram.login.ok', ['username' => $username]);

        return true;
    }

    /**
     * @return array<int, array{username: string, password: string}>
     */
    public function accounts(): array
    {
        $raw = (array) config('services.instagram.accounts', []);
        $accounts = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $username = (string) ($entry['username'] ?? '');
            $password = (string) ($entry['password'] ?? '');

            if ($username === '' || $password === '') {
                continue;
            }

            $accounts[] = ['username' => $username, 'password' => $password];
        }

        return $accounts;
    }

    public function cacheDir(): string
    {
        $dir = storage_path('app/private/instagram-cookies');

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0700, true, true);
        }

        return $dir;
    }

    public function pathFor(string $username): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $username) ?? 'unknown';

        return $this->cacheDir().'/'.$safe.'.txt';
    }

    private function writeCookies(string $username, string $cookiesTxt): void
    {
        $path = $this->pathFor($username);

        file_put_contents($path, $cookiesTxt);
        @chmod($path, 0600);
    }

    private function cooldownKey(string $username): string
    {
        return 'ig:cooldown:'.sha1($username);
    }
}
