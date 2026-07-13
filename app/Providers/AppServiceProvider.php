<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\Tenant\TenantConnectionResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->ensureWebhookLogFileExists();
        $this->ensureStorageDirectoriesExist();
    }

    /**
     * Create storage/logs/webhook.log on deploy so `tail -f` works before the first event.
     */
    private function ensureWebhookLogFileExists(): void
    {
        $path = storage_path('logs/webhook.log');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (! is_file($path) && is_writable($dir)) {
            @file_put_contents($path, '');
        }
    }

    /**
     * Avoid file_put_contents Permission denied on first cache write after deploy.
     */
    private function ensureStorageDirectoriesExist(): void
    {
        foreach ([
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ] as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }
}
