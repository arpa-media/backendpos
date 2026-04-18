<?php

namespace App\Services;

use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReportSaleBusinessDateIndexService
{
    private const DEFAULT_OUTLET_CHUNK_SIZE = 6;
    private const DEFAULT_DATE_CHUNK_DAYS = 3;
    private const CANDIDATE_SALE_CHUNK_SIZE = 1000;

    public function ensureCoverage(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $fallbackTimezone = null, array $options = []): void
    {
        $normalizedOutletIds = array_values(array_unique(array_filter(array_map('strval', $outletIds))));
        sort($normalizedOutletIds);

        if ($normalizedOutletIds === []) {
            return;
        }

        $outletChunkSize = $this->normalizeOutletChunkSize($options['outlet_chunk'] ?? null);
        $dateChunkDays = $this->normalizeDateChunkDays($options['date_chunk_days'] ?? $options['date_chunk'] ?? null);

        $timezoneMap = $this->resolveTimezoneMap($normalizedOutletIds, $fallbackTimezone);
        $groupedOutletIds = [];
        foreach ($normalizedOutletIds as $outletId) {
            $timezone = $timezoneMap[$outletId] ?? TransactionDate::normalizeTimezone($fallbackTimezone, TransactionDate::appTimezone());
            $groupedOutletIds[$timezone] ??= [];
            $groupedOutletIds[$timezone][] = $outletId;
        }

        foreach ($groupedOutletIds as $timezone => $tzOutletIds) {
            [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, $timezone);
            foreach (array_chunk($tzOutletIds, $outletChunkSize) as $outletChunk) {
                foreach ($this->splitWindowsByDays($this->refreshWindows($outletChunk, $fromDate, $toDate, $timezone), $timezone, $dateChunkDays) as [$windowFrom, $windowTo]) {
                    $this->rebuildCoverageWindow($outletChunk, $windowFrom, $windowTo, $timezone);
                }
            }
        }
    }


    public function refreshExactCoverage(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $fallbackTimezone = null, array $options = []): void
    {
        $normalizedOutletIds = array_values(array_unique(array_filter(array_map('strval', $outletIds))));
        sort($normalizedOutletIds);

        if ($normalizedOutletIds === []) {
            return;
        }

        $outletChunkSize = $this->normalizeOutletChunkSize($options['outlet_chunk'] ?? null);
        $dateChunkDays = $this->normalizeDateChunkDays($options['date_chunk_days'] ?? $options['date_chunk'] ?? null);
        $timezoneMap = $this->resolveTimezoneMap($normalizedOutletIds, $fallbackTimezone);
        $groupedOutletIds = [];
        foreach ($normalizedOutletIds as $outletId) {
            $timezone = $timezoneMap[$outletId] ?? TransactionDate::normalizeTimezone($fallbackTimezone, TransactionDate::appTimezone());
            $groupedOutletIds[$timezone] ??= [];
            $groupedOutletIds[$timezone][] = $outletId;
        }

        foreach ($groupedOutletIds as $timezone => $tzOutletIds) {
            [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, $timezone);
            $windows = $this->splitWindowsByDays([[$fromDate, $toDate]], $timezone, $dateChunkDays);
            foreach (array_chunk($tzOutletIds, $outletChunkSize) as $outletChunk) {
                foreach ($windows as [$windowFrom, $windowTo]) {
                    $this->rebuildCoverageWindow($outletChunk, $windowFrom, $windowTo, $timezone);
                }
            }
        }
    }

    public function saleIdsIfCovered(array $outletIds, ?string $dateFrom, ?string $dateTo, bool $markedOnly = false, ?string $fallbackTimezone = null): ?array
    {
        $normalizedOutletIds = array_values(array_unique(array_filter(array_map('strval', $outletIds))));
        sort($normalizedOutletIds);

        if ($normalizedOutletIds === []) {
            return [];
        }

        $timezoneMap = $this->resolveTimezoneMap($normalizedOutletIds, $fallbackTimezone);
        $groupedOutletIds = [];
        foreach ($normalizedOutletIds as $outletId) {
            $timezone = $timezoneMap[$outletId] ?? TransactionDate::normalizeTimezone($fallbackTimezone, TransactionDate::appTimezone());
            $groupedOutletIds[$timezone] ??= [];
            $groupedOutletIds[$timezone][] = $outletId;
        }

        $ids = [];
        foreach ($groupedOutletIds as $timezone => $tzOutletIds) {
            [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, $timezone);
            if (! $this->hasFreshCoverage($tzOutletIds, $fromDate, $toDate, $timezone)) {
                return null;
            }

            $query = DB::table('report_sale_business_dates as rsbd')
                ->select('rsbd.sale_id')
                ->whereIn('rsbd.outlet_id', $tzOutletIds)
                ->where('rsbd.business_timezone', '=', $timezone)
                ->whereBetween('rsbd.business_date', [$fromDate, $toDate]);

            if ($markedOnly) {
                $query->whereRaw('COALESCE(CAST(rsbd.marking AS SIGNED), 0) = 1');
            }

            foreach ($query->distinct()->pluck('rsbd.sale_id') as $saleId) {
                $saleId = (string) $saleId;
                if ($saleId !== '') {
                    $ids[$saleId] = true;
                }
            }
        }

        $resolved = array_keys($ids);
        sort($resolved);

        return $resolved;
    }

    public function saleIdsCoveredSubquery(array $outletIds, ?string $dateFrom, ?string $dateTo, bool $markedOnly = false, ?string $fallbackTimezone = null): ?Builder
    {
        $normalizedOutletIds = array_values(array_unique(array_filter(array_map('strval', $outletIds))));
        sort($normalizedOutletIds);

        if ($normalizedOutletIds === []) {
            return $this->emptySaleIdSubquery();
        }

        $timezoneMap = $this->resolveTimezoneMap($normalizedOutletIds, $fallbackTimezone);
        $groupedOutletIds = [];
        foreach ($normalizedOutletIds as $outletId) {
            $timezone = $timezoneMap[$outletId] ?? TransactionDate::normalizeTimezone($fallbackTimezone, TransactionDate::appTimezone());
            $groupedOutletIds[$timezone] ??= [];
            $groupedOutletIds[$timezone][] = $outletId;
        }

        $queries = [];
        foreach ($groupedOutletIds as $timezone => $tzOutletIds) {
            [$fromDate, $toDate] = $this->normalizeDateRange($dateFrom, $dateTo, $timezone);
            if (! $this->hasFreshCoverage($tzOutletIds, $fromDate, $toDate, $timezone)) {
                return null;
            }

            $query = DB::table('report_sale_business_dates as rsbd')
                ->selectRaw('rsbd.sale_id as id')
                ->whereIn('rsbd.outlet_id', $tzOutletIds)
                ->where('rsbd.business_timezone', '=', $timezone)
                ->whereBetween('rsbd.business_date', [$fromDate, $toDate]);

            if ($markedOnly) {
                $query->whereRaw('COALESCE(CAST(rsbd.marking AS SIGNED), 0) = 1');
            }

            $queries[] = $query;
        }

        return $this->unionSaleIdQueries($queries);
    }

    public function saleIdsSubquery(array $outletIds, ?string $dateFrom, ?string $dateTo, bool $markedOnly = false, ?string $fallbackTimezone = null): Builder
    {
        $normalizedOutletIds = array_values(array_unique(array_filter(array_map('strval', $outletIds))));
        sort($normalizedOutletIds);

        if ($normalizedOutletIds === []) {
            return $this->emptySaleIdSubquery();
        }

        $this->ensureCoverage($normalizedOutletIds, $dateFrom, $dateTo, $fallbackTimezone);

        return $this->saleIdsCoveredSubquery($normalizedOutletIds, $dateFrom, $dateTo, $markedOnly, $fallbackTimezone)
            ?? $this->emptySaleIdSubquery();
    }

    private function rebuildCoverageWindow(array $outletIds, string $fromDate, string $toDate, string $timezone): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');

        DB::table('report_sale_business_date_coverage')
            ->whereIn('outlet_id', $outletIds)
            ->where('business_timezone', '=', $timezone)
            ->whereBetween('business_date', [$fromDate, $toDate])
            ->delete();

        DB::table('report_sale_business_dates')
            ->whereIn('outlet_id', $outletIds)
            ->whereBetween('business_date', [$fromDate, $toDate])
            ->delete();

        $candidateQuery = DB::table('sales as s')
            ->select(['s.id', 's.outlet_id', 's.sale_number', 's.created_at', 's.marking'])
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID')
            ->whereIn('s.outlet_id', $outletIds);

        $candidateDateTo = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone()) === 'Asia/Makassar'
            ? CarbonImmutable::parse($toDate, $timezone)->addDay()->toDateString()
            : $toDate;

        $this->applyCashierCandidateScope(
            $candidateQuery,
            's.sale_number',
            's.created_at',
            $fromDate,
            $candidateDateTo,
            $timezone
        );

        $candidateQuery
            ->orderBy('s.id')
            ->chunkById(self::CANDIDATE_SALE_CHUNK_SIZE, function ($chunk) use ($fromDate, $toDate, $timezone, $timestamp) {
                $rows = [];
                foreach ($chunk as $sale) {
                    $businessDate = $this->resolveExactBusinessDate(
                        $sale->created_at ?? null,
                        isset($sale->sale_number) ? (string) $sale->sale_number : null,
                        $timezone
                    );

                    if (! $businessDate || $businessDate < $fromDate || $businessDate > $toDate) {
                        continue;
                    }

                    $saleId = (string) ($sale->id ?? '');
                    $outletId = (string) ($sale->outlet_id ?? '');
                    if ($saleId === '' || $outletId === '') {
                        continue;
                    }

                    $rows[] = [
                        'sale_id' => $saleId,
                        'outlet_id' => $outletId,
                        'business_timezone' => $timezone,
                        'business_date' => $businessDate,
                        'marking' => (int) ($sale->marking ?? 0),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }

                foreach (array_chunk($rows, 1000) as $insertChunk) {
                    DB::table('report_sale_business_dates')->insertOrIgnore($insertChunk);
                }
            }, 's.id', 'id');

        $coverageRows = [];
        for ($cursor = CarbonImmutable::parse($fromDate, $timezone); $cursor->lessThanOrEqualTo(CarbonImmutable::parse($toDate, $timezone)); $cursor = $cursor->addDay()) {
            $businessDate = $cursor->toDateString();
            foreach ($outletIds as $outletId) {
                $coverageRows[] = [
                    'outlet_id' => (string) $outletId,
                    'business_timezone' => $timezone,
                    'business_date' => $businessDate,
                    'synced_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        foreach (array_chunk($coverageRows, 1000) as $chunk) {
            DB::table('report_sale_business_date_coverage')->insertOrIgnore($chunk);
        }
    }


    private function unionSaleIdQueries(array $queries): Builder
    {
        if ($queries === []) {
            return $this->emptySaleIdSubquery();
        }

        $query = array_shift($queries);
        foreach ($queries as $unionQuery) {
            $query->unionAll($unionQuery);
        }

        return DB::query()
            ->fromSub($query, 'covered_sale_ids')
            ->selectRaw('DISTINCT covered_sale_ids.id as id');
    }

    private function emptySaleIdSubquery(): Builder
    {
        return DB::table('report_sale_business_dates as rsbd')
            ->selectRaw('rsbd.sale_id as id')
            ->whereRaw('1 = 0');
    }

    private function refreshWindows(array $outletIds, string $fromDate, string $toDate, string $timezone): array
    {
        $rows = DB::table('report_sale_business_date_coverage')
            ->selectRaw('business_date, COUNT(DISTINCT outlet_id) as outlet_count, MAX(synced_at) as last_synced_at')
            ->whereIn('outlet_id', $outletIds)
            ->where('business_timezone', '=', $timezone)
            ->whereBetween('business_date', [$fromDate, $toDate])
            ->groupBy('business_date')
            ->get()
            ->keyBy(fn ($row) => (string) ($row->business_date ?? ''));

        $requiredOutletCount = count($outletIds);
        $datesNeedingRefresh = [];
        $refreshThreshold = now()->subMinutes(20);
        $hotDates = $this->hotBusinessDates($timezone);

        for ($cursor = CarbonImmutable::parse($fromDate, $timezone); $cursor->lessThanOrEqualTo(CarbonImmutable::parse($toDate, $timezone)); $cursor = $cursor->addDay()) {
            $date = $cursor->toDateString();
            $row = $rows->get($date);
            if (! $row || (int) ($row->outlet_count ?? 0) < $requiredOutletCount) {
                $datesNeedingRefresh[] = $date;
                continue;
            }

            if (in_array($date, $hotDates, true)) {
                try {
                    $lastSynced = CarbonImmutable::parse((string) ($row->last_synced_at ?? ''), config('app.timezone', 'Asia/Jakarta'));
                } catch (\Throwable $e) {
                    $datesNeedingRefresh[] = $date;
                    continue;
                }

                if ($lastSynced->lessThan($refreshThreshold)) {
                    $datesNeedingRefresh[] = $date;
                }
            }
        }

        if ($datesNeedingRefresh === []) {
            return [];
        }

        $windows = [];
        $windowStart = null;
        $previous = null;
        foreach ($datesNeedingRefresh as $date) {
            $current = CarbonImmutable::parse($date, $timezone);
            if ($windowStart === null) {
                $windowStart = $date;
                $previous = $current;
                continue;
            }

            if ($previous && $current->equalTo($previous->addDay())) {
                $previous = $current;
                continue;
            }

            $windows[] = [$windowStart, $previous?->toDateString() ?? $windowStart];
            $windowStart = $date;
            $previous = $current;
        }

        if ($windowStart !== null) {
            $windows[] = [$windowStart, $previous?->toDateString() ?? $windowStart];
        }

        return $windows;
    }

    private function hasFreshCoverage(array $outletIds, string $fromDate, string $toDate, string $timezone): bool
    {
        return $this->refreshWindows($outletIds, $fromDate, $toDate, $timezone) === [];
    }

    private function hotBusinessDates(string $timezone): array
    {
        $today = CarbonImmutable::parse(TransactionDate::businessTodayDateString($timezone), $timezone);

        return [
            $today->toDateString(),
            $today->subDay()->toDateString(),
        ];
    }

    private function resolveExactBusinessDate($createdAt, ?string $saleNumber, ?string $timezone = null): ?string
    {
        $tz = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        $localText = TransactionDate::formatSaleLocal($createdAt, $tz, $saleNumber);
        if (! $localText) {
            return null;
        }

        try {
            $moment = CarbonImmutable::parse($localText, $tz);
        } catch (\Throwable $e) {
            return null;
        }

        $startHour = TransactionDate::businessDayStartHour($tz);
        if ($startHour > 0) {
            $moment = $moment->subHours($startHour);
        }

        return $moment->toDateString();
    }

    private function resolveTimezoneMap(array $outletIds, ?string $fallbackTimezone = null): array
    {
        $fallback = TransactionDate::normalizeTimezone($fallbackTimezone, TransactionDate::appTimezone());
        $rows = DB::table('outlets')
            ->whereIn('id', $outletIds)
            ->get(['id', 'timezone']);

        $map = [];
        foreach ($rows as $row) {
            $map[(string) ($row->id ?? '')] = TransactionDate::normalizeTimezone((string) ($row->timezone ?? ''), $fallback);
        }

        foreach ($outletIds as $outletId) {
            $map[(string) $outletId] = $map[(string) $outletId] ?? $fallback;
        }

        return $map;
    }

    private function normalizeOutletChunkSize(mixed $value): int
    {
        $size = (int) $value;

        return max(1, min(50, $size > 0 ? $size : self::DEFAULT_OUTLET_CHUNK_SIZE));
    }

    private function normalizeDateChunkDays(mixed $value): int
    {
        $days = (int) $value;

        return max(1, min(31, $days > 0 ? $days : self::DEFAULT_DATE_CHUNK_DAYS));
    }

    private function splitWindowsByDays(array $windows, string $timezone, int $maxDays): array
    {
        $resolved = [];

        foreach ($windows as [$windowFrom, $windowTo]) {
            $cursor = CarbonImmutable::parse($windowFrom, $timezone);
            $end = CarbonImmutable::parse($windowTo, $timezone);

            while ($cursor->lessThanOrEqualTo($end)) {
                $chunkEnd = $cursor->addDays($maxDays - 1);
                if ($chunkEnd->greaterThan($end)) {
                    $chunkEnd = $end;
                }

                $resolved[] = [$cursor->toDateString(), $chunkEnd->toDateString()];
                $cursor = $chunkEnd->addDay();
            }
        }

        return $resolved;
    }

    private function normalizeDateRange(?string $dateFrom, ?string $dateTo, ?string $fallbackTimezone = null): array
    {
        $tz = TransactionDate::normalizeTimezone($fallbackTimezone, TransactionDate::appTimezone());
        $today = CarbonImmutable::parse(TransactionDate::businessTodayDateString($tz), $tz)->toDateString();

        try {
            $from = $dateFrom ? CarbonImmutable::parse($dateFrom, $tz)->toDateString() : $today;
        } catch (\Throwable $e) {
            $from = $today;
        }

        try {
            $to = $dateTo ? CarbonImmutable::parse($dateTo, $tz)->toDateString() : $today;
        } catch (\Throwable $e) {
            $to = $today;
        }

        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    private function applyCashierCandidateScope(object $query, ?string $saleNumberColumn, string $createdAtColumn, ?string $dateFrom, ?string $dateTo, ?string $timezone = null): void
    {
        [, , $fromUtc, $toUtc] = TransactionDate::dateRange(
            $dateFrom,
            $dateTo,
            $timezone ?: TransactionDate::appTimezone()
        );

        $tokens = TransactionDate::dateTokens($dateFrom, $dateTo, $timezone ?: TransactionDate::appTimezone());
        if (! $saleNumberColumn || empty($tokens)) {
            $query->whereBetween($createdAtColumn, [$fromUtc->toDateTimeString(), $toUtc->toDateTimeString()]);
            return;
        }

        $query->where(function ($outer) use ($saleNumberColumn, $createdAtColumn, $tokens, $fromUtc, $toUtc) {
            $outer->where(function ($saleNumberScope) use ($saleNumberColumn, $tokens) {
                foreach ($tokens as $index => $token) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $saleNumberScope->{$method}($saleNumberColumn, 'like', '%-' . $token . '-%');
                }
            })->orWhere(function ($fallbackScope) use ($saleNumberColumn, $createdAtColumn, $fromUtc, $toUtc) {
                $fallbackScope
                    ->where(function ($legacyScope) use ($saleNumberColumn) {
                        $legacyScope
                            ->whereNull($saleNumberColumn)
                            ->orWhere($saleNumberColumn, 'not like', 'S.%-%-%');
                    })
                    ->whereBetween($createdAtColumn, [$fromUtc->toDateTimeString(), $toUtc->toDateTimeString()]);
            });
        });
    }
}
