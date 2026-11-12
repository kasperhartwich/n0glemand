<?php

namespace App\Services\Instagram;

/**
 * The text of `instagram status`: cookie file age and login cooldown, never
 * a secret. Shared by the slash command and the admin direct messages.
 */
class InstagramStatusReport
{
    public function __construct(
        private InstagramCookieJar $jar,
    ) {}

    public function text(): string
    {
        $age = $this->jar->cookiesAgeSeconds();
        $ttl = (int) config('services.instagram.cookies_ttl', 60 * 60 * 24 * 7);
        $cooldown = $this->jar->cooldownStatus();

        return implode("\n", [
            '*Instagram status*',
            '• Credentials: '.($this->jar->credentials() === null ? 'not configured' : 'configured'),
            '• Cookie file: '.($age === null
                ? 'missing'
                : 'present, '.$this->humanSeconds($age).' old'.($age > $ttl ? ' (expired)' : '')),
            '• Login cooldown: '.($cooldown === null ? 'not active' : "active after `{$cooldown}`"),
        ]);
    }

    private function humanSeconds(int $seconds): string
    {
        return match (true) {
            $seconds < 120 => "{$seconds} s",
            $seconds < 7200 => intdiv($seconds, 60).' min',
            $seconds < 172800 => intdiv($seconds, 3600).' h',
            default => intdiv($seconds, 86400).' days',
        };
    }
}
