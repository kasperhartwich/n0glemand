<?php

namespace App\Providers;

use App\Services\Slack\Handlers\InstagramLinkHandler;
use App\Services\Slack\Handlers\SpotifyLinkHandler;
use App\Services\Slack\SlackClient;
use App\Services\Slack\SlackEventRouter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class SlackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SlackClient::class, fn (Application $app) => new SlackClient(
            botToken: (string) $app['config']->get('services.slack.bot_token', ''),
            apiBase: (string) $app['config']->get('services.slack.api_base', 'https://slack.com/api'),
        ));

        $this->app->tag([
            InstagramLinkHandler::class,
            SpotifyLinkHandler::class,
        ], 'slack.handlers');

        $this->app->bind(
            SlackEventRouter::class,
            fn (Application $app) => new SlackEventRouter($app->tagged('slack.handlers')),
        );
    }
}
