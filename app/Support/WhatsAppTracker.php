<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Structured WhatsApp tracking (dedicated daily log files).
 *
 * tail -f storage/logs/whatsapp-$(date +%Y-%m-%d).log
 *
 * Pre-screening tracking lives in the cPanel Xander project, not here.
 */
final class WhatsAppTracker
{
    public static function enabled(): bool
    {
        return filter_var(config('tracking.whatsapp_enabled', true), FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  'whatsapp'  $channel
     * @param  array<string, mixed>  $context
     */
    public static function log(string $channel, string $action, array $context = [], string $level = 'info'): void
    {
        if (! self::enabled()) {
            return;
        }

        $payload = array_merge([
            'ts' => now()->toIso8601String(),
            'action' => $action,
            'trace_id' => (string) Str::uuid(),
        ], self::sanitize($context));

        $message = '['.$action.'] '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        Log::channel($channel)->log($level, $message, $payload);
        // Also write to laravel.log so `tail -f storage/logs/laravel.log` shows traffic
        Log::channel('single')->log($level, '['.$channel.'] '.$message, $payload);
    }

    public static function whatsapp(string $action, array $context = [], string $level = 'info'): void
    {
        self::log('whatsapp', $action, $context, $level);
    }

    /**
     * Strip secrets and clip large bodies for logs.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function sanitize(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $k = strtolower((string) $key);
            if (in_array($k, ['secret', 'token', 'authorization', 'password', 'access_token', 'app_key'], true)) {
                $out[$key] = '[redacted]';

                continue;
            }
            if (in_array($k, ['to', 'from', 'recipient_id', 'phone', 'phone_raw'], true) && is_string($value)) {
                $digits = preg_replace('/\D+/', '', $value) ?? '';
                $out[$key] = strlen($digits) >= 4 ? '***'.substr($digits, -4) : '[redacted]';

                continue;
            }
            if (is_string($value) && preg_match('/\bEAA[A-Za-z0-9]{20,}/', $value)) {
                $out[$key] = '[redacted_token]';

                continue;
            }
            if (is_string($value) && strlen($value) > 2000) {
                $out[$key] = substr($value, 0, 2000).'…[truncated]';

                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::sanitize($value);

                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
