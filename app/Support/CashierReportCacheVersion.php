<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class CashierReportCacheVersion
{
    public static function current(string $outletId, string $businessDate): int
    {
        $key = self::key($outletId, $businessDate);
        $value = Cache::get($key);

        if (! is_numeric($value)) {
            Cache::forever($key, 1);
            return 1;
        }

        return max(1, (int) $value);
    }

    public static function bump(string $outletId, string $businessDate): int
    {
        $key = self::key($outletId, $businessDate);

        if (! Cache::has($key)) {
            Cache::forever($key, 1);
        }

        try {
            $value = Cache::increment($key);
            if (is_numeric($value)) {
                return max(1, (int) $value);
            }
        } catch (\Throwable) {
            // fallback below
        }

        $next = self::current($outletId, $businessDate) + 1;
        Cache::forever($key, $next);

        return $next;
    }

    private static function key(string $outletId, string $businessDate): string
    {
        return 'cashier-report-version:v1:'.sha1(trim($outletId).'|'.trim($businessDate));
    }
}
