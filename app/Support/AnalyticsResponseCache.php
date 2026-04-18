<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AnalyticsResponseCache
{
    public static function remember(string $namespace, array $params, Closure $callback, int $ttlSeconds = 15, ?string $userId = null)
    {
        $ttlSeconds = self::resolveTtlSeconds($namespace, $ttlSeconds);
        $normalized = self::normalize($params);
        $resolvedUserId = trim((string) ($userId ?? optional(auth()->user())->getAuthIdentifier() ?? 'guest'));
        $version = AnalyticsResponseVersion::current();
        $cacheKey = 'analytics:' . trim($namespace) . ':' . md5(json_encode([
            'version' => $version,
            'user_id' => $resolvedUserId,
            'params' => $normalized,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        return self::rememberWithLock($cacheKey, $callback, $ttlSeconds);
    }



    private static function rememberWithLock(string $cacheKey, Closure $callback, int $ttlSeconds)
    {
        try {
            $lock = Cache::lock($cacheKey . ':lock', max(5, min(30, $ttlSeconds)));

            return $lock->block(8, function () use ($cacheKey, $callback, $ttlSeconds) {
                $cached = Cache::get($cacheKey);
                if ($cached !== null) {
                    return $cached;
                }

                $payload = $callback();
                Cache::put($cacheKey, $payload, now()->addSeconds($ttlSeconds));

                return $payload;
            });
        } catch (Throwable) {
            return Cache::remember($cacheKey, now()->addSeconds($ttlSeconds), $callback);
        }
    }

    public static function bumpVersion(?string $reason = null): string
    {
        return AnalyticsResponseVersion::bump($reason);
    }

    private static function resolveTtlSeconds(string $namespace, int $ttlSeconds): int
    {
        $ttlSeconds = max(1, $ttlSeconds);
        $normalized = trim($namespace);

        if (
            str_starts_with($normalized, 'finance-')
            || str_starts_with($normalized, 'report-portal.')
            || str_starts_with($normalized, 'owner-overview')
        ) {
            return max($ttlSeconds, 900);
        }

        return $ttlSeconds;
    }

    private static function normalize($value)
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[(string) $key] = self::normalize($item);
            }
            ksort($normalized);

            return $normalized;
        }

        if (is_bool($value) || is_null($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        return trim((string) $value);
    }
}
