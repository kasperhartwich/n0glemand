<?php

use App\Http\Middleware\VerifySlackSignature;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('services.slack.signing_secret', 'test-secret');
    config()->set('services.slack.signature_tolerance', 300);

    Route::post('/test-slack-signature', fn () => response()->json(['ok' => true]))
        ->middleware(VerifySlackSignature::class)
        ->withoutMiddleware(PreventRequestForgery::class);
});

function signSlack(string $body, string $secret = 'test-secret', ?int $ts = null): array
{
    $ts ??= time();
    $signature = 'v0='.hash_hmac('sha256', "v0:{$ts}:{$body}", $secret);

    return ['timestamp' => (string) $ts, 'signature' => $signature];
}

it('rejects requests with a missing signature header', function () {
    $this->post('/test-slack-signature', [], [
        'X-Slack-Request-Timestamp' => (string) time(),
    ])->assertStatus(401)->assertJson(['error' => 'invalid_signature']);
});

it('rejects requests outside the 5-minute window', function () {
    $body = json_encode(['hello' => 'world']);
    $headers = signSlack($body, ts: time() - 600);

    $this->call(
        method: 'POST',
        uri: '/test-slack-signature',
        parameters: [],
        cookies: [],
        files: [],
        server: [
            'HTTP_X-Slack-Request-Timestamp' => $headers['timestamp'],
            'HTTP_X-Slack-Signature' => $headers['signature'],
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $body,
    )->assertStatus(401);
});

it('rejects tampered bodies', function () {
    $body = json_encode(['hello' => 'world']);
    $headers = signSlack($body);

    $tamperedBody = json_encode(['hello' => 'tampered']);

    $this->call(
        method: 'POST',
        uri: '/test-slack-signature',
        parameters: [],
        cookies: [],
        files: [],
        server: [
            'HTTP_X-Slack-Request-Timestamp' => $headers['timestamp'],
            'HTTP_X-Slack-Signature' => $headers['signature'],
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $tamperedBody,
    )->assertStatus(401);
});

it('allows valid signed requests through', function () {
    $body = json_encode(['hello' => 'world']);
    $headers = signSlack($body);

    $this->call(
        method: 'POST',
        uri: '/test-slack-signature',
        parameters: [],
        cookies: [],
        files: [],
        server: [
            'HTTP_X-Slack-Request-Timestamp' => $headers['timestamp'],
            'HTTP_X-Slack-Signature' => $headers['signature'],
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $body,
    )->assertOk()->assertJson(['ok' => true]);
});
