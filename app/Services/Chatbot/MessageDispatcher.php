<?php

namespace App\Services\Chatbot;

use App\Models\PlatformMetaConnection;
use App\Support\WhatsAppTracker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class MessageDispatcher
{
    protected int $timeout = 20;

    /*
    |--------------------------------------------------------------------------
    | PUBLIC SEND METHOD (TEXT + ATTACHMENTS)
    |--------------------------------------------------------------------------
    */
    public function send(
        PlatformMetaConnection $platform,
        string $to,
        array $payload
    ): array {

        WhatsAppTracker::whatsapp('outbound_dispatch_start', [
            'to' => $to,
            'platform_id' => $platform->id,
            'has_text' => ! empty($payload['text']),
            'attachments' => count($payload['attachments'] ?? []),
        ]);

        if (empty($platform->whatsapp_phone_number_id)) {
            return $this->error('Missing WhatsApp phone_number_id', $platform);
        }

        $token = $this->decryptToken($platform);
        if (!$token) {
            return $this->error('Token decryption failed', $platform);
        }

        $endpoint = $this->buildEndpoint($platform);
        $results  = [];

        /*
        |--------------------------------------------------------------------------
        | 1️⃣ SEND TEXT FIRST
        |--------------------------------------------------------------------------
        */
        if (!empty($payload['text'])) {
            $results[] = $this->sendText(
                $endpoint,
                $token,
                $to,
                $payload['text']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ SEND ATTACHMENTS
        |--------------------------------------------------------------------------
        */
        foreach ($payload['attachments'] ?? [] as $attachment) {
            $results[] = $this->sendAttachment(
                $endpoint,
                $token,
                $to,
                $attachment
            );
        }

        if (! empty($payload['voice_url']) && filter_var($payload['voice_url'], FILTER_VALIDATE_URL)) {
            $results[] = $this->sendAudioLink($endpoint, $token, $to, $payload['voice_url']);
        }

        return $results;
    }

    public function accessTokenForPlatform(PlatformMetaConnection $platform): ?string
    {
        return $this->decryptToken($platform);
    }

    protected function sendAudioLink(string $endpoint, string $token, string $to, string $link): array
    {
        return $this->post($endpoint, $token, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'audio',
            'audio' => [
                'link' => $link,
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | SEND TEXT
    |--------------------------------------------------------------------------
    */
    protected function sendText(
        string $endpoint,
        string $token,
        string $to,
        string $message
    ): array {

        return $this->post($endpoint, $token, [
            'messaging_product' => 'whatsapp',
            'to'   => $to,
            'type' => 'text',
            'text' => ['body' => $message],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | SEND ATTACHMENT (ENTERPRISE SAFE)
    |--------------------------------------------------------------------------
    */
    protected function sendAttachment(
        string $endpoint,
        string $token,
        string $to,
        array $attachment
    ): array {

        $type = strtolower($attachment['type'] ?? 'document');

        // Normalize common extensions
        if (in_array($type, ['jpg','jpeg','png','gif','webp'])) {
            $type = 'image';
        }

        if ($type === 'pdf') {
            $type = 'document';
        }

        $link = $this->resolveAttachmentUrl($attachment);

        if (!$link) {
            Log::error('Attachment URL missing or invalid', $attachment);

            return [
                'success' => false,
                'error'   => 'Invalid attachment URL',
            ];
        }

        Log::info('Sending WhatsApp attachment', [
            'type' => $type,
            'link' => $link
        ]);

        switch ($type) {

            case 'image':
                return $this->post($endpoint, $token, [
                    'messaging_product' => 'whatsapp',
                    'to'   => $to,
                    'type' => 'image',
                    'image' => [
                        'link' => $link,
                    ],
                ]);

            case 'document':
                return $this->post($endpoint, $token, [
                    'messaging_product' => 'whatsapp',
                    'to'   => $to,
                    'type' => 'document',
                    'document' => [
                        'link'     => $link,
                        'filename' => $attachment['filename'] ?? basename($link),
                    ],
                ]);

            case 'audio':
            case 'voice':
                return $this->sendAudioLink($endpoint, $token, $to, $link);

            default:
                Log::warning('Unsupported attachment type', [
                    'type' => $type
                ]);

                return [
                    'success' => false,
                    'error'   => 'Unsupported attachment type',
                ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE ATTACHMENT URL (CRITICAL FIX)
    |--------------------------------------------------------------------------
    */
    protected function resolveAttachmentUrl(array $attachment): ?string
    {
        // If already full URL
        if (!empty($attachment['url']) &&
            filter_var($attachment['url'], FILTER_VALIDATE_URL)) {
            return $attachment['url'];
        }

        // If stored as /storage/faq_attachments/...
        if (!empty($attachment['file_path'])) {
            return URL::to('/storage/' . ltrim($attachment['file_path'], '/'));
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | CORE POST METHOD
    |--------------------------------------------------------------------------
    */
    protected function post(string $endpoint, string $token, array $body): array
    {
        try {

            $response = Http::withToken($token)
                ->timeout($this->timeout)
                ->retry(2, 500)
                ->post($endpoint, $body);

            $msgType = (string) ($body['type'] ?? 'unknown');
            $to = (string) ($body['to'] ?? '');

            if ($response->failed()) {
                WhatsAppTracker::whatsapp('graph_send_failed', [
                    'to' => $to,
                    'type' => $msgType,
                    'http_status' => $response->status(),
                    'response' => substr((string) $response->body(), 0, 1500),
                ], 'error');

                return [
                    'success' => false,
                    'status'  => $response->status(),
                    'error'   => $response->body(),
                ];
            }

            $data = $response->json();
            $messageId = $data['messages'][0]['id'] ?? null;
            $messageStatus = $data['messages'][0]['message_status'] ?? null;

            WhatsAppTracker::whatsapp('graph_send_ok', [
                'to' => $to,
                'type' => $msgType,
                'wamid' => $messageId,
                'message_status' => $messageStatus,
            ]);

            return [
                'success' => true,
                'external_message_id' => $messageId,
                'response' => $data,
            ];

        } catch (\Throwable $e) {
            WhatsAppTracker::whatsapp('graph_send_exception', [
                'to' => (string) ($body['to'] ?? ''),
                'type' => (string) ($body['type'] ?? ''),
                'error' => $e->getMessage(),
            ], 'critical');

            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS Good
    |--------------------------------------------------------------------------
    */
  protected function decryptToken(PlatformMetaConnection $platform): ?string
{
    if (!$platform->access_token) {
        Log::error('Access token missing', [
            'platform_id' => $platform->id
        ]);
        return null;
    }

    try {

        // Try decrypting first (for encrypted tokens)
        return decrypt($platform->access_token);

    } catch (\Throwable $e) {

        // Token is probably stored as plain text
        Log::warning('Access token not encrypted, using raw token', [
            'platform_id' => $platform->id
        ]);

        return $platform->access_token;
    }
}

    protected function buildEndpoint(PlatformMetaConnection $platform): string
    {
        $graphUrl     = rtrim(config('services.meta.graph_url'), '/');
        $graphVersion = config('services.meta.graph_version');

        return "{$graphUrl}/{$graphVersion}/{$platform->whatsapp_phone_number_id}/messages";
    }

    protected function error(string $message, PlatformMetaConnection $platform): array
    {
        Log::error($message, ['platform_id' => $platform->id]);

        return [
            'success' => false,
            'error'   => $message,
        ];
    }
}