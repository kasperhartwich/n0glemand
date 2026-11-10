<?php

namespace App\Services\Instagram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks that Instagram still accepts the cookies yt-dlp downloads with.
 *
 * It sends one authenticated request with the cookie file as it is, rather
 * than logging in: a daily forced login risks a checkpoint, and production
 * may run on an exported cookie file it cannot recreate. A login is tried
 * only when there is no cookie file at all and credentials are set.
 */
class InstagramSessionCheck
{
    private const CURRENT_USER_URL = 'https://www.instagram.com/api/v1/accounts/current_user/?edit=true';

    private const IG_APP_ID = '936619743392459';

    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    public function __construct(
        private InstagramCookieJar $jar,
    ) {}

    public function run(): InstagramCheckResult
    {
        $result = $this->check();

        if ($result->isOk()) {
            Log::info('slack.ig.healthcheck.ok');
        } else {
            Log::warning('slack.ig.healthcheck.failed', [
                'status' => $result->status->value,
                'message' => $result->message,
            ]);
        }

        return $result;
    }

    private function check(): InstagramCheckResult
    {
        $path = $this->cookieFile();

        if ($path === null) {
            return $this->loginInstead();
        }

        $cookies = $this->readCookies($path);

        if (! isset($cookies['sessionid'])) {
            return new InstagramCheckResult(
                InstagramCheckStatus::CookiesInvalid,
                'The cookie file has no valid `sessionid` (missing or expired).',
            );
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => '*/*',
                'Accept-Language' => 'en-US,en;q=0.9',
                'X-IG-App-ID' => self::IG_APP_ID,
                'X-CSRFToken' => $cookies['csrftoken'] ?? '',
                'X-Requested-With' => 'XMLHttpRequest',
                'Cookie' => collect($cookies)->map(fn (string $value, string $name) => "{$name}={$value}")->implode('; '),
            ])
                ->withoutRedirecting()
                ->timeout((int) config('services.instagram.login_timeout', 8))
                ->get(self::CURRENT_USER_URL);
        } catch (Throwable $e) {
            return new InstagramCheckResult(
                InstagramCheckStatus::Inconclusive,
                'Could not reach Instagram: '.$e->getMessage(),
            );
        }

        return $this->classify($response);
    }

    private function classify(Response $response): InstagramCheckResult
    {
        $body = (array) $response->json();
        $message = (string) ($body['message'] ?? '');

        if ($message === 'checkpoint_required' || isset($body['checkpoint_url'])) {
            return new InstagramCheckResult(
                InstagramCheckStatus::CheckpointRequired,
                'Instagram wants a checkpoint (challenge) for this account.',
            );
        }

        if ($response->successful() && isset($body['user'])) {
            return new InstagramCheckResult(InstagramCheckStatus::Ok, 'Instagram accepts the cookies.');
        }

        if ($response->redirect() || in_array($response->status(), [401, 403], true) || ($body['require_login'] ?? false) === true) {
            return new InstagramCheckResult(
                InstagramCheckStatus::CookiesInvalid,
                'Instagram rejected the cookies (HTTP '.$response->status().($message !== '' ? ", {$message}" : '').').',
            );
        }

        return new InstagramCheckResult(
            InstagramCheckStatus::Inconclusive,
            'Unexpected answer from Instagram (HTTP '.$response->status().'), cannot tell if the cookies work.',
        );
    }

    private function loginInstead(): InstagramCheckResult
    {
        if ($this->jar->credentials() === null) {
            return new InstagramCheckResult(
                InstagramCheckStatus::NoCookies,
                'There is no cookie file and no Instagram credentials are set.',
            );
        }

        $cooldown = $this->jar->cooldownStatus();

        if ($cooldown !== null) {
            return new InstagramCheckResult(
                InstagramCheckStatus::LoginFailed,
                "There is no cookie file and the last login failed with `{$cooldown}`.",
            );
        }

        $status = $this->jar->refreshStatus();

        return match ($status) {
            InstagramLoginStatus::Ok => new InstagramCheckResult(
                InstagramCheckStatus::Ok,
                'There was no cookie file, a fresh login worked.',
            ),
            null => new InstagramCheckResult(
                InstagramCheckStatus::Inconclusive,
                'There is no cookie file and another login was already running.',
            ),
            default => new InstagramCheckResult(
                InstagramCheckStatus::LoginFailed,
                "There is no cookie file and the login failed: `{$status->value}`.",
            ),
        };
    }

    /**
     * The cookie file yt-dlp would use: the one the login wrote, else the
     * exported one from YTDLP_COOKIES_PATH.
     */
    private function cookieFile(): ?string
    {
        foreach ([$this->jar->cookiesPath(), (string) config('services.slack.ytdlp_cookies', '')] as $path) {
            if ($path !== '' && is_file($path) && filesize($path) > 0) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Reads the Netscape cookie file into name => value for instagram.com,
     * leaving out cookies that have expired.
     *
     * @return array<string, string>
     */
    private function readCookies(string $path): array
    {
        $cookies = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = rtrim($line, "\r");

            if (str_starts_with($line, '#HttpOnly_')) {
                $line = substr($line, strlen('#HttpOnly_'));
            }

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $fields = explode("\t", $line);

            if (count($fields) < 7 || ! str_contains($fields[0], 'instagram.com')) {
                continue;
            }

            $expires = (int) $fields[4];

            if ($expires > 0 && $expires < time()) {
                continue;
            }

            $cookies[$fields[5]] = $fields[6];
        }

        return $cookies;
    }
}
