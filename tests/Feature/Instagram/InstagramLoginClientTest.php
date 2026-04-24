<?php

use App\Services\Instagram\InstagramLoginClient;
use App\Services\Instagram\InstagramLoginStatus;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function fakeBootstrap(): PromiseInterface
{
    return Http::response('<html>login</html>', 200, [
        'Set-Cookie' => [
            'csrftoken=tok123; Path=/; Domain=.instagram.com; Secure',
            'mid=MIDMID; Path=/; Domain=.instagram.com; Secure',
        ],
    ]);
}

it('returns Ok with cookies.txt when Instagram authenticates', function () {
    Http::fake([
        'https://www.instagram.com/accounts/login/' => fakeBootstrap(),
        'https://www.instagram.com/accounts/login/ajax/' => Http::response(
            ['authenticated' => true, 'user' => true, 'userId' => '42', 'status' => 'ok'],
            200,
            [
                'Set-Cookie' => [
                    'sessionid=SESSX; Path=/; Domain=.instagram.com; Secure; HttpOnly',
                    'ds_user_id=42; Path=/; Domain=.instagram.com; Secure',
                ],
            ],
        ),
    ]);

    $result = app(InstagramLoginClient::class)->login('kasper', 'hunter2');

    expect($result->status)->toBe(InstagramLoginStatus::Ok);
    expect($result->cookiesTxt)->toContain('sessionid')
        ->and($result->cookiesTxt)->toContain('SESSX')
        ->and($result->cookiesTxt)->toContain('ds_user_id')
        ->and($result->cookiesTxt)->toContain('csrftoken');

    Http::assertSent(fn (Request $req) => $req->url() === 'https://www.instagram.com/accounts/login/ajax/'
        && $req->hasHeader('X-CSRFToken', 'tok123')
        && $req->hasHeader('X-IG-App-ID')
        && str_contains((string) $req->header('Cookie')[0], 'csrftoken=tok123')
        && str_starts_with($req->data()['enc_password'] ?? '', '#PWD_INSTAGRAM_BROWSER:0:')
        && $req->data()['username'] === 'kasper'
    );
});

it('returns InvalidCredentials when Instagram rejects the password', function () {
    Http::fake([
        'https://www.instagram.com/accounts/login/' => fakeBootstrap(),
        'https://www.instagram.com/accounts/login/ajax/' => Http::response([
            'authenticated' => false,
            'user' => true,
            'error_type' => 'bad_password',
            'message' => 'Sorry, your password was incorrect.',
            'status' => 'fail',
        ]),
    ]);

    $result = app(InstagramLoginClient::class)->login('kasper', 'wrong');

    expect($result->status)->toBe(InstagramLoginStatus::InvalidCredentials);
    expect($result->cookiesTxt)->toBeNull();
});

it('returns TwoFactorRequired when Instagram demands 2FA', function () {
    Http::fake([
        'https://www.instagram.com/accounts/login/' => fakeBootstrap(),
        'https://www.instagram.com/accounts/login/ajax/' => Http::response([
            'two_factor_required' => true,
            'two_factor_info' => ['totp_two_factor_on' => true],
            'status' => 'fail',
        ]),
    ]);

    $result = app(InstagramLoginClient::class)->login('kasper', 'pw');

    expect($result->status)->toBe(InstagramLoginStatus::TwoFactorRequired);
    expect($result->cookiesTxt)->toBeNull();
});

it('returns CheckpointRequired when Instagram triggers a challenge', function () {
    Http::fake([
        'https://www.instagram.com/accounts/login/' => fakeBootstrap(),
        'https://www.instagram.com/accounts/login/ajax/' => Http::response([
            'message' => 'checkpoint_required',
            'checkpoint_url' => '/challenge/xyz/',
            'lock' => false,
            'status' => 'fail',
        ]),
    ]);

    $result = app(InstagramLoginClient::class)->login('kasper', 'pw');

    expect($result->status)->toBe(InstagramLoginStatus::CheckpointRequired);
});

it('returns Unknown when the login page does not set csrftoken', function () {
    Http::fake([
        'https://www.instagram.com/accounts/login/' => Http::response('<html>login</html>', 200),
        'https://www.instagram.com/accounts/login/ajax/' => Http::response(['authenticated' => true]),
    ]);

    $result = app(InstagramLoginClient::class)->login('kasper', 'pw');

    expect($result->status)->toBe(InstagramLoginStatus::Unknown);
});
