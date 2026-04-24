<?php

use App\Services\Instagram\NetscapeCookieFormatter;

it('emits a header and one tab-separated line per cookie', function () {
    $formatter = new NetscapeCookieFormatter;

    $out = $formatter->format([
        ['domain' => '.instagram.com', 'path' => '/', 'secure' => true, 'expires' => 1234567890, 'name' => 'sessionid', 'value' => 'abc'],
        ['domain' => 'instagram.com', 'name' => 'csrftoken', 'value' => 'def'],
    ]);

    expect($out)->toStartWith("# Netscape HTTP Cookie File\n");
    expect($out)->toContain(".instagram.com\tTRUE\t/\tTRUE\t1234567890\tsessionid\tabc");
    expect($out)->toContain("instagram.com\tFALSE\t/\tTRUE\t0\tcsrftoken\tdef");
});

it('skips cookies missing a name or domain', function () {
    $formatter = new NetscapeCookieFormatter;

    $out = $formatter->format([
        ['domain' => '.instagram.com', 'name' => '', 'value' => 'x'],
        ['domain' => '', 'name' => 'sessionid', 'value' => 'x'],
        ['domain' => '.instagram.com', 'name' => 'ok', 'value' => 'y'],
    ]);

    expect($out)->toContain("\tok\ty");
    expect(substr_count($out, "\t"))->toBe(6); // one cookie line = 6 tabs
});
