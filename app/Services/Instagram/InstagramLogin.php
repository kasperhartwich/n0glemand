<?php

namespace App\Services\Instagram;

interface InstagramLogin
{
    /**
     * Logs in and returns the outcome. On success the result carries the
     * session cookies in Netscape format. It never throws: any failure is
     * an `Unknown` result with a message. With `$forceFresh` a driver that keeps
     * its own session (the browser profile) must not reuse it: the cookies it
     * holds were just reported dead. With `$waitForCode` false a challenge ends
     * the login as `CheckpointRequired` at once instead of waiting for an admin
     * to hand over a code (for callers with a short time budget).
     */
    public function login(string $username, string $password, bool $forceFresh = false, bool $waitForCode = true): InstagramLoginResult;
}
