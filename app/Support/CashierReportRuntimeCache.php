<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;

class CashierReportRuntimeCache
{
    public const TTL_SECONDS = 12;

    public function remember(
        string $namespace,
        array $params,
        ?string $fallbackOutletId,
        ?string $userId,
        Closure $callback,
    ) {
        $context = $this->context($params, $fallbackOutletId);
        if ($context['outlet_ids'] === []) {
            return $callback();
        }

        // Re-read once if a checkout/void/cancel mutates the same business date
        // while this request is being calculated.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $versionsBefore = $this->versions($context['outlet_ids'], $context['dates']);
            $key = $this->cacheKey($namespace, $params, $context, $versionsBefore, $userId);

            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached;
            }

            try {
                $lock = Cache::lock($key.':lock', 20);
                $payload = $lock->block(6, function () use ($key, $callback) {
                    $cached = Cache::get($key);
                    if ($cached !== null) {
                        return $cached;
                    }

                    $value = $callback();
                    Cache::put($key, $value, now()->addSeconds(self::TTL_SECONDS));
                    return $value;
                });
            } catch (\Throwable) {
                $payload = $callback();
            }

            $versionsAfter = $this->versions($context['outlet_ids'], $context['dates']);
            if ($versionsAfter === $versionsBefore) {
                return $payload;
            }
        }

        return $callback();
    }

    private function context(array $params, ?string $fallbackOutletId): array
    {
        $outletIds = array_values(array_unique(array_filter(array_map(
            'strval',
            $params['scope_outlet_ids'] ?? []
        ))));

        if ($outletIds === [] && filled($fallbackOutletId)) {
            $outletIds = [(string) $fallbackOutletId];
        }
        sort($outletIds);

        $timezone = TransactionDate::normalizeTimezone(
            (string) ($params['scope_timezone'] ?? ''),
            TransactionDate::appTimezone()
        );
        $today = TransactionDate::businessTodayDateString($timezone);
        $from = (string) ($params['date_from'] ?? $params['date'] ?? $today);
        $to = (string) ($params['date_to'] ?? $params['date'] ?? $from);

        try {
            $fromDate = CarbonImmutable::parse($from, $timezone);
        } catch (\Throwable) {
            $fromDate = CarbonImmutable::parse($today, $timezone);
        }
        try {
            $toDate = CarbonImmutable::parse($to, $timezone);
        } catch (\Throwable) {
            $toDate = $fromDate;
        }
        if ($toDate->lessThan($fromDate)) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        $dates = [];
        for ($cursor = $fromDate; $cursor->lessThanOrEqualTo($toDate) && count($dates) < 31; $cursor = $cursor->addDay()) {
            $dates[] = $cursor->toDateString();
        }

        return [
            'outlet_ids' => $outletIds,
            'timezone' => $timezone,
            'date_from' => $fromDate->toDateString(),
            'date_to' => $toDate->toDateString(),
            'dates' => $dates,
        ];
    }

    private function versions(array $outletIds, array $dates): array
    {
        $versions = [];
        foreach ($outletIds as $outletId) {
            foreach ($dates as $date) {
                $versions[$outletId.'#'.$date] = CashierReportCacheVersion::current($outletId, $date);
            }
        }
        ksort($versions);
        return $versions;
    }

    private function cacheKey(string $namespace, array $params, array $context, array $versions, ?string $userId): string
    {
        unset($params['_live'], $params['_ts'], $params['timestamp']);

        return 'cashier-report-runtime:v1:'.sha1(json_encode([
            'namespace' => $namespace,
            'user_id' => trim((string) ($userId ?? 'guest')),
            'params' => $params,
            'context' => $context,
            'versions' => $versions,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
