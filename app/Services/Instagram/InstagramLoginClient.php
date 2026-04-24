<?php

namespace App\Services\Instagram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class InstagramLoginClient
{
    private const LOGIN_PAGE_URL = 'https://www.instagram.com/accounts/login/';

    private const LOGIN_AJAX_URL = 'https://www.instagram.com/accounts/login/ajax/';

    private const IG_APP_ID = '936619743392459';

    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    public function __construct(
        private NetscapeCookieFormatter $formatter,
    ) {}

    public function login(string $username, string $password): InstagramLoginResult
    {
        try {
            $bootstrap = $this->fetchLoginPage();

            $cookies = $this->collectSetCookies($bootstrap);

            $csrfToken = $cookies['csrftoken']['value'] ?? null;

            if ($csrfToken === null) {
                return new InstagramLoginResult(
                    InstagramLoginStatus::Unknown,
                    errorMessage: 'Instagram did not return a csrftoken cookie.',
                );
            }

            $login = $this->submitLogin($username, $password, $csrfToken, $cookies);

            $cookies = array_merge($cookies, $this->collectSetCookies($login));

            return $this->classify($login, $cookies);
        } catch (Throwable $e) {
            return new InstagramLoginResult(
                InstagramLoginStatus::Unknown,
                errorMessage: 'IG login transport error: '.$e->getMessage(),
            );
        }
    }

    private function fetchLoginPage(): Response
    {
        return Http::withHeaders([
            'User-Agent' => self::USER_AGENT,
            'Accept-Language' => 'en-US,en;q=0.9',
        ])->get(self::LOGIN_PAGE_URL);
    }

    private function submitLogin(string $username, string $password, string $csrfToken, array $cookies): Response
    {
        $encPassword = sprintf('#PWD_INSTAGRAM_BROWSER:0:%d:%s', time(), $password);

        return Http::asForm()
            ->withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => '*/*',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Origin' => 'https://www.instagram.com',
                'Referer' => self::LOGIN_PAGE_URL,
                'X-CSRFToken' => $csrfToken,
                'X-Requested-With' => 'XMLHttpRequest',
                'X-Instagram-AJAX' => '1',
                'X-IG-App-ID' => self::IG_APP_ID,
                'Cookie' => $this->buildCookieHeader($cookies),
            ])
            ->post(self::LOGIN_AJAX_URL, [
                'username' => $username,
                'enc_password' => $encPassword,
                'queryParams' => '{}',
                'optIntoOneTap' => 'false',
                'trustedDeviceRecords' => '{}',
            ]);
    }

    /**
     * @param  array<string, array{name: string, value: string, domain: string, path: string, secure: bool, expires: int}>  $cookies
     */
    private function classify(Response $response, array $cookies): InstagramLoginResult
    {
        $body = (array) $response->json();

        if (($body['two_factor_required'] ?? false) === true) {
            return new InstagramLoginResult(
                InstagramLoginStatus::TwoFactorRequired,
                errorMessage: 'Instagram requires 2FA for this account.',
            );
        }

        if (($body['message'] ?? null) === 'checkpoint_required' || isset($body['checkpoint_url'])) {
            return new InstagramLoginResult(
                InstagramLoginStatus::CheckpointRequired,
                errorMessage: 'Instagram triggered a checkpoint challenge.',
            );
        }

        if (($body['authenticated'] ?? false) === true) {
            $cookiesTxt = $this->formatter->format(array_values($cookies));

            return new InstagramLoginResult(
                InstagramLoginStatus::Ok,
                cookiesTxt: $cookiesTxt,
            );
        }

        if (($body['user'] ?? null) === false || ($body['error_type'] ?? null) === 'invalid_user' || ($body['error_type'] ?? null) === 'bad_password') {
            return new InstagramLoginResult(
                InstagramLoginStatus::InvalidCredentials,
                errorMessage: (string) ($body['message'] ?? 'Invalid Instagram credentials.'),
            );
        }

        return new InstagramLoginResult(
            InstagramLoginStatus::Unknown,
            errorMessage: (string) ($body['message'] ?? 'Unexpected Instagram login response.'),
        );
    }

    /**
     * @return array<string, array{name: string, value: string, domain: string, path: string, secure: bool, expires: int}>
     */
    private function collectSetCookies(Response $response): array
    {
        $collected = [];
        $headers = $response->headers();
        $raw = $headers['Set-Cookie'] ?? $headers['set-cookie'] ?? [];

        foreach ((array) $raw as $header) {
            $parsed = $this->parseSetCookie((string) $header);

            if ($parsed !== null) {
                $collected[$parsed['name']] = $parsed;
            }
        }

        return $collected;
    }

    /**
     * @return array{name: string, value: string, domain: string, path: string, secure: bool, expires: int}|null
     */
    private function parseSetCookie(string $header): ?array
    {
        $parts = array_map('trim', explode(';', $header));

        if ($parts === [] || ! str_contains($parts[0], '=')) {
            return null;
        }

        [$name, $value] = array_map('trim', explode('=', array_shift($parts), 2));

        if ($name === '') {
            return null;
        }

        $cookie = [
            'name' => $name,
            'value' => $value,
            'domain' => '.instagram.com',
            'path' => '/',
            'secure' => false,
            'expires' => 0,
        ];

        foreach ($parts as $attr) {
            if ($attr === '') {
                continue;
            }

            if (strcasecmp($attr, 'Secure') === 0) {
                $cookie['secure'] = true;

                continue;
            }

            if (strcasecmp($attr, 'HttpOnly') === 0) {
                continue;
            }

            if (! str_contains($attr, '=')) {
                continue;
            }

            [$k, $v] = array_map('trim', explode('=', $attr, 2));

            match (strtolower($k)) {
                'domain' => $cookie['domain'] = str_starts_with($v, '.') ? $v : '.'.ltrim($v, '.'),
                'path' => $cookie['path'] = $v === '' ? '/' : $v,
                'expires' => $cookie['expires'] = $this->parseExpires($v, $cookie['expires']),
                'max-age' => $cookie['expires'] = $this->parseMaxAge($v, $cookie['expires']),
                default => null,
            };
        }

        return $cookie;
    }

    private function parseExpires(string $raw, int $fallback): int
    {
        $ts = strtotime($raw);

        return $ts === false ? $fallback : $ts;
    }

    private function parseMaxAge(string $raw, int $fallback): int
    {
        if (! is_numeric($raw)) {
            return $fallback;
        }

        return time() + (int) $raw;
    }

    /**
     * @param  array<string, array{name: string, value: string, domain: string, path: string, secure: bool, expires: int}>  $cookies
     */
    private function buildCookieHeader(array $cookies): string
    {
        return collect($cookies)
            ->map(fn (array $c) => $c['name'].'='.$c['value'])
            ->implode('; ');
    }
}
