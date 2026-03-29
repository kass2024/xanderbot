<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use App\Models\PlatformMetaConnection;
use App\Models\Client;
use App\Models\Message;
use App\Services\Chatbot\ChatbotProcessor;
use App\Services\Chatbot\MessageDispatcher;
use App\Services\Chatbot\SpeechService;

class MetaWebhookController extends Controller
{
    public function __construct(
        protected ChatbotProcessor $processor,
        protected MessageDispatcher $dispatcher
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Webhook Verification
    |--------------------------------------------------------------------------
    */
    public function verify(Request $request): Response
    {
        $mode      = $request->input('hub_mode') ?? $request->input('hub.mode');
        $token     = $request->input('hub_verify_token') ?? $request->input('hub.verify_token');
        $challenge = $request->input('hub_challenge') ?? $request->input('hub.challenge');

        if (
            $mode === 'subscribe' &&
            hash_equals(
                (string) config('services.whatsapp_webhook.verify_token'),
                (string) $token
            )
        ) {
            return response($challenge, 200);
        }

        Log::warning('Meta webhook verification failed');
        return response('Forbidden', 403);
    }

    /*
    |--------------------------------------------------------------------------
    | Handle Incoming Webhook
    |--------------------------------------------------------------------------
    */
    public function handle(Request $request): Response
    {
        if (!$this->isValidSignature($request)) {
            Log::warning('Invalid Meta webhook signature');
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payload = $request->json()->all();

        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return response()->json(['status' => 'ignored'], 200);
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {

                $value = $change['value'] ?? [];

                if (!empty($value['messages'])) {
                    $this->handleIncomingMessages($value);
                }

                if (!empty($value['statuses'])) {
                    $this->handleStatusUpdates($value['statuses']);
                }
            }
        }

        return response()->json(['status' => 'ok'], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Process Incoming Messages
    |--------------------------------------------------------------------------
    */
    protected function handleIncomingMessages(array $value): void
    {
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

        if (!$phoneNumberId) {
            Log::warning('Missing phone_number_id in webhook');
            return;
        }

        $platform = PlatformMetaConnection::where(
            'whatsapp_phone_number_id',
            $phoneNumberId
        )->first();

        if (!$platform) {
            Log::warning('Platform not found', ['phone_number_id' => $phoneNumberId]);
            return;
        }

        $clientId = $this->resolveClientId($platform);
        if (!$clientId) {
            return;
        }

        foreach ($value['messages'] as $incoming) {

            $from      = $incoming['from'] ?? null;
            $messageId = $incoming['id'] ?? null;

            if (!$from || !$messageId) {
                continue;
            }

            if ($this->isDuplicate($messageId)) {
                continue;
            }

            $payload = $this->extractInboundPayload($incoming);

            $text = $payload['text'] ?? '';

            if ($text === '' && ! empty($payload['audio_media_id'])) {
                if (config('chatbot.transcribe_inbound_audio', true)) {
                    $text = $this->downloadAndTranscribeAudio($platform, $payload['audio_media_id']) ?? '';
                }
                if ($text === '') {
                    Log::warning('Voice note could not be transcribed; using fallback text for bot', [
                        'from' => $from,
                        'media_id' => $payload['audio_media_id'],
                    ]);
                    $text = 'The user sent a voice message. Transcription was unavailable. Greet them briefly and ask them to type their question, or summarize common topics you can help with (visas, study abroad, etc.).';
                }
            }

            if ($text === '') {
                Log::info('Unsupported or empty inbound message', ['type' => $incoming['type'] ?? null]);
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Ad Attribution Detection (Enterprise Upgrade)
            |--------------------------------------------------------------------------
            */
            $referral = $incoming['referral'] ?? null;

            $metaCampaignId = null;
            $metaAdsetId    = null;
            $metaAdId       = null;
            $source         = 'organic';

            if ($referral) {
                $metaCampaignId = $referral['campaign_id'] ?? null;
                $metaAdsetId    = $referral['adset_id'] ?? null;
                $metaAdId       = $referral['ad_id'] ?? null;
                $source         = 'paid';

                Log::info('Ad referral detected', [
                    'campaign_id' => $metaCampaignId,
                    'adset_id'    => $metaAdsetId,
                    'ad_id'       => $metaAdId,
                ]);
            }

            try {

                $aiResponse = $this->processor->process([
                    'from'              => $from,
                    'text'              => $text,
                    'client_id'         => $clientId,
                    'message_id'        => $messageId,
                    'meta_campaign_id'  => $metaCampaignId,
                    'meta_adset_id'     => $metaAdsetId,
                    'meta_ad_id'        => $metaAdId,
                    'source'            => $source,
                ]);

                if (empty($aiResponse) || empty($aiResponse['text'])) {
                    continue;
                }

                if (config('chatbot.voice_faq_replies') && $this->shouldAttachVoiceReply($aiResponse)) {
                    $path = app(SpeechService::class)->textToSpeechMp3($aiResponse['text']);
                    if ($path) {
                        $aiResponse['voice_url'] = asset('storage/'.$path);
                    }
                }

                $results = $this->dispatcher->send(
                    platform: $platform,
                    to: $from,
                    payload: $aiResponse
                );

                $this->storeExternalIds($results);

            } catch (\Throwable $e) {

                Log::error('Incoming message processing failed', [
                    'error' => $e->getMessage(),
                    'from'  => $from,
                ]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Handle Delivery Status Updates
    |--------------------------------------------------------------------------
    */
    protected function handleStatusUpdates(array $statuses): void
    {
        foreach ($statuses as $status) {

            $externalId = $status['id'] ?? null;
            $delivery   = $status['status'] ?? null;

            if (!$externalId || !$delivery) {
                continue;
            }

            Message::where('external_message_id', $externalId)
                ->update(['status' => $delivery]);

            Log::info('Message status updated', [
                'external_id' => $externalId,
                'status'      => $delivery
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Store External IDs Safely
    |--------------------------------------------------------------------------
    */
    protected function storeExternalIds(array $results): void
    {
        foreach ($results as $result) {

            if (!empty($result['external_message_id'])) {

                Message::whereNull('external_message_id')
                    ->latest('id')
                    ->limit(1)
                    ->update([
                        'external_message_id' => $result['external_message_id'],
                        'status'              => 'sent'
                    ]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Client
    |--------------------------------------------------------------------------
    */
    protected function resolveClientId(PlatformMetaConnection $platform): ?int
    {
        $userId = $platform->connected_by;

        $clientId = Client::where('user_id', $userId)->value('id');

        if (!$clientId) {
            Log::error('Client not found for platform', [
                'platform_id' => $platform->id
            ]);
        }

        return $clientId;
    }

    /*
    |--------------------------------------------------------------------------
    | Extract inbound payload (text and/or audio id)
    |--------------------------------------------------------------------------
    */
    protected function extractInboundPayload(array $incoming): array
    {
        $type = $incoming['type'] ?? null;

        return match ($type) {
            'text' => [
                'text' => trim($incoming['text']['body'] ?? ''),
            ],
            'button' => [
                'text' => trim($incoming['button']['text'] ?? ''),
            ],
            'interactive' => [
                'text' => trim(
                    $incoming['interactive']['button_reply']['title']
                    ?? $incoming['interactive']['list_reply']['title']
                    ?? ''
                ),
            ],
            'audio' => [
                'audio_media_id' => $incoming['audio']['id'] ?? null,
            ],
            // Some payloads label voice distinctly; treat like audio.
            'voice' => [
                'audio_media_id' => is_array($incoming['voice'] ?? null)
                    ? ($incoming['voice']['id'] ?? null)
                    : null,
            ],
            default => [],
        };
    }

    protected function shouldAttachVoiceReply(array $aiResponse): bool
    {
        $source = $aiResponse['source'] ?? '';

        return in_array($source, [
            'direct_match',
            'similar_question',
            'keyword_match',
            'faq_token_overlap',
            'semantic_match',
        ], true);
    }

    protected function downloadAndTranscribeAudio(PlatformMetaConnection $platform, string $mediaId): ?string
    {
        $token = $this->dispatcher->accessTokenForPlatform($platform);
        if (! $token) {
            Log::warning('No access token for media download', ['platform_id' => $platform->id]);

            return null;
        }

        $base = rtrim((string) config('services.whatsapp.graph_url'), '/');
        $version = trim((string) config('services.whatsapp.graph_version'), '/');

        try {
            $metaResponse = Http::withToken($token)
                ->timeout(45)
                ->get("{$base}/{$version}/{$mediaId}");

            if ($metaResponse->failed()) {
                Log::warning('WhatsApp media meta failed', [
                    'status' => $metaResponse->status(),
                    'body' => $metaResponse->body(),
                ]);

                return null;
            }

            $mime = (string) ($metaResponse->json('mime_type') ?? '');
            $mediaUrl = $metaResponse->json('url');
            if (! $mediaUrl) {
                Log::warning('WhatsApp media meta missing url', ['json' => $metaResponse->json()]);

                return null;
            }

            $ext = match (true) {
                str_contains($mime, 'ogg') => 'ogg',
                str_contains($mime, 'opus') => 'ogg',
                str_contains($mime, 'mpeg') || str_contains($mime, 'mp3') => 'mp3',
                str_contains($mime, 'mp4') || str_contains($mime, 'm4a') || str_contains($mime, 'aac') => 'm4a',
                str_contains($mime, 'webm') => 'webm',
                str_contains($mime, 'wav') => 'wav',
                default => 'ogg',
            };

            $binary = Http::withToken($token)
                ->timeout(120)
                ->get($mediaUrl);

            if ($binary->failed()) {
                Log::warning('WhatsApp media binary download failed', ['status' => $binary->status()]);

                return null;
            }

            $tmp = sys_get_temp_dir().'/wa_audio_'.uniqid('', true).'.'.$ext;
            if (file_put_contents($tmp, $binary->body()) === false) {
                return null;
            }

            $uploadName = 'voice.'.$ext;
            $text = app(SpeechService::class)->transcribeFile($tmp, $uploadName);

            @unlink($tmp);

            return $text;
        } catch (\Throwable $e) {
            Log::error('Audio transcription pipeline failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Idempotency Protection
    |--------------------------------------------------------------------------
    */
    protected function isDuplicate(string $messageId): bool
    {
        $key = "wa_msg_$messageId";

        if (Cache::has($key)) {
            return true;
        }

        Cache::put($key, true, now()->addMinutes(10));
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Signature
    |--------------------------------------------------------------------------
    */
    protected function isValidSignature(Request $request): bool
    {
        $signature = $request->header('X-Hub-Signature-256');

        if (!$signature) {
            return false;
        }

        $appSecret = config('services.whatsapp_webhook.app_secret');

        if (!$appSecret) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac(
            'sha256',
            $request->getContent(),
            $appSecret
        );

        return hash_equals($expected, $signature);
    }
}