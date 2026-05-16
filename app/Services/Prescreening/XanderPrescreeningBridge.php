<?php

namespace App\Services\Prescreening;

use App\Support\WhatsAppTracker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use mysqli;

/**
 * Pre-screening: forwards to cPanel only when a session is active (or explicit trigger).
 * Normal chat ("hello", visa questions) stays on the VPS chatbot.
 */
class XanderPrescreeningBridge
{
    public function handleInbound(string $waPhone, array $message): bool
    {
        if (! config('prescreening.forward_enabled', true)) {
            WhatsAppTracker::prescreening('forward_disabled', ['from' => $waPhone]);

            return false;
        }

        $forwardUrl = trim((string) config('prescreening.forward_url'));
        if ($forwardUrl === '') {
            WhatsAppTracker::prescreening('forward_local_fallback', [
                'from' => $waPhone,
                'reason' => 'empty_forward_url',
            ]);

            return $this->handleInboundLocal($waPhone, $message);
        }

        $decision = $this->forwardDecision($waPhone, $message);
        if (! $decision['forward']) {
            WhatsAppTracker::prescreening('forward_skipped', [
                'from' => $waPhone,
                'reason' => $decision['reason'],
                'message_type' => $message['type'] ?? null,
                'text_preview' => mb_substr($decision['text'] ?? '', 0, 80),
            ]);

            return false;
        }

        $handled = $this->forwardToCpanel($forwardUrl, 'handle', $waPhone, $message);
        WhatsAppTracker::prescreening($handled ? 'forward_handled' : 'forward_not_handled', [
            'from' => $waPhone,
            'handled' => $handled,
            'reason' => $decision['reason'],
            'type' => $message['type'] ?? null,
            'message_id' => $message['id'] ?? null,
        ], $handled ? 'info' : 'warning');

        return $handled;
    }

    /**
     * Forward Meta message status webhooks to cPanel for invite delivery tracking.
     *
     * @param  array<string, mixed>  $status
     */
    public function forwardDeliveryStatus(array $status): void
    {
        if (! config('prescreening.forward_enabled', true)) {
            return;
        }

        $forwardUrl = trim((string) config('prescreening.forward_url'));
        if ($forwardUrl === '') {
            return;
        }

        $delivery = strtolower(trim((string) ($status['status'] ?? '')));
        if ($delivery === '') {
            return;
        }

        $recipient = (string) ($status['recipient_id'] ?? '');
        $payload = [
            'action' => 'delivery_status',
            'wamid' => (string) ($status['id'] ?? ''),
            'status' => $delivery,
            'recipient_id' => $recipient,
            'from' => $recipient,
            'errors' => $status['errors'] ?? [],
        ];

        $response = $this->postForward($forwardUrl, $payload, 4);
        $errorCode = null;
        $errors = $status['errors'] ?? [];
        if (is_array($errors) && isset($errors[0]['code'])) {
            $errorCode = (int) $errors[0]['code'];
        }
        WhatsAppTracker::prescreening('delivery_forward', [
            'status' => $delivery,
            'wamid' => $payload['wamid'],
            'recipient_id' => $payload['recipient_id'],
            'meta_error_code' => $errorCode,
            'recorded' => (bool) ($response['recorded'] ?? false),
        ], ($response['recorded'] ?? false) ? 'info' : 'warning');
    }

    public function hasActiveSession(string $waPhone): bool
    {
        $forwardUrl = trim((string) config('prescreening.forward_url'));
        if ($forwardUrl === '' || ! config('prescreening.forward_enabled', true)) {
            return $this->hasActiveSessionLocal($waPhone);
        }

        $response = $this->postForward($forwardUrl, [
            'action' => 'active_session',
            'from' => $waPhone,
        ], (int) config('prescreening.forward_session_timeout', 4));

        $active = (bool) ($response['active'] ?? false);
        WhatsAppTracker::prescreening('active_session_check', [
            'from' => $waPhone,
            'active' => $active,
            'step' => $response['step'] ?? null,
        ]);

        return $active;
    }

    /**
     * @return array{forward:bool,reason:string,text:string}
     */
    protected function forwardDecision(string $waPhone, array $message): array
    {
        $type = (string) ($message['type'] ?? '');
        $text = $this->extractMessageText($message);
        $action = strtolower(trim($text));

        if (in_array($type, ['image', 'document', 'audio'], true)) {
            $active = $this->hasActiveSession($waPhone);

            return [
                'forward' => $active,
                'reason' => $active ? 'media_active_session' : 'media_no_session',
                'text' => $text,
            ];
        }

        if ($this->looksLikePrescreeningTrigger($text)) {
            return ['forward' => true, 'reason' => 'keyword_trigger', 'text' => $text];
        }

        // Always forward START/CANCEL — cPanel handles invited sessions (do not rely on active_session HTTP alone)
        if (in_array($action, ['start', 'cancel'], true)) {
            return [
                'forward' => true,
                'reason' => 'button_'.$action,
                'text' => $text,
            ];
        }

        $active = $this->hasActiveSession($waPhone);

        return [
            'forward' => $active,
            'reason' => $active ? 'ongoing_session' : 'no_session_bot_path',
            'text' => $text,
        ];
    }

    protected function looksLikePrescreeningTrigger(string $text): bool
    {
        $t = strtolower(trim($text));
        if ($t === '') {
            return false;
        }
        foreach (['prescreening', 'pre-screening', 'prescreen', 'screening', 'start screening'] as $trigger) {
            if ($t === $trigger || str_contains($t, $trigger)) {
                return true;
            }
        }

        return false;
    }

    protected function extractMessageText(array $message): string
    {
        $type = (string) ($message['type'] ?? '');
        if ($type === 'text') {
            return trim((string) ($message['text']['body'] ?? ''));
        }
        if ($type === 'button') {
            return trim((string) ($message['button']['text'] ?? $message['button']['payload'] ?? ''));
        }
        if ($type === 'interactive') {
            $btn = $message['interactive']['button_reply']['title'] ?? $message['interactive']['button_reply']['id'] ?? '';
            $list = $message['interactive']['list_reply']['title'] ?? $message['interactive']['list_reply']['id'] ?? '';

            return trim((string) ($btn !== '' ? $btn : $list));
        }

        return '';
    }

    protected function forwardToCpanel(string $url, string $action, string $waPhone, ?array $message = null): bool
    {
        $payload = [
            'action' => $action,
            'from' => $waPhone,
        ];
        if ($message !== null) {
            $payload['message'] = $message;
        }

        $response = $this->postForward($url, $payload);
        if ($response === null) {
            return false;
        }

        return (bool) ($response['handled'] ?? false);
    }

    /** @return array<string, mixed>|null */
    protected function postForward(string $url, array $payload, ?int $timeoutSeconds = null): ?array
    {
        $secret = (string) config('prescreening.forward_secret');
        if ($secret === '') {
            WhatsAppTracker::prescreening('forward_secret_missing', ['url' => $url], 'error');

            return null;
        }

        $payload['secret'] = $secret;
        $timeout = $timeoutSeconds ?? (int) config('prescreening.forward_timeout', 8);
        $action = (string) ($payload['action'] ?? 'handle');

        WhatsAppTracker::prescreening('cpanel_request', WhatsAppTracker::sanitize([
            'url' => $url,
            'action' => $action,
            'from' => $payload['from'] ?? null,
            'timeout' => $timeout,
            'has_message' => isset($payload['message']),
        ]));

        try {
            $http = Http::timeout($timeout)
                ->connectTimeout(3)
                ->acceptJson()
                ->withHeaders(['X-Xander-Forward-Secret' => $secret])
                ->post($url, $payload);

            if (! $http->successful()) {
                WhatsAppTracker::prescreening('cpanel_http_error', [
                    'url' => $url,
                    'action' => $action,
                    'status' => $http->status(),
                    'body' => substr((string) $http->body(), 0, 800),
                ], 'error');

                return null;
            }

            $json = $http->json();
            $json = is_array($json) ? $json : [];

            WhatsAppTracker::prescreening('cpanel_response', [
                'url' => $url,
                'action' => $action,
                'response' => $json,
            ]);

            return $json;
        } catch (\Throwable $e) {
            WhatsAppTracker::prescreening('cpanel_exception', [
                'url' => $url,
                'action' => $action,
                'error' => $e->getMessage(),
            ], 'error');

            return null;
        }
    }

    protected function handleInboundLocal(string $waPhone, array $message): bool
    {
        if (! $this->bootLocal()) {
            return false;
        }

        $conn = $this->connection();
        if (! $conn) {
            return false;
        }

        try {
            if (function_exists('xander_prescreening_wa_dedup_seen')) {
                $mid = (string) ($message['id'] ?? '');
                if ($mid !== '' && xander_prescreening_wa_dedup_seen($conn, $mid)) {
                    return true;
                }
            }

            return (bool) xander_prescreening_handle_inbound($conn, $waPhone, $message);
        } catch (\Throwable $e) {
            Log::error('Prescreening local bridge failed', [
                'error' => $e->getMessage(),
                'from' => $waPhone,
            ]);

            return false;
        }
    }

    protected function hasActiveSessionLocal(string $waPhone): bool
    {
        if (! $this->bootLocal()) {
            return false;
        }
        $conn = $this->connection();
        if (! $conn || ! function_exists('xander_prescreening_load_session')) {
            return false;
        }
        $row = xander_prescreening_load_session($conn, $waPhone);
        if (! $row) {
            return false;
        }

        return (string) ($row['current_step'] ?? 'idle') !== 'idle';
    }

    protected ?mysqli $mysqli = null;

    protected bool $helpersLoaded = false;

    protected function bootLocal(): bool
    {
        $root = rtrim((string) config('prescreening.xander_php_path'), '/\\');
        if ($root === '' || ! is_dir($root)) {
            return false;
        }

        if (! $this->helpersLoaded) {
            $files = [
                $root.'/helpers/env_load.php',
                $root.'/helpers/student_status_notify.php',
                $root.'/helpers/prescreening_notify.php',
                $root.'/helpers/prescreening_schema.php',
                $root.'/helpers/prescreening_whatsapp_schema.php',
                $root.'/helpers/prescreening_whatsapp_flow.php',
            ];
            foreach ($files as $file) {
                if (! is_file($file)) {
                    return false;
                }
                require_once $file;
            }
            $this->helpersLoaded = true;
        }

        return true;
    }

    protected function connection(): ?mysqli
    {
        if ($this->mysqli instanceof mysqli) {
            return $this->mysqli;
        }

        $host = (string) config('database.connections.mysql.host', '127.0.0.1');
        $user = (string) config('database.connections.mysql.username', 'root');
        $pass = (string) config('database.connections.mysql.password', '');
        $db = (string) config('database.connections.mysql.database', '');

        $mysqli = @new mysqli($host, $user, $pass, $db);
        if ($mysqli->connect_errno) {
            return null;
        }
        $mysqli->set_charset('utf8mb4');
        $this->mysqli = $mysqli;

        return $this->mysqli;
    }
}
