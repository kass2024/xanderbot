<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\PlatformResolver;
use Illuminate\Console\Command;

class EnsureWhatsAppPlatform extends Command
{
    protected $signature = 'whatsapp:ensure-platform';

    protected $description = 'Create/update platform_meta_connections from WHATSAPP_* in .env (required for webhook replies)';

    public function handle(PlatformResolver $resolver): int
    {
        $platform = $resolver->ensureFromEnv();

        if (! $platform) {
            $this->error('Could not link platform. Set WHATSAPP_PHONE_NUMBER_ID, WHATSAPP_ACCESS_TOKEN, and ensure at least one user exists.');

            return self::FAILURE;
        }

        $this->info("Linked platform #{$platform->id} → phone {$platform->whatsapp_phone_number_id}");
        $this->comment('Run on server after deploy: php artisan config:cache && php artisan whatsapp:ensure-platform');

        return self::SUCCESS;
    }
}
