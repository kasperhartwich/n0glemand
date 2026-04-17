<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('services.slack.signing_secret', 'test-secret');
    config()->set('services.slack.signature_tolerance', 300);

    Log::spy();

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
        content: 'command=/n0glemand&text=help',
    )->assertStatus(401);
});

it('returns the help text when called with no text', function () {
    $response = postSlackCommand([
        'command' => '/n0glemand',
        'text' => '',
        'user_id' => 'U1',
        'channel_id' => 'C1',
    ]);

    $response->assertOk()
        ->assertJsonPath('response_type', 'ephemeral');

    expect($response->json('text'))
        ->toContain('/n0glemand logs')
        ->toContain('!n0g ping');
});

it('returns the help text when called with `help`', function () {
    $response = postSlackCommand([
        'command' => '/n0glemand',
        'text' => 'help',
    ]);

    expect($response->json('text'))
        ->toContain('/n0glemand logs')
        ->toContain('!n0g ping');
});

it('returns an ephemeral message with the log tail', function () {
    $path = storage_path('logs/laravel.log');
    $lines = array_map(fn ($i) => "line-{$i}", range(1, 80));
    file_put_contents($path, implode("\n", $lines)."\n");

    $response = postSlackCommand([
        'command' => '/n0glemand',
        'text' => 'logs 5',
        'user_id' => 'U1',
        'channel_id' => 'C1',
    ]);

    $response->assertOk()
        ->assertJsonPath('response_type', 'ephemeral');

    expect($response->json('text'))
        ->toContain('line-76')
        ->toContain('line-80')
        ->not->toContain('line-75');
});

it('defaults to 50 log lines when no count is given', function () {
    $path = storage_path('logs/laravel.log');
    $lines = array_map(fn ($i) => "line-{$i}", range(1, 100));
    file_put_contents($path, implode("\n", $lines)."\n");

    $response = postSlackCommand([
        'command' => '/n0glemand',
        'text' => 'logs',
    ]);

    expect($response->json('text'))
        ->toContain('line-51')
        ->toContain('line-100')
        ->not->toContain('line-50');
});

it('responds gracefully when the log file does not exist', function () {
    $response = postSlackCommand([
        'command' => '/n0glemand',
        'text' => 'logs',
    ]);

    $response->assertOk()
        ->assertJsonPath('text', 'No log file found at `storage/logs/laravel.log`.');
});

it('rejects unknown subcommands with a hint', function () {
    $response = postSlackCommand([
        'command' => '/n0glemand',
        'text' => 'mystery',
    ]);

    expect($response->json('text'))
        ->toContain('Unknown subcommand')
        ->toContain('/n0glemand help');
});

it('rejects unknown top-level commands', function () {
    $response = postSlackCommand([
        'command' => '/other',
        'text' => '',
    ]);

    $response->assertOk()
        ->assertJsonPath('text', 'Unknown command `/other`.');
});
