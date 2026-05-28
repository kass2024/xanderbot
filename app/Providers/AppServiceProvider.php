<?php

namespace App\Providers;

use App\Support\EnsureAdsSchema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $logDir = storage_path('logs');
        if (! is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        foreach (['webhook-hits.log', 'webhook.log', 'laravel.log'] as $name) {
            $path = $logDir.DIRECTORY_SEPARATOR.$name;
            if (! is_file($path)) {
                @touch($path);
            }
        }

        if (! $this->app->runningInConsole()) {
            EnsureAdsSchema::run();
        }
    }
}
