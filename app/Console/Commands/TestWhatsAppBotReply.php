<?php

namespace App\Console\Commands;

use App\Services\Chatbot\ChatbotProcessor;
use App\Services\Chatbot\MessageDispatcher;
use App\Services\WhatsApp\PlatformResolver;
use Illuminate\Console\Command;

class TestWhatsAppBotReply extends Command
{
    protected $signature = 'whatsapp:test-bot
                            {phone : E.164 digits only, e.g. 254712345678}
                            {--text=Hello : Message to simulate}';

    protected $description = 'Send a plain-text FAQ bot reply (no template) — verifies outbound WhatsApp API';

    public function handle(
        PlatformResolver $resolver,
        ChatbotProcessor $processor,
        MessageDispatcher $dispatcher
    ): int {
        $platform = $resolver->ensureFromEnv();
        if (! $platform) {
            $this->error('Run whatsapp:ensure-platform first.');

            return self::FAILURE;
        }

        $phone = preg_replace('/\D+/', '', (string) $this->argument('phone')) ?: '';
        if (strlen($phone) < 10) {
            $this->error('Invalid phone number.');

            return self::FAILURE;
        }

        $clientId = (int) (\App\Models\Client::where('user_id', $platform->connected_by)->value('id')
            ?: \App\Models\Client::query()->orderBy('id')->value('id'));

        if ($clientId <= 0) {
            $this->error('No client row — run whatsapp:ensure-platform again.');

            return self::FAILURE;
        }

        $text = (string) $this->option('text');
        $response = $processor->process([
            'from' => $phone,
            'text' => $text,
            'client_id' => $clientId,
            'message_id' => 'cli-test-'.time(),
            'source' => 'organic',
        ]);

        if (empty($response['text'])) {
            $this->error('Chatbot returned empty text.');

            return self::FAILURE;
        }

        $this->line('Bot text: '.mb_substr($response['text'], 0, 200).'…');

        $results = $dispatcher->send($platform, $phone, $response);
        $ok = false;
        foreach ($results as $r) {
            if (! empty($r['success'])) {
                $ok = true;
            }
            $this->line(json_encode($r));
        }

        if ($ok) {
            $this->info('Plain-text reply sent (no template required).');

            return self::SUCCESS;
        }

        $this->error('Send failed — check WHATSAPP_ACCESS_TOKEN and storage/logs/laravel.log');

        return self::FAILURE;
    }
}
