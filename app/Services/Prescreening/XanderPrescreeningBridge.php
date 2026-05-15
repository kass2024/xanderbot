<?php

namespace App\Services\Prescreening;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use mysqli;

/**
 * Pre-screening: forwards inbound WhatsApp to cPanel Xander (local DB there).
 * Meta webhook URL on VPS stays unchanged.
 */
class XanderPrescreeningBridge
{
    public function handleInbound(string $waPhone, array $message): bool
    {
        $forwardUrl = trim((string) config('prescreening.forward_url'));
        if ($forwardUrl !== '') {
            return $this->forwardToCpanel($forwardUrl, 'handle', $waPhone, $message);
        }

        return $this->handleInboundLocal($waPhone, $message);
    }

    public function hasActiveSession(string $waPhone): bool
    {
        $forwardUrl = trim((string) config('prescreening.forward_url'));
        if ($forwardUrl !== '') {
            $response = $this->postForward($forwardUrl, [
                'action' => 'active_session',
                'from' => $waPhone,
            ]);

            return (bool) ($response['active'] ?? false);
        }

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
    protected function postForward(string $url, array $payload): ?array
    {
        $secret = (string) config('prescreening.forward_secret');
        if ($secret === '') {
            Log::warning('Prescreening forward: PRESCREENING_FORWARD_SECRET not set');

            return null;
        }

        $payload['secret'] = $secret;

        try {
            $http = Http::timeout((int) config('prescreening.forward_timeout', 25))
                ->acceptJson()
                ->withHeaders(['X-Xander-Forward-Secret' => $secret])
                ->post($url, $payload);

            if (! $http->successful()) {
                Log::warning('Prescreening forward HTTP error', [
                    'url' => $url,
                    'status' => $http->status(),
                    'body' => $http->body(),
                ]);

                return null;
            }

            $json = $http->json();
            if (! is_array($json)) {
                return null;
            }

            return $json;
        } catch (\Throwable $e) {
            Log::error('Prescreening forward failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

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
                    Log::warning('Prescreening helper missing', ['file' => $file]);

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
            Log::error('Prescreening mysqli failed', ['error' => $mysqli->connect_error]);

            return null;
        }
        $mysqli->set_charset('utf8mb4');
        $this->mysqli = $mysqli;

        return $this->mysqli;
    }
}
