<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Forwards WhatsApp events from the Meta webhook app to cPanel pre-screening (xanderglobalscholars.com).
 * Meta webhook URL stays on this app — cPanel runs the pre-screening flow and delivery tracking.
 */
class PrescreeningBridge
{
    public function isEnabled(): bool
    {
        $url = trim((string) config('services.prescreening.cpanel_url'));
        $secret = trim((string) config('services.prescreening.forward_secret'));

        return $url !== '' && $secret !== '';
    }

    /**
     * Quick checks before calling cPanel active_session.
     */
    public function shouldTryPrescreening(array $incoming): bool
    {
        $type = (string) ($incoming['type'] ?? '');

        if ($type === 'button') {
            return true;
        }

        if (in_array($type, ['image', 'document', 'audio', 'video', 'sticker'], true)) {
            return true;
        }

        $text = strtolower(trim($this->extractText($incoming)));

        if ($text === '') {
            return false;
        }

        if (in_array($text, ['start', 'cancel', 'stop', 'yes', 'begin', 'ok', 'okay', 'quit', 'end'], true)) {
            return true;
        }

        foreach (['prescreening', 'pre-screening', 'prescreen', 'screening'] as $kw) {
            if ($text === $kw || str_contains($text, $kw)) {
                return true;
            }
        }

        return false;
    }

    public function hasActiveSession(string $from): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $digits = preg_replace('/\D+/', '', $from) ?? '';
        if ($digits === '') {
            return false;
        }

        $resp = $this->post([
            'action' => 'active_session',
            'from' => $digits,
        ]);

        return is_array($resp) && ! empty($resp['active']);
    }

    /**
     * @return array{handled:bool,duplicate?:bool}|null
     */
    public function forwardMessage(string $from, array $message): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $from) ?? '';
        if ($digits === '') {
            return null;
        }

        return $this->post([
            'action' => 'handle',
            'from' => $digits,
            'message' => $message,
        ]);
    }

    public function forwardDeliveryStatus(array $status): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $wamid = trim((string) ($status['id'] ?? ''));
        $delivery = strtolower(trim((string) ($status['status'] ?? '')));
        $recipient = preg_replace('/\D+/', '', (string) ($status['recipient_id'] ?? '')) ?? '';

        if ($delivery === '' || $recipient === '') {
            return;
        }

        $this->post([
            'action' => 'delivery_status',
            'wamid' => $wamid,
            'status' => $delivery,
            'recipient_id' => $recipient,
            'errors' => $status['errors'] ?? [],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function post(array $payload): ?array
    {
        $url = trim((string) config('services.prescreening.cpanel_url'));
        $secret = trim((string) config('services.prescreening.forward_secret'));

        if ($url === '' || $secret === '') {
            return null;
        }

        $payload['secret'] = $secret;

        try {
            $response = Http::timeout(25)
                ->withHeaders(['X-Xander-Forward-Secret' => $secret])
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);

            if ($response->failed()) {
                Log::channel('webhook')->warning('prescreening.bridge.http_failed', [
                    'status' => $response->status(),
                    'action' => $payload['action'] ?? 'handle',
                ]);

                return null;
            }

            $json = $response->json();

            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            Log::channel('webhook')->error('prescreening.bridge.exception', [
                'action' => $payload['action'] ?? 'handle',
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function extractText(array $message): string
    {
        $type = (string) ($message['type'] ?? '');

        return match ($type) {
            'text' => trim((string) ($message['text']['body'] ?? '')),
            'button' => trim((string) ($message['button']['text'] ?? $message['button']['payload'] ?? '')),
            'interactive' => trim((string) (
                $message['interactive']['button_reply']['title']
                ?? $message['interactive']['button_reply']['id']
                ?? $message['interactive']['list_reply']['title']
                ?? $message['interactive']['list_reply']['id']
                ?? ''
            )),
            default => '',
        };
    }
}
