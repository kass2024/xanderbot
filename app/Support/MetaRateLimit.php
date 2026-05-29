<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class MetaRateLimit
{
    private const CACHE_KEY = 'meta_api_rate_limit_until';

    public static function isBlocked(): bool
    {
        $until = Cache::get(self::CACHE_KEY);

        return $until instanceof Carbon && now()->lt($until);
    }

    public static function blockedUntil(): ?Carbon
    {
        $until = Cache::get(self::CACHE_KEY);

        return $until instanceof Carbon ? $until : null;
    }

    public static function block(int $seconds = 900): void
    {
        $until = now()->addSeconds($seconds);
        Cache::put(self::CACHE_KEY, $until, $seconds + 120);
    }

    public static function clear(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function isRateLimitMessage(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, '"code":17')
            || str_contains($normalized, '2446079')
            || str_contains($normalized, 'too many calls')
            || str_contains($normalized, 'rate limit')
            || str_contains($normalized, 'user request limit')
            || str_contains($normalized, 'application request limit');
    }

    public static function recordFromMessage(string $message, int $defaultSeconds = 900): bool
    {
        if (! self::isRateLimitMessage($message)) {
            return false;
        }

        self::block($defaultSeconds);

        return true;
    }
}
