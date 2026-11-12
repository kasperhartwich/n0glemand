<?php

namespace App\Providers;

use App\Services\Instagram\InstagramBrowserLoginClient;
use App\Services\Instagram\InstagramLogin;
use App\Services\Instagram\InstagramLoginClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(InstagramLogin::class, fn (Application $app): InstagramLogin => match ($app['config']->get('services.instagram.login_driver')) {
            'browser' => $app->make(InstagramBrowserLoginClient::class),
            default => $app->make(InstagramLoginClient::class),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
