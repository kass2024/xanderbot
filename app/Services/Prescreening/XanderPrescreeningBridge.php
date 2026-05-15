<?php

namespace App\Services\Prescreening;

use Illuminate\Support\Facades\Log;
use mysqli;

/**
 * Runs pre-screening WhatsApp flow from shared Xander PHP helpers + MySQL tables.
 */
class XanderPrescreeningBridge
{
    protected ?mysqli $mysqli = null;

    protected bool $helpersLoaded = false;

    public function handleInbound(string $waPhone, array $message): bool
    {
        if (! $this->boot()) {
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
            Log::error('Prescreening bridge failed', [
                'error' => $e->getMessage(),
                'from' => $waPhone,
            ]);

            return false;
        }
    }

    public function hasActiveSession(string $waPhone): bool
    {
        if (! $this->boot()) {
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
        $step = (string) ($row['current_step'] ?? 'idle');

        return $step !== 'idle';
    }

    protected function boot(): bool
    {
        $root = rtrim((string) config('prescreening.xander_php_path'), '/\\');
        if ($root === '' || ! is_dir($root)) {
            Log::warning('Prescreening: XANDER_PHP_PATH not found', ['path' => $root]);

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
            if (function_exists('xander_ensure_prescreening_whatsapp_tables')) {
                $c = $this->connection();
                if ($c) {
                    xander_ensure_prescreening_whatsapp_tables($c);
                }
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
