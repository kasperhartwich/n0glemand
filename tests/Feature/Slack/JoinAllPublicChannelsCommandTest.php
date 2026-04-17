<?php

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.slack.bot_token', 'xoxb-test');
    config()->set('services.slack.api_base', 'https://slack.test/api');
});

it('pages through conversations.list and joins each channel', function () {
    Http::fakeSequence('slack.test/api/conversations.list*')
        ->push([
            'ok' => true,
            'channels' => [
                ['id' => 'C1', 'name' => 'general'],
                ['id' => 'C2', 'name' => 'random'],
            ],
            'response_metadata' => ['next_cursor' => 'page2'],
        ])
        ->push([
            'ok' => true,
            'channels' => [
                ['id' => 'C3', 'name' => 'ops'],
            ],
            'response_metadata' => ['next_cursor' => ''],
        ]);

    Http::fake([
        'slack.test/api/conversations.join' => Http::response(['ok' => true]),
    ]);

    $this->artisan('slack:join-all-public', ['--sleep' => 0])
        ->expectsOutputToContain('joined #general')
        ->expectsOutputToContain('joined #random')
        ->expectsOutputToContain('joined #ops')
        ->expectsOutputToContain('Joined: 3, already in: 0, failed: 0')
        ->assertSuccessful();

    Http::assertSentCount(5);
});

it('reports channels the bot is already in without counting them as joined', function () {
    Http::fakeSequence('slack.test/api/conversations.list*')
        ->push([
            'ok' => true,
            'channels' => [['id' => 'C1', 'name' => 'general']],
            'response_metadata' => ['next_cursor' => ''],
        ]);

    Http::fake([
        'slack.test/api/conversations.join' => Http::response([
            'ok' => false,
            'error' => 'already_in_channel',
        ]),
    ]);

    $this->artisan('slack:join-all-public', ['--sleep' => 0])
        ->expectsOutputToContain('already in #general')
        ->expectsOutputToContain('Joined: 0, already in: 1, failed: 0')
        ->assertSuccessful();
});

it('continues on per-channel failure and exits with non-zero', function () {
    Http::fakeSequence('slack.test/api/conversations.list*')
        ->push([
            'ok' => true,
            'channels' => [
                ['id' => 'C1', 'name' => 'good'],
                ['id' => 'C2', 'name' => 'bad'],
            ],
            'response_metadata' => ['next_cursor' => ''],
        ]);

    Http::fakeSequence('slack.test/api/conversations.join')
        ->push(['ok' => true])
        ->push(['ok' => false, 'error' => 'channel_not_found']);

    $this->artisan('slack:join-all-public', ['--sleep' => 0])
        ->expectsOutputToContain('joined #good')
        ->expectsOutputToContain('#bad')
        ->expectsOutputToContain('Joined: 1, already in: 0, failed: 1')
        ->assertFailed();
});
