<?php

use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('services.slack.signing_secret', 'test-secret');
    config()->set('services.slack.signature_tolerance', 300);

    $path = storage_path('logs/laravel.log');
    @mkdir(dirname($path), 0755, true);
    @unlink($path);
});

afterEach(function () {
    @unlink(storage_path('logs/laravel.log'));
});

function postSlackCommand(array $form, string $secret = 'test-secret', ?int $ts = null): TestResponse
{
    $body = http_build_query($form);
    $ts ??= time();
    $signature = 'v0='.hash_hmac('sha256', "v0:{$ts}:{$body}", $secret);

    return test()->call(
        method: 'POST',
        uri: '/slack/commands',
        parameters: $form,
        cookies: [],
        files: [],
        server: [
            'HTTP_X-Slack-Request-Timestamp' => (string) $ts,
            'HTTP_X-Slack-Signature' => $signature,
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ],
        content: $body,
    );
}

it('rejects commands with a bad signature', function () {
    test()->call(
        method: 'POST',
        uri: '/slack/commands',
        parameters: [],
        cookies: [],
        files: [],
        server: [
            'HTTP_X-Slack-Request-Timestamp' => (string) time(),
            'HTTP_X-Slack-Signature' => 'v0=bad',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ],
        content: 'command=/n0g-logs&text=',
    )->assertStatus(401);
});

it('returns an ephemeral message with the log tail', function () {
    $path = storage_path('logs/laravel.log');
    $lines = array_map(fn ($i) => "line-{$i}", range(1, 80));
    file_put_contents($path, implode("\n", $lines)."\n");

    $response = postSlackCommand([
        'command' => '/n0g-logs',
        'text' => '5',
        'user_id' => 'U1',
        'channel_id' => 'C1',
    ]);

    $response->assertOk()
        ->assertJsonPath('response_type', 'ephemeral')
        ->assertJsonFragment(['response_type' => 'ephemeral']);

    $text = $response->json('text');

    expect($text)->toContain('line-76')
        ->toContain('line-80')
        ->not->toContain('line-75');
});

it('responds gracefully when the log file does not exist', function () {
    $response = postSlackCommand([
        'command' => '/n0g-logs',
        'text' => '',
        'user_id' => 'U1',
        'channel_id' => 'C1',
    ]);

    $response->assertOk()
        ->assertJsonPath('text', 'No log file found at `storage/logs/laravel.log`.');
});

it('rejects unknown commands', function () {
    $response = postSlackCommand([
        'command' => '/mystery',
        'text' => '',
        'user_id' => 'U1',
        'channel_id' => 'C1',
    ]);

    $response->assertOk()
        ->assertJsonPath('text', 'Unknown command `/mystery`.');
});
