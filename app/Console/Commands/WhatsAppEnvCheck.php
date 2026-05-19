<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\PlatformMetaConnection;
use App\Services\WhatsApp\PlatformResolver;
use Illuminate\Console\Command;

class WhatsAppEnvCheck extends Command
{
    protected $signature = 'whatsapp:check-env';

    protected $description = 'Verify VPS .env has everything needed for inbound webhooks + FAQ bot replies';

    public function handle(PlatformResolver $resolver): int
    {
        $ok = true;

        $this->info('=== WhatsApp / webhook env check ===');

        $checks = [
            'APP_URL' => config('app.url'),
            'WHATSAPP_PHONE_NUMBER_ID' => config('services.whatsapp.phone_number_id'),
            'WHATSAPP_ACCESS_TOKEN' => config('services.whatsapp.access_token') ? '[set]' : '',
            'WHATSAPP_VERIFY_TOKEN' => config('services.whatsapp_webhook.verify_token') ? '[set]' : '',
            'WHATSAPP_APP_SECRET' => config('services.whatsapp_webhook.app_secret') ? '[set]' : '',
            'CHATBOT_REQUIRE_PROFILE_ONBOARDING' => config('chatbot.require_profile_onboarding') ? 'true' : 'false',
            'QUEUE_CONNECTION' => config('queue.default'),
            'PRESCREENING_FORWARD_SECRET' => config('prescreening.forward_secret') ? '[set]' : '',
            'XANDER_PRESCREENING_URL' => config('prescreening.forward_url'),
        ];

        foreach ($checks as $key => $value) {
            $empty = $value === '' || $value === null;
            if ($empty && ! in_array($key, ['PRESCREENING_FORWARD_SECRET'], true)) {
                $this->error("MISSING: {$key}");
                $ok = false;
            } else {
                $this->line("OK  {$key} = ".(is_string($value) && strlen($value) > 60 ? substr($value, 0, 40).'…' : $value));
            }
        }

        if (config('queue.default') !== 'sync') {
            $this->warn('Recommend QUEUE_CONNECTION=sync for instant bot replies (you have: '.config('queue.default').')');
        }

        if (config('chatbot.require_profile_onboarding')) {
            $this->warn('CHATBOT_REQUIRE_PROFILE_ONBOARDING=true — Hello will ask for name first');
        }

        $platform = $resolver->resolve((string) config('services.whatsapp.phone_number_id'));
        if ($platform) {
            $this->info("OK  platform_meta_connections #{$platform->id}");
        } else {
            $this->error('MISSING: platform link — run php artisan whatsapp:ensure-platform');
            $ok = false;
        }

        $clients = Client::count();
        $this->line("OK  clients table rows: {$clients}");

        $hits = storage_path('logs/webhook-hits.log');
        if (is_readable($hits)) {
            $lines = file($hits, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $last = $lines !== [] ? end($lines) : '(empty)';
            $this->line('Last webhook-hits.log entry: '.$last);
            if (is_string($last) && ! str_contains($last, now()->format('Y-m-d'))) {
                $this->error('No webhook hit TODAY — Meta is NOT POSTing to xanderbot.site');
                $this->line('  Fix: Meta Developer → WhatsApp → Configuration');
                $this->line('  Callback URL: https://xanderbot.site/api/webhook/meta');
                $this->line('  Or cPanel .env: XANDERBOT_WEBHOOK_URL=https://xanderbot.site/api/webhook/meta');
                $ok = false;
            }
        }

        $this->newLine();
        $this->line('Meta webhook must be: '.url('/api/webhook/meta'));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
