<?php

namespace App\Services\Instagram;

enum InstagramLoginStatus: string
{
    case Ok = 'ok';
    case InvalidCredentials = 'invalid_credentials';
    case TwoFactorRequired = 'two_factor_required';
    case CheckpointRequired = 'checkpoint_required';
    case Unknown = 'unknown';
}
