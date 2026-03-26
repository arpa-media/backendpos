<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class TransactionDate
{
    public static function normalizeTimezone(?string $timezone, string $fallback = 'Asia/Jakarta'): string
    {
        $fallback = trim($fallback) !== '' ? trim($fallback) : 'Asia/Jakarta';
        $raw = trim((string) ($timezone ?? ''));
        if ($raw === '') {
            return $fallback;
        }

        $upper = strtoupper($raw);
        $normalizedMap = [
            'WIB' => 'Asia/Jakarta',
            'WITA' => 'Asia/Makassar',
            'WIT' => 'Asia/Jayapura',
            'GMT+7' => 'Asia/Jakarta',
            'GMT+07' => 'Asia/Jakarta',
            'GMT+07:00' => 'Asia/Jakarta',
            'UTC+7' => 'Asia/Jakarta',
            'UTC+07' => 'Asia/Jakarta',
            'UTC+07:00' => 'Asia/Jakarta',
            '+07:00' => 'Asia/Jakarta',
            'GMT+8' => 'Asia/Makassar',
            'GMT+08' => 'Asia/Makassar',
            'GMT+08:00' => 'Asia/Makassar',
            'UTC+8' => 'Asia/Makassar',
            'UTC+08' => 'Asia/Makassar',
            'UTC+08:00' => 'Asia/Makassar',
            '+08:00' => 'Asia/Makassar',
            'GMT+9' => 'Asia/Jayapura',
            'GMT+09' => 'Asia/Jayapura',
            'GMT+09:00' => 'Asia/Jayapura',
            'UTC+9' => 'Asia/Jayapura',
            'UTC+09' => 'Asia/Jayapura',
            'UTC+09:00' => 'Asia/Jayapura',
            '+09:00' => 'Asia/Jayapura',
        ];

        return $normalizedMap[$upper] ?? $raw;
    }

    public static function appTimezone(string $fallback = 'Asia/Jakarta'): string
    {
        $tz = trim((string) config('app.timezone', $fallback));
        return self::normalizeTimezone($tz !== '' ? $tz : $fallback, $fallback);
    }

    public static function toIso($value, ?string $timezone = null): ?string
    {
        $dt = self::coerce($value);
        if (!$dt) {
            return null;
        }

        return $dt->copy()->setTimezone(self::normalizeTimezone($timezone, self::appTimezone()))->toIso8601String();
    }

    public static function formatLocal($value, ?string $timezone = null, string $pattern = 'Y-m-d H:i:s'): ?string
    {
        $dt = self::coerce($value);
        if (!$dt) {
            return null;
        }

        return $dt->copy()->setTimezone(self::normalizeTimezone($timezone, self::appTimezone()))->format($pattern);
    }

    public static function todayDateString(?string $timezone = null): string
    {
        return CarbonImmutable::now(self::normalizeTimezone($timezone, self::appTimezone()))->toDateString();
    }

    /**
     * Resolve a local outlet date range and convert the query boundaries to UTC.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable}
     */
    public static function dateRange(?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
    {
        $tz = self::normalizeTimezone($timezone, self::appTimezone());
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

        return [$from, $to, $fromUtc, $toUtc];
    }

    public static function saleNumberDateToken(?string $saleNumber): ?string
    {
        $raw = trim((string) ($saleNumber ?? ''));
        if ($raw === '') {
            return null;
        }

        if (preg_match('/-(\d{8})-(?:[A-Z0-9]{2,}|\d{3,})/i', $raw, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public static function dateTokens(?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
    {
        [$from, $to] = self::dateRange($dateFrom, $dateTo, $timezone);

        $tokens = [];
        for ($cursor = $from->startOfDay(); $cursor->lessThanOrEqualTo($to->startOfDay()); $cursor = $cursor->addDay()) {
            $tokens[] = $cursor->format('Ymd');
            if (count($tokens) >= 370) {
                break;
            }
        }

        return array_values(array_unique($tokens));
    }

    public static function formatSaleLocal($value, ?string $timezone = null, ?string $saleNumber = null, string $pattern = 'Y-m-d H:i:s'): ?string
    {
        $dt = self::coerceSale($value, $timezone, $saleNumber);
        if (!$dt) {
            return null;
        }

        return $dt->format($pattern);
    }

    public static function toSaleIso($value, ?string $timezone = null, ?string $saleNumber = null): ?string
    {
        $dt = self::coerceSale($value, $timezone, $saleNumber);
        if (!$dt) {
            return null;
        }

        return $dt->toIso8601String();
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

    private static function coerceSale($value, ?string $timezone = null, ?string $saleNumber = null): ?Carbon
    {
        $tz = self::normalizeTimezone($timezone, self::appTimezone());

        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value)->copy()->setTimezone($tz);
        }

        if (!is_string($value)) {
            return null;
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        try {
            if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $raw)) {
                return Carbon::parse($raw)->setTimezone($tz);
            }

            $normalized = str_replace('T', ' ', $raw);
            $normalized = substr($normalized, 0, 19);

            $candidateUtc = Carbon::createFromFormat('Y-m-d H:i:s', $normalized, 'UTC')->setTimezone($tz);
            $candidateLocal = Carbon::createFromFormat('Y-m-d H:i:s', $normalized, $tz);
            $saleToken = self::saleNumberDateToken($saleNumber);

            if ($saleToken) {
                $utcToken = $candidateUtc->format('Ymd');
                $localToken = $candidateLocal->format('Ymd');

                if ($utcToken === $saleToken && $localToken !== $saleToken) {
                    return $candidateUtc;
                }

                if ($localToken === $saleToken && $utcToken !== $saleToken) {
                    return $candidateLocal;
                }
            }

            return $candidateUtc;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
