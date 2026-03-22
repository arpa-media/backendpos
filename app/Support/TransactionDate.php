<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class TransactionDate
{
    public static function appTimezone(string $fallback = 'Asia/Jakarta'): string
    {
        $tz = trim((string) config('app.timezone', $fallback));
        return $tz !== '' ? $tz : $fallback;
    }

    public static function toIso($value, ?string $timezone = null): ?string
    {
        $dt = self::coerce($value);
        if (!$dt) {
            return null;
        }

        return $dt->copy()->setTimezone($timezone ?: self::appTimezone())->toIso8601String();
    }

    public static function formatLocal($value, ?string $timezone = null, string $pattern = 'Y-m-d H:i:s'): ?string
    {
        $dt = self::coerce($value);
        if (!$dt) {
            return null;
        }

        return $dt->copy()->setTimezone($timezone ?: self::appTimezone())->format($pattern);
    }

    private static function coerce($value): ?Carbon
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value)->copy();
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value)) {
                return Carbon::parse($value);
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
                return Carbon::createFromFormat('Y-m-d H:i:s', $value, 'UTC');
            }

            return Carbon::parse($value, 'UTC');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
