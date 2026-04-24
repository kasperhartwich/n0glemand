<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
        'bot_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
        'signing_secret' => env('SLACK_SIGNING_SECRET'),
        'api_base' => 'https://slack.com/api',
        'signature_tolerance' => 60 * 5,
        'event_dedupe_ttl' => 60 * 10,
        'max_upload_bytes' => 900 * 1024 * 1024,
        'ytdlp_binary' => env('YTDLP_BINARY', 'yt-dlp'),
        'ytdlp_timeout' => 120,
        'ytdlp_cookies' => env('YTDLP_COOKIES_PATH'),
    ],

    'instagram' => [
        'accounts' => json_decode((string) env('INSTAGRAM_ACCOUNTS', '[]'), true) ?: [],
        'cookies_ttl' => (int) env('INSTAGRAM_COOKIES_TTL', 60 * 60 * 24 * 7),
        'login_cooldown' => (int) env('INSTAGRAM_LOGIN_COOLDOWN', 60 * 5),
    ],

];
