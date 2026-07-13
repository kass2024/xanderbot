<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * File cache writes fail with "Permission denied" when www-data cannot write
 * storage/framework/cache. Never let that bubble into Meta sync UI errors.
 */
class SafeCache
{
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            return Cache::get($key, $default);
        } catch (Throwable $e) {
            self::log($e, 'get', $key);

            return $default;
        }
    }

    public static function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        try {
            Cache::put($key, $value, $ttl);

            return true;
        } catch (Throwable $e) {
            self::log($e, 'put', $key);

            return false;
        }
    }

    public static function forget(string $key): bool
    {
        try {
            Cache::forget($key);

            return true;
        } catch (Throwable $e) {
            self::log($e, 'forget', $key);

            return false;
        }
    }

    public static function add(string $key, mixed $value, mixed $ttl = null): bool
    {
        try {
            return Cache::add($key, $value, $ttl);
        } catch (Throwable $e) {
            self::log($e, 'add', $key);

            return false;
        }
    }

    public static function has(string $key): bool
    {
        try {
            return Cache::has($key);
        } catch (Throwable $e) {
            self::log($e, 'has', $key);

            return false;
        }
    }

    public static function isPermissionError(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'permission denied')
            || str_contains($msg, 'failed to open stream')
            || str_contains($msg, 'file_put_contents');
    }

    protected static function log(Throwable $e, string $op, string $key): void
    {
        Log::warning('SAFE_CACHE_'.$op.'_FAILED', [
            'key' => $key,
            'error' => $e->getMessage(),
        ]);
    }
}
