<?php

namespace App\Services\Instagram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Checks that Instagram still accepts the cookies yt-dlp downloads with.
 *
 * It sends one authenticated request with the cookie file as it is, rather
 * than logging in: a daily forced login risks a checkpoint, and production
 * may run on an exported cookie file it cannot recreate. A login is tried
 * only when credentials are set and the jar has no fresh cookie file, which
 * is when a login is due.
 */
class InstagramSessionCheck
{
    private const CURRENT_USER_URL = 'https://www.instagram.com/api/v1/accounts/current_user/?edit=true';

    private const MAX_REDIRECTS = 3;

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
            $response = $this->request($cookies);
        } catch (Throwable $e) {
            return new InstagramCheckResult(
                InstagramCheckStatus::Inconclusive,
                'Could not reach Instagram: '.$e->getMessage(),
            );
        }

        return $this->confirmWithYtDlp($this->classify($response), $path);
    }

    /**
     * Sends the request the web app sends and follows redirects by hand, up
     * to MAX_REDIRECTS, on instagram.com only and with the same cookies. A
     * redirect to a canonical host or a trailing slash is then not mistaken
     * for a rejection, and every Location is logged.
     *
     * @param  array<string, string>  $cookies
     */
    private function request(array $cookies): Response
    {
        $url = self::CURRENT_USER_URL;
        $headers = [
            'User-Agent' => self::USER_AGENT,
            'Accept' => '*/*',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Referer' => 'https://www.instagram.com/',
            'Origin' => 'https://www.instagram.com',
            'Sec-Fetch-Site' => 'same-origin',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Dest' => 'empty',
            'X-IG-App-ID' => self::IG_APP_ID,
            'X-CSRFToken' => $cookies['csrftoken'] ?? '',
            'X-Requested-With' => 'XMLHttpRequest',
            'Cookie' => collect($cookies)->map(fn (string $value, string $name) => "{$name}={$value}")->implode('; '),
        ];

        for ($hop = 0; ; $hop++) {
            $response = Http::withHeaders($headers)
                ->withoutRedirecting()
                ->timeout((int) config('services.instagram.login_timeout', 8))
                ->get($url);

            if (! $response->redirect()) {
                return $response;
            }

            $location = (string) $response->header('Location');

            Log::warning('slack.ig.healthcheck.redirect', [
                'status' => $response->status(),
                'from' => $url,
                'location' => $location,
            ]);

            $next = $this->resolveLocation($url, $location);

            if ($hop >= self::MAX_REDIRECTS || $next === null || $this->isLoginOrChallenge($next)) {
                return $response;
            }

            $url = $next;
        }
    }

    private function resolveLocation(string $from, string $location): ?string
    {
        if ($location === '') {
            return null;
        }

        $absolute = str_starts_with($location, '/')
            ? 'https://www.instagram.com'.$location
            : $location;

        $host = strtolower((string) parse_url($absolute, PHP_URL_HOST));

        if (! str_starts_with($absolute, 'https://') || ($host !== 'instagram.com' && ! str_ends_with($host, '.instagram.com'))) {
            return null;
        }

        return $absolute === $from ? null : $absolute;
    }

    private function isLoginOrChallenge(string $url): bool
    {
        return (bool) preg_match('#^https://[^/]+/(accounts/login|challenge)#', $url);
    }

    /**
     * A plain HTTP check can be wrong (a redirect we do not understand, a
     * transport error). When INSTAGRAM_CHECK_URL names a stable reel, ask
     * yt-dlp, which is what the download job uses, and trust that.
     */
    private function confirmWithYtDlp(InstagramCheckResult $result, string $cookiesPath): InstagramCheckResult
    {
        $url = (string) config('services.instagram.check_url', '');

        if ($result->isOk() || $url === '' || ! in_array($result->status, [InstagramCheckStatus::CookiesInvalid, InstagramCheckStatus::Inconclusive], true)) {
            return $result;
        }

        try {
            $process = Process::timeout((int) config('services.slack.ytdlp_timeout', 40))->run([
                (string) config('services.slack.ytdlp_binary', 'yt-dlp'),
                '--cookies', $cookiesPath,
                '--skip-download', '--no-warnings', '--quiet', '--dump-single-json',
                $url,
            ]);
        } catch (Throwable $e) {
            Log::warning('slack.ig.healthcheck.ytdlp_error', ['exception' => $e->getMessage()]);

            return $result;
        }

        if ($process->successful()) {
            Log::info('slack.ig.healthcheck.ytdlp_confirmed', ['after' => $result->status->value]);

            return new InstagramCheckResult(
                InstagramCheckStatus::Ok,
                'yt-dlp read the check reel with the cookies (the HTTP check said: '.$result->message.')',
            );
        }

        return new InstagramCheckResult(
            $result->status,
            $result->message.' yt-dlp on the check reel failed too.',
        );
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

        if ($response->redirect()) {
            return $this->classifyRedirect($response);
        }

        if (in_array($response->status(), [401, 403], true) || ($body['require_login'] ?? false) === true) {
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

    /**
     * Only a redirect to the login page counts as a rejection. Any other
     * redirect we could not follow is reported with its Location, so the
     * message says what Instagram answered instead of guessing.
     */
    private function classifyRedirect(Response $response): InstagramCheckResult
    {
        $location = (string) $response->header('Location');
        $path = (string) parse_url($location, PHP_URL_PATH);

        if (str_starts_with($path, '/challenge')) {
            return new InstagramCheckResult(
                InstagramCheckStatus::CheckpointRequired,
                'Instagram wants a checkpoint (challenge) for this account.',
            );
        }

        if (str_starts_with($path, '/accounts/login')) {
            return new InstagramCheckResult(
                InstagramCheckStatus::CookiesInvalid,
                'Instagram rejected the cookies (HTTP '.$response->status().', redirected to the login page).',
            );
        }

        return new InstagramCheckResult(
            InstagramCheckStatus::Inconclusive,
            'Instagram answered HTTP '.$response->status().' with Location `'.($location === '' ? '(none)' : $location).'`, cannot tell if the cookies work.',
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

        $status = $this->jar->refreshStatus(waitForCode: false);

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
     * The cookie file the download job would use. With credentials that is
     * the jar's own file, and a missing or stale one (older than
     * INSTAGRAM_COOKIES_TTL) means this check logs in first (the download job
     * never does), so null here. Without
     * credentials it is only the exported file from YTDLP_COOKIES_PATH, and
     * a leftover jar file is ignored.
     */
    private function cookieFile(): ?string
    {
        if ($this->jar->credentials() !== null) {
            $age = $this->jar->cookiesAgeSeconds();
            $ttl = (int) config('services.instagram.cookies_ttl', 60 * 60 * 24 * 7);

            return $age !== null && $age <= $ttl ? $this->jar->cookiesPath() : null;
        }

        $exported = (string) config('services.slack.ytdlp_cookies', '');

        return $exported !== '' && is_file($exported) && filesize($exported) > 0 ? $exported : null;
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
