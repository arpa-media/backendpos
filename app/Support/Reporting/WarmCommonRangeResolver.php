<?php

namespace App\Support\Reporting;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class WarmCommonRangeResolver
{
    public const MODES = ['safe', 'normal', 'fast'];

    public static function resolveDateRange(?string $month, ?string $from, ?string $to, int $days, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();

        if (is_string($month) && trim($month) !== '') {
            $parsed = self::parseMonth($month, $timezone);
            if ($parsed->greaterThan($today->startOfMonth())) {
                throw new InvalidArgumentException('Bulan masa depan belum dapat diproses.');
            }

            $rangeFrom = $parsed->startOfMonth();
            $rangeTo = $parsed->isSameMonth($today) ? $today : $parsed->endOfMonth();

            return [$rangeFrom->toDateString(), $rangeTo->toDateString(), $rangeFrom->format('Y-m')];
        }

        if ((is_string($from) && trim($from) !== '') || (is_string($to) && trim($to) !== '')) {
            if (! is_string($from) || trim($from) === '' || ! is_string($to) || trim($to) === '') {
                throw new InvalidArgumentException('Gunakan --from dan --to bersamaan.');
            }

            $rangeFrom = self::parseDate($from, $timezone);
            $rangeTo = self::parseDate($to, $timezone);
            if ($rangeFrom->greaterThan($rangeTo)) {
                throw new InvalidArgumentException('--from tidak boleh lebih besar dari --to.');
            }
            if ($rangeFrom->greaterThan($today)) {
                throw new InvalidArgumentException('Tanggal masa depan belum dapat diproses.');
            }
            if ($rangeTo->greaterThan($today)) {
                $rangeTo = $today;
            }

            return [$rangeFrom->toDateString(), $rangeTo->toDateString(), null];
        }

        $days = max(1, min(400, $days));
        $rangeTo = $today;
        $rangeFrom = $today->subDays($days - 1);

        return [$rangeFrom->toDateString(), $rangeTo->toDateString(), null];
    }

    public static function resolveMonthRange(?string $month, ?string $from, ?string $to, int $months, string $timezone, bool $includeCurrent = false): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $latestClosedMonth = $today->subMonthNoOverflow()->startOfMonth();
        $latestAllowed = $includeCurrent ? $today->startOfMonth() : $latestClosedMonth;

        if (is_string($month) && trim($month) !== '') {
            $parsed = self::parseMonth($month, $timezone);
            if ($parsed->greaterThan($latestAllowed)) {
                throw new InvalidArgumentException($includeCurrent ? 'Bulan masa depan belum dapat diproses.' : 'Monthly summary hanya untuk bulan yang sudah closed.');
            }

            return [$parsed, $parsed];
        }

        if ((is_string($from) && trim($from) !== '') || (is_string($to) && trim($to) !== '')) {
            if (! is_string($from) || trim($from) === '' || ! is_string($to) || trim($to) === '') {
                throw new InvalidArgumentException('Gunakan --from dan --to bersamaan.');
            }

            $rangeFrom = self::parseMonth($from, $timezone);
            $rangeTo = self::parseMonth($to, $timezone);
            if ($rangeFrom->greaterThan($rangeTo)) {
                throw new InvalidArgumentException('--from tidak boleh lebih besar dari --to.');
            }
            if ($rangeFrom->greaterThan($latestAllowed)) {
                throw new InvalidArgumentException($includeCurrent ? 'Bulan masa depan belum dapat diproses.' : 'Monthly summary hanya untuk bulan yang sudah closed.');
            }
            if ($rangeTo->greaterThan($latestAllowed)) {
                $rangeTo = $latestAllowed;
            }

            return [$rangeFrom, $rangeTo];
        }

        $months = max(1, min(60, $months));
        $rangeTo = $latestAllowed;
        $rangeFrom = $rangeTo->subMonthsNoOverflow($months - 1)->startOfMonth();

        return [$rangeFrom, $rangeTo];
    }

    public static function resolveChunks(string $mode, int $outletChunk, int $dateChunk): array
    {
        $mode = self::normalizeMode($mode);
        $presets = [
            'safe' => ['outlet_chunk' => 3, 'date_chunk' => 7],
            'normal' => ['outlet_chunk' => 6, 'date_chunk' => 14],
            'fast' => ['outlet_chunk' => 20, 'date_chunk' => 14],
        ];

        return [
            max(1, min(50, $outletChunk > 0 ? $outletChunk : $presets[$mode]['outlet_chunk'])),
            max(1, min(31, $dateChunk > 0 ? $dateChunk : $presets[$mode]['date_chunk'])),
            $mode,
        ];
    }

    public static function resolveOutletChunk(string $mode, int $outletChunk): array
    {
        $mode = self::normalizeMode($mode);
        $presets = ['safe' => 3, 'normal' => 6, 'fast' => 20];

        return [max(1, min(50, $outletChunk > 0 ? $outletChunk : $presets[$mode])), $mode];
    }

    public static function monthCursor(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];
        for ($cursor = $from->startOfMonth(); $cursor->lessThanOrEqualTo($to->startOfMonth()); $cursor = $cursor->addMonthNoOverflow()) {
            $months[] = $cursor;
        }

        return $months;
    }

    public static function labelMonth(CarbonImmutable|string $month): string
    {
        $parsed = $month instanceof CarbonImmutable ? $month : CarbonImmutable::parse((string) $month)->startOfMonth();

        return $parsed->format('Y-m');
    }

    public static function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('Mode harus salah satu: safe, normal, fast.');
        }

        return $mode;
    }

    private static function parseMonth(string $value, string $timezone): CarbonImmutable
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            return CarbonImmutable::createFromFormat('Y-m-d', $value.'-01', $timezone)->startOfMonth();
        }

        return CarbonImmutable::parse($value, $timezone)->startOfMonth();
    }

    private static function parseDate(string $value, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse(trim($value), $timezone)->startOfDay();
    }
}
