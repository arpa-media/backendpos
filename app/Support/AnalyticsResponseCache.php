<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AnalyticsResponseCache
{
    public const HOT_WINDOW_TTL_SECONDS = 30;
    public const HISTORICAL_REPORT_TTL_SECONDS = 900;

    public static function remember(string $namespace, array $params, Closure $callback, int $ttlSeconds = 15, ?string $userId = null)
    {
        $ttlSeconds = self::resolveTtlSeconds($namespace, $ttlSeconds);

        return self::rememberExact($namespace, $params, $callback, $ttlSeconds, $userId);
    }

    /**
     * I04: reporting-aware cache boundary.
     *
     * Live / hybrid reads intentionally bypass the legacy Finance/Owner 15 minute
     * minimum cache floor. The cache key also carries the reporting contract/window
     * so a request cannot accidentally reuse a value produced under a different
     * read boundary after the business date advances.
     */
    public static function rememberReporting(
        string $namespace,
        array $params,
        array $reportingSource,
        Closure $callback,
        ?string $userId = null,
    ) {
        $mode = strtolower(trim((string) ($reportingSource['read_mode'] ?? 'materialized')));
        $ttlSeconds = self::reportingTtlSeconds($reportingSource);

        $params['__report_cache_boundary'] = [
            'contract' => (string) ($reportingSource['contract'] ?? 'unknown'),
            'consumer_contract' => (string) ($reportingSource['consumer_contract'] ?? ''),
            'read_mode' => $mode,
            'hot_window_days' => (int) ($reportingSource['hot_window_days'] ?? 0),
            'materialized_first_days' => (int) ($reportingSource['materialized_first_days'] ?? 0),
            'preferred_materialized_ready' => (bool) ($reportingSource['preferred_materialized_ready'] ?? false),
            'fallback_reason' => (string) ($reportingSource['fallback_reason'] ?? ''),
            'hot_window_from' => $reportingSource['hot_window_from'] ?? null,
            'hot_window_to' => $reportingSource['hot_window_to'] ?? null,
            'live_date_from' => $reportingSource['live_date_from'] ?? null,
            'live_date_to' => $reportingSource['live_date_to'] ?? null,
            'materialized_date_from' => $reportingSource['materialized_date_from'] ?? null,
            'materialized_date_to' => $reportingSource['materialized_date_to'] ?? null,
        ];

        return self::rememberExact($namespace, $params, $callback, $ttlSeconds, $userId);
    }

    public static function reportingTtlSeconds(array $reportingSource): int
    {
        $mode = strtolower(trim((string) ($reportingSource['read_mode'] ?? 'materialized')));

        return in_array($mode, ['live', 'hybrid', 'live_fallback', 'hybrid_fallback', 'live_detail'], true)
            ? self::HOT_WINDOW_TTL_SECONDS
            : self::HISTORICAL_REPORT_TTL_SECONDS;
    }

    public static function bumpVersion(?string $reason = null): string
    {
        return AnalyticsResponseVersion::bump($reason);
    }

    private static function rememberExact(string $namespace, array $params, Closure $callback, int $ttlSeconds, ?string $userId = null)
    {
        $ttlSeconds = max(1, $ttlSeconds);
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

    private static function resolveTtlSeconds(string $namespace, int $ttlSeconds): int
    {
        $ttlSeconds = max(1, $ttlSeconds);
        $normalized = trim($namespace);

        if (
            str_starts_with($normalized, 'finance-')
            || str_starts_with($normalized, 'report-portal.')
            || str_starts_with($normalized, 'owner-overview')
        ) {
            return max($ttlSeconds, self::HISTORICAL_REPORT_TTL_SECONDS);
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
