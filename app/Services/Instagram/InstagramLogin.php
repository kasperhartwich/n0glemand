<?php

namespace App\Services\Instagram;

interface InstagramLogin
{
    /**
     * Logs in and returns the outcome. On success the result carries the
     * session cookies in Netscape format. It never throws: any failure is
     * an `Unknown` result with a message.
     */
    public function login(string $username, string $password): InstagramLoginResult;
}
