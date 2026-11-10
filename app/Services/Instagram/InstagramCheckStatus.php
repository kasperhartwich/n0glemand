<?php

namespace App\Services\Instagram;

enum InstagramCheckStatus: string
{
    case Ok = 'ok';
    case NoCookies = 'no_cookies';
    case CookiesInvalid = 'cookies_invalid';
    case CheckpointRequired = 'checkpoint_required';
    case LoginFailed = 'login_failed';
    case Inconclusive = 'inconclusive';
}
