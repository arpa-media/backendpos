<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
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

    public static function todayDateString(?string $timezone = null): string
    {
        return CarbonImmutable::now($timezone ?: self::appTimezone())->toDateString();
    }

    /**
     * Resolve a local date range and the exact database query boundaries.
     *
     * Resolve a local outlet date range and convert the query boundaries to UTC.
     *
     * Transactions are stored in UTC. Reports must scope by local outlet date,
     * but the actual database comparison must still use UTC boundaries.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable}
     */
    public static function dateRange(?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
    {
        $tz = $timezone ?: self::appTimezone();
        $today = CarbonImmutable::now($tz)->startOfDay();

        try {
            $from = $dateFrom ? CarbonImmutable::parse($dateFrom, $tz)->startOfDay() : $today;
        } catch (\Throwable $e) {
            $from = $today;
        }

        try {
            $to = $dateTo ? CarbonImmutable::parse($dateTo, $tz)->startOfDay() : $today;
        } catch (\Throwable $e) {
            $to = $today;
        }

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        $to = $to->endOfDay();
        $fromUtc = $from->setTimezone('UTC');
        $toUtc = $to->setTimezone('UTC');

        return [
            $from,
            $to,
            $fromUtc,
            $toUtc,
        ];
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

            $storedTimezone = 'UTC';

            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
                return Carbon::createFromFormat('Y-m-d H:i:s', $value, $storedTimezone);
            }

            return Carbon::parse($value, $storedTimezone);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
