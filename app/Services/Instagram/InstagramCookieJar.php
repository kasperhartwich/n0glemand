<?php

namespace App\Services\Instagram;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InstagramCookieJar
{
    private const COOLDOWN_CACHE_KEY = 'ig:login:cooldown';

    private const LOGIN_LOCK_KEY = 'ig:login';

    public function __construct(
        private InstagramLoginClient $client,
    ) {}

    public function pickCookiesPath(): ?string
    {
        $credentials = $this->credentials();

        if ($credentials === null) {
            return null;
        }

        $path = $this->cookiesPath();
        $ttl = (int) config('services.instagram.cookies_ttl', 60 * 60 * 24 * 7);

        if ($this->cacheHit($path, $ttl)) {
            @touch($path);

            return $path;
        }

        if (Cache::has(self::COOLDOWN_CACHE_KEY)) {
            return null;
        }

        return $this->withLoginLock(function () use ($credentials, $path, $ttl): ?string {
            if ($this->cacheHit($path, $ttl)) {
                return $path;
            }

            if (Cache::has(self::COOLDOWN_CACHE_KEY)) {
                return null;
            }

            return $this->login($credentials) ? $path : null;
        });
    }

    public function markInvalid(string $reason): void
    {
        $path = $this->cookiesPath();

        if (is_file($path)) {
            @unlink($path);
        }

        Log::warning('instagram.cookies.invalidated', [
            'username' => $this->credentials()['username'] ?? null,
            'reason' => $reason,
        ]);
    }

    public function refresh(): bool
    {
        $credentials = $this->credentials();

        if ($credentials === null) {
            Log::warning('instagram.login.missing_credentials');

            return false;
        }

        return $this->withLoginLock(fn (): bool => $this->login($credentials)) ?? false;
    }

    /**
     * Runs the callback while holding the login lock, so concurrent workers
     * never log in twice at once. Returns null when the lock stays taken.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult|null
     */
    private function withLoginLock(callable $callback): mixed
    {
        try {
            return Cache::lock(self::LOGIN_LOCK_KEY, 60)
                ->block((int) config('services.instagram.login_lock_wait', 16), $callback);
        } catch (LockTimeoutException) {
            Log::warning('instagram.login.lock_timeout');

            return null;
        }
    }

    /**
     * @param  array{username: string, password: string}  $credentials
     */
    private function login(array $credentials): bool
    {
        $result = $this->client->login($credentials['username'], $credentials['password']);

        if (! $result->isOk() || $result->cookiesTxt === null) {
            Cache::put(
                self::COOLDOWN_CACHE_KEY,
                $result->status->value,
                (int) config('services.instagram.login_cooldown', 60 * 5),
            );

            Log::warning('instagram.login.failed', [
                'username' => $credentials['username'],
                'status' => $result->status->value,
                'message' => $result->errorMessage,
            ]);

            return false;
        }

        $this->writeCookies($result->cookiesTxt);

        Log::info('instagram.login.ok', ['username' => $credentials['username']]);

        return true;
    }

    /**
     * @return array{username: string, password: string}|null
     */
    public function credentials(): ?array
    {
        $username = (string) config('services.instagram.username', '');
        $password = (string) config('services.instagram.password', '');

        if ($username === '' || $password === '') {
            return null;
        }

        return ['username' => $username, 'password' => $password];
    }

    public function cacheDir(): string
    {
        $dir = storage_path('app/private/instagram-cookies');

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0700, true, true);
        }

        return $dir;
    }

    public function cookiesPath(): string
    {
        return $this->cacheDir().'/instagram.txt';
    }

    private function cacheHit(string $path, int $ttl): bool
    {
        if (! is_file($path) || filesize($path) === 0) {
            return false;
        }

        return (time() - (int) filemtime($path)) <= $ttl;
    }

    private function writeCookies(string $cookiesTxt): void
    {
        $path = $this->cookiesPath();
        $tmpPath = $path.'.'.Str::random(8).'.tmp';

        file_put_contents($tmpPath, $cookiesTxt);
        @chmod($tmpPath, 0600);
        rename($tmpPath, $path);
    }
}
