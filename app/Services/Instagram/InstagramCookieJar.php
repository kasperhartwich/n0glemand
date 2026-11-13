<?php

namespace App\Services\Instagram;

use App\Jobs\Slack\AnnounceInstagramCheckpoint;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InstagramCookieJar
{
    private const COOLDOWN_CACHE_KEY = 'ig:login:cooldown';

    private const LOGIN_LOCK_KEY = 'ig:login';

    private const FORCE_FRESH_CACHE_KEY = 'ig:login:force_fresh';

    private const FORCE_FRESH_THROTTLE_KEY = 'ig:login:force_fresh_throttle';

    public function __construct(
        private InstagramLogin $client,
    ) {}

    /**
     * The cookie file the login wrote, as it is: no login, no touch, whatever
     * its age (the scheduled refresh and the upload job's auth-failure path
     * keep it alive). Null without credentials or without a file.
     */
    public function currentCookiesPath(): ?string
    {
        if ($this->credentials() === null) {
            return null;
        }

        $path = $this->cookiesPath();

        return is_file($path) && filesize($path) > 0 ? $path : null;
    }

    /**
     * Modification time of the cookie file, null when there is none.
     */
    public function cookiesModifiedAt(): ?int
    {
        $path = $this->cookiesPath();
        clearstatcache(true, $path);

        return is_file($path) ? (int) filemtime($path) : null;
    }

    /**
     * Throws the cookie file away because they were found dead, and makes the
     * next login start a fresh sign-in (the browser profile may still hold the
     * dead session). With `$seenModifiedAt` it only does so when the file is
     * still the one the caller used: a login that finished meanwhile wrote new
     * cookies that must not be deleted. Returns whether it invalidated.
     */
    public function markInvalid(string $reason, ?int $seenModifiedAt = null): bool
    {
        if ($seenModifiedAt !== null && $this->cookiesModifiedAt() !== $seenModifiedAt) {
            Log::info('instagram.cookies.invalidate_skipped', ['reason' => 'replaced since use']);

            return false;
        }

        $path = $this->cookiesPath();

        if (is_file($path)) {
            @unlink($path);
        }

        // yt-dlp's auth-looking errors also come from private or deleted reels and rate limits,
        // so a password login is forced at most once per interval; otherwise the browser reuses
        // the profile session when Instagram still accepts it.
        if (! Cache::has(self::FORCE_FRESH_THROTTLE_KEY)) {
            Cache::put(
                self::FORCE_FRESH_CACHE_KEY,
                true,
                (int) config('services.instagram.cookies_ttl', 60 * 60 * 24 * 7),
            );
        }

        Log::warning('instagram.cookies.invalidated', [
            'username' => $this->credentials()['username'] ?? null,
            'reason' => $reason,
        ]);

        return true;
    }

    public function refresh(): bool
    {
        return $this->refreshStatus() === InstagramLoginStatus::Ok;
    }

    /**
     * Forces a fresh login. Returns how it went, or null when no credentials
     * are configured or the login lock stayed taken. `$waitForCode` false is for
     * callers that cannot wait minutes for an admin to hand over a code.
     */
    public function refreshStatus(bool $waitForCode = true): ?InstagramLoginStatus
    {
        $credentials = $this->credentials();

        if ($credentials === null) {
            Log::warning('instagram.login.missing_credentials');

            return null;
        }

        return $this->withLoginLock(fn (): InstagramLoginStatus => $this->login($credentials, $waitForCode));
    }

    /**
     * The status of the failed login that started the current cooldown, or
     * null when no cooldown is active.
     */
    public function cooldownStatus(): ?string
    {
        $status = Cache::get(self::COOLDOWN_CACHE_KEY);

        return is_string($status) ? $status : null;
    }

    /**
     * Age of the cookie file in seconds, or null when there is none.
     */
    public function cookiesAgeSeconds(): ?int
    {
        $path = $this->cookiesPath();

        if (! is_file($path) || filesize($path) === 0) {
            return null;
        }

        return max(0, time() - (int) filemtime($path));
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
            return Cache::lock(self::LOGIN_LOCK_KEY, (int) config('services.instagram.login_lock_ttl', 120))
                ->block((int) config('services.instagram.login_lock_wait', 16), $callback);
        } catch (LockTimeoutException) {
            Log::warning('instagram.login.lock_timeout');

            return null;
        }
    }

    /**
     * @param  array{username: string, password: string}  $credentials
     */
    private function login(array $credentials, bool $waitForCode): InstagramLoginStatus
    {
        $forceFresh = Cache::has(self::FORCE_FRESH_CACHE_KEY);

        if ($forceFresh) {
            Cache::put(
                self::FORCE_FRESH_THROTTLE_KEY,
                true,
                (int) config('services.instagram.force_fresh_interval', 60 * 60 * 6),
            );
        }

        $result = $this->client->login($credentials['username'], $credentials['password'], $forceFresh, $waitForCode);

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

            if ($result->status === InstagramLoginStatus::CheckpointRequired) {
                AnnounceInstagramCheckpoint::dispatch();
            }

            return $result->status === InstagramLoginStatus::Ok
                ? InstagramLoginStatus::Unknown
                : $result->status;
        }

        $this->writeCookies($result->cookiesTxt);
        Cache::forget(self::COOLDOWN_CACHE_KEY);
        Cache::forget(self::FORCE_FRESH_CACHE_KEY);

        Log::info('instagram.login.ok', ['username' => $credentials['username']]);

        return InstagramLoginStatus::Ok;
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

    private function writeCookies(string $cookiesTxt): void
    {
        $path = $this->cookiesPath();
        $tmpPath = $path.'.'.Str::random(8).'.tmp';

        file_put_contents($tmpPath, $cookiesTxt);
        @chmod($tmpPath, 0600);
        rename($tmpPath, $path);
    }
}
