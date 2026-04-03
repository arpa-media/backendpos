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

    public static function businessDayStartHour(?string $timezone = null): int
    {
        return self::normalizeTimezone($timezone, self::appTimezone()) === 'Asia/Makassar' ? 1 : 0;
    }

    public static function usesBusinessCutoff(?string $timezone = null): bool
    {
        return self::businessDayStartHour($timezone) > 0;
    }

    public static function businessTodayDateString(?string $timezone = null): string
    {
        $tz = self::normalizeTimezone($timezone, self::appTimezone());
        $now = CarbonImmutable::now($tz);
        $startHour = self::businessDayStartHour($tz);

        if ($startHour > 0 && (int) $now->format('H') < $startHour) {
            return $now->subDay()->toDateString();
        }

        return $now->toDateString();
    }

    /**
     * Resolve a local outlet date range and convert the query boundaries to UTC.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable}
     */
    public static function dateRange(?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
    {
        $window = self::businessDateWindow($dateFrom, $dateTo, $timezone);

        return [
            $window['requested_from'],
            $window['requested_to'],
            $window['from_utc'],
            $window['to_utc'],
        ];
    }

    /**
     * @return array{
     *   timezone: string,
     *   requested_from: CarbonImmutable,
     *   requested_to: CarbonImmutable,
     *   from_local: CarbonImmutable,
     *   to_exclusive_local: CarbonImmutable,
     *   to_inclusive_local: CarbonImmutable,
     *   from_utc: CarbonImmutable,
     *   to_utc: CarbonImmutable,
     *   start_hour: int
     * }
     */
    public static function businessDateWindow(?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
    {
        $tz = self::normalizeTimezone($timezone, self::appTimezone());
        $today = CarbonImmutable::parse(self::businessTodayDateString($tz), $tz)->startOfDay();

        try {
            $requestedFrom = $dateFrom ? CarbonImmutable::parse($dateFrom, $tz)->startOfDay() : $today;
        } catch (\Throwable $e) {
            $requestedFrom = $today;
        }

        try {
            $requestedTo = $dateTo ? CarbonImmutable::parse($dateTo, $tz)->startOfDay() : $today;
        } catch (\Throwable $e) {
            $requestedTo = $today;
        }

        if ($requestedTo->lessThan($requestedFrom)) {
            [$requestedFrom, $requestedTo] = [$requestedTo, $requestedFrom];
        }

        $startHour = self::businessDayStartHour($tz);
        $fromLocal = $requestedFrom->addHours($startHour);
        $toExclusiveLocal = $requestedTo->addDay()->addHours($startHour);
        $toInclusiveLocal = $toExclusiveLocal->subSecond();

        return [
            'timezone' => $tz,
            'requested_from' => $requestedFrom,
            'requested_to' => $requestedTo,
            'from_local' => $fromLocal,
            'to_exclusive_local' => $toExclusiveLocal,
            'to_inclusive_local' => $toInclusiveLocal,
            'from_utc' => $fromLocal->setTimezone('UTC'),
            'to_utc' => $toInclusiveLocal->setTimezone('UTC'),
            'start_hour' => $startHour,
        ];
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
        return self::businessDateTokens($dateFrom, $dateTo, $timezone);
    }

    public static function businessDateTokens(?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
    {
        $window = self::businessDateWindow($dateFrom, $dateTo, $timezone);
        $from = $window['requested_from'];
        $to = $window['requested_to'];

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

    public static function timezoneUtcOffsetHours(?string $timezone = null): int
    {
        return match (self::normalizeTimezone($timezone, self::appTimezone())) {
            'Asia/Makassar' => 8,
            'Asia/Jayapura' => 9,
            default => 7,
        };
    }

    public static function saleNumberTokenSqlExpression(string $saleNumberColumn): string
    {
        return "CASE WHEN {$saleNumberColumn} REGEXP '-[0-9]{8}-' THEN SUBSTRING_INDEX(SUBSTRING_INDEX({$saleNumberColumn}, '-', -2), '-', 1) ELSE NULL END";
    }

    public static function resolvedSaleLocalSqlExpression(string $createdAtColumn, ?string $saleNumberColumn = null, ?string $timezone = null): string
    {
        $offsetHours = self::timezoneUtcOffsetHours($timezone);
        $utcLocalExpr = $offsetHours === 0
            ? $createdAtColumn
            : "DATE_ADD({$createdAtColumn}, INTERVAL {$offsetHours} HOUR)";

        if (!$saleNumberColumn) {
            return $utcLocalExpr;
        }

        $tokenExpr = self::saleNumberTokenSqlExpression($saleNumberColumn);
        $utcTokenExpr = "DATE_FORMAT({$utcLocalExpr}, '%Y%m%d')";
        $localTokenExpr = "DATE_FORMAT({$createdAtColumn}, '%Y%m%d')";

        return implode(' ', [
            'CASE',
            "WHEN ({$saleNumberColumn} IS NULL OR {$saleNumberColumn} NOT REGEXP '-[0-9]{8}-') THEN {$utcLocalExpr}",
            "WHEN {$utcTokenExpr} = {$tokenExpr} AND {$localTokenExpr} <> {$tokenExpr} THEN {$utcLocalExpr}",
            "WHEN {$localTokenExpr} = {$tokenExpr} AND {$utcTokenExpr} <> {$tokenExpr} THEN {$createdAtColumn}",
            "ELSE {$utcLocalExpr}",
            'END',
        ]);
    }

    /**
     * Apply exact business-date filtering using the resolved local transaction moment.
     *
     * @return array{
     *   timezone: string,
     *   requested_from: CarbonImmutable,
     *   requested_to: CarbonImmutable,
     *   from_local: CarbonImmutable,
     *   to_exclusive_local: CarbonImmutable,
     *   to_inclusive_local: CarbonImmutable,
     *   from_utc: CarbonImmutable,
     *   to_utc: CarbonImmutable,
     *   start_hour: int
     * }
     */
    public static function applyExactBusinessDateScope(object $query, string $createdAtColumn, ?string $dateFrom, ?string $dateTo, ?string $timezone = null, ?string $saleNumberColumn = null): array
    {
        $window = self::businessDateWindow($dateFrom, $dateTo, $timezone);
        $resolvedLocalExpr = self::resolvedSaleLocalSqlExpression($createdAtColumn, $saleNumberColumn, $window['timezone']);

        $query->whereRaw(
            "({$resolvedLocalExpr} >= ? AND {$resolvedLocalExpr} < ?)",
            [
                $window['from_local']->format('Y-m-d H:i:s'),
                $window['to_exclusive_local']->format('Y-m-d H:i:s'),
            ]
        );

        return $window;
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
