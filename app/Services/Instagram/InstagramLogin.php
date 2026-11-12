<?php

namespace App\Services\Instagram;

interface InstagramLogin
{
    /**
     * Logs in and returns the outcome. On success the result carries the
     * session cookies in Netscape format. It never throws: any failure is
     * an `Unknown` result with a message. With `$forceFresh` a driver that keeps
     * its own session (the browser profile) must not reuse it: the cookies it
     * holds were just reported dead.
     */
    public function login(string $username, string $password, bool $forceFresh = false): InstagramLoginResult;
}
