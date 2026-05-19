<?php

namespace App\Services\Prescreening;

use App\Support\WhatsAppTracker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use mysqli;

/**
 * Pre-screening is separate from the FAQ bot:
 * - Started only from web admin (WhatsApp invite template → session "invited").
 * - WhatsApp webhook handles START + Q&A + docs until complete.
 * - Completion saves to prescreening_submissions (same web list as admin form).
 * - "Hello" and general chat never enter this path.
 */
class XanderPrescreeningBridge
{
    public function handleInbound(string $waPhone, array $message): bool
    {
        if ($this->usesLocalPrescreening()) {
            return $this->handlePrescreeningWhenMatched($waPhone, $message);
        }

        if (! config('prescreening.forward_enabled', true)) {
            WhatsAppTracker::prescreening('forward_disabled', ['from' => $waPhone]);

            return $this->handlePrescreeningWhenMatched($waPhone, $message);
        }

        $forwardUrl = trim((string) config('prescreening.forward_url'));
        if ($forwardUrl === '') {
            return $this->handlePrescreeningWhenMatched($waPhone, $message);
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
        if (! $handled && $this->shouldTryLocalFallback($decision)) {
            WhatsAppTracker::prescreening('forward_fallback_local', [
                'from' => $waPhone,
                'reason' => $decision['reason'],
            ], 'warning');
            $handled = $this->processInboundLocal($waPhone, $message);
        }

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
     * Only run pre-screening when triggers/session match (never steal plain "hello").
     */
    protected function handlePrescreeningWhenMatched(string $waPhone, array $message): bool
    {
        $decision = $this->forwardDecision($waPhone, $message);
        if (! $decision['forward']) {
            return false;
        }

        return $this->processInboundLocal($waPhone, $message);
    }

    protected function usesLocalPrescreening(): bool
    {
        return strtolower((string) config('prescreening.mode', 'forward')) === 'local';
    }

    /**
     * @param  array{forward:bool,reason:string,text:string}  $decision
     */
    protected function shouldTryLocalFallback(array $decision): bool
    {
        if ($this->usesLocalPrescreening()) {
            return true;
        }

        return in_array($decision['reason'], [
            'button_start',
            'button_cancel',
            'ongoing_session',
            'media_active_session',
        ], true);
    }

    /**
     * @return array{active:bool,step:string}
     */
    public function sessionState(string $waPhone): array
    {
        if ($this->usesLocalPrescreening() || ! config('prescreening.forward_enabled', true)) {
            return $this->sessionStateLocal($waPhone);
        }

        $forwardUrl = trim((string) config('prescreening.forward_url'));
        if ($forwardUrl === '') {
            return $this->sessionStateLocal($waPhone);
        }

        $response = $this->postForward($forwardUrl, [
            'action' => 'active_session',
            'from' => $waPhone,
        ], (int) config('prescreening.forward_session_timeout', 4));

        // Fail open → FAQ bot (never block "hello" when cPanel is slow/down)
        if ($response === null) {
            return ['active' => false, 'step' => 'idle'];
        }

        $step = (string) ($response['step'] ?? 'idle');
        $active = (bool) ($response['active'] ?? false);
        if ($step === 'idle' && $active) {
            $step = 'invited';
        }

        return ['active' => $active, 'step' => $step !== '' ? $step : 'idle'];
    }

    /** @return array{active:bool,step:string} */
    protected function sessionStateLocal(string $waPhone): array
    {
        $info = $this->activeSessionInfo($waPhone);

        return [
            'active' => (bool) ($info['active'] ?? false),
            'step' => (string) ($info['step'] ?? 'idle'),
        ];
    }

    /** @return array{active:bool,step:?string} */
    public function activeSessionInfo(string $waPhone): array
    {
        if (! $this->bootLocal()) {
            return ['active' => false, 'step' => null];
        }
        $conn = $this->connection();
        if (! $conn || ! function_exists('xander_prescreening_load_session')) {
            return ['active' => false, 'step' => null];
        }
        xander_ensure_prescreening_whatsapp_tables($conn);
        $row = xander_prescreening_load_session($conn, $waPhone);
        if (! $row) {
            return ['active' => false, 'step' => 'idle'];
        }
        $step = (string) ($row['current_step'] ?? 'idle');

        return [
            'active' => $step !== 'idle',
            'step' => $step,
        ];
    }

    public function processInboundLocal(string $waPhone, array $message): bool
    {
        return $this->handleInboundLocal($waPhone, $message);
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
        if ($this->usesLocalPrescreening()) {
            return $this->hasActiveSessionLocal($waPhone);
        }

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

        // Hello / FAQ chat: skip cPanel session check when no VPS session (web-invite-only)
        if (config('prescreening.web_invite_only', true) && $this->isGeneralBotChat($text, $type, $action)) {
            $local = $this->sessionStateLocal($waPhone);
            if (! $this->isPrescreeningStep($local['step'])) {
                return [
                    'forward' => false,
                    'reason' => 'general_chat_bot_path',
                    'text' => $text,
                ];
            }
        }

        $state = $this->sessionState($waPhone);
        $step = $state['step'];
        $inPrescreeningFlow = $this->isPrescreeningStep($step);

        if (in_array($type, ['image', 'document', 'audio'], true)) {
            return [
                'forward' => $inPrescreeningFlow,
                'reason' => $inPrescreeningFlow ? 'media_active_session' : 'media_no_session',
                'text' => $text,
            ];
        }

        if (! config('prescreening.web_invite_only', true) && $this->looksLikePrescreeningTrigger($text)) {
            return ['forward' => true, 'reason' => 'keyword_trigger', 'text' => $text];
        }

        if (in_array($action, ['start', 'cancel'], true)) {
            if ($inPrescreeningFlow) {
                return [
                    'forward' => true,
                    'reason' => 'button_'.$action,
                    'text' => $text,
                ];
            }

            return [
                'forward' => false,
                'reason' => 'button_without_web_invite',
                'text' => $text,
            ];
        }

        return [
            'forward' => $inPrescreeningFlow,
            'reason' => $inPrescreeningFlow ? 'ongoing_session' : 'no_invite_bot_path',
            'text' => $text,
        ];
    }

    protected function isPrescreeningStep(string $step): bool
    {
        if ($step === 'invited') {
            return true;
        }

        return str_starts_with($step, 'q:') || str_starts_with($step, 'doc:');
    }

    /**
     * Plain FAQ messages (hello, visa questions) — never require Meta templates.
     */
    protected function isGeneralBotChat(string $text, string $type, string $action): bool
    {
        if (in_array($action, ['start', 'cancel'], true)) {
            return false;
        }

        if (! in_array($type, ['text', 'button', 'interactive', ''], true)) {
            return false;
        }

        $t = strtolower(trim($text));
        if ($t === '') {
            return false;
        }

        $greetings = [
            'hi', 'hello', 'hey', 'hola', 'good morning', 'good afternoon', 'good evening',
            'good day', 'howdy', 'sup', 'yo', 'hi there', 'hello there',
        ];
        if (in_array($t, $greetings, true)) {
            return true;
        }

        foreach (['hello', 'hi ', 'hey ', 'good morning', 'good afternoon', 'good evening'] as $prefix) {
            if (str_starts_with($t, $prefix)) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikePrescreeningTrigger(string $text): bool
    {
        $t = strtolower(trim($text));
        if ($t === '') {
            return false;
        }
        $triggers = config('prescreening.triggers', []);
        foreach ($triggers as $trigger) {
            $trigger = strtolower(trim((string) $trigger));
            if ($trigger !== '' && ($t === $trigger || str_contains($t, $trigger))) {
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
            WhatsAppTracker::prescreening('local_boot_failed', ['from' => $waPhone], 'error');

            return false;
        }

        $conn = $this->connection();
        if (! $conn) {
            WhatsAppTracker::prescreening('local_db_failed', ['from' => $waPhone], 'error');

            return false;
        }

        if (function_exists('xander_ensure_prescreening_whatsapp_tables')) {
            xander_ensure_prescreening_whatsapp_tables($conn);
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
