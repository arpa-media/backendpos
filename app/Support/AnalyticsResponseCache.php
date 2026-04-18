<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

class AnalyticsResponseCache
{
    public static function remember(string $namespace, array $params, Closure $callback, int $ttlSeconds = 15, ?string $userId = null)
    {
        $ttlSeconds = self::resolveTtlSeconds($namespace, $ttlSeconds);
        $normalized = self::normalize($params);
        $resolvedUserId = trim((string) ($userId ?? optional(auth()->user())->getAuthIdentifier() ?? 'guest'));
        $cacheKey = 'analytics:' . trim($namespace) . ':' . md5(json_encode([
            'user_id' => $resolvedUserId,
            'params' => $normalized,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return Cache::remember($cacheKey, now()->addSeconds($ttlSeconds), $callback);
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
