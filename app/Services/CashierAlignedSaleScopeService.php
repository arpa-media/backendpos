<?php

namespace App\Services;

use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CashierAlignedSaleScopeService
{
    public function eligibleSaleIds(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $fallbackTimezone = null): array
    {
        $normalizedOutletIds = array_values(array_unique(array_filter(array_map('strval', $outletIds))));
        sort($normalizedOutletIds);

        if ($normalizedOutletIds === []) {
            return [];
        }

        $timezoneMap = $this->resolveTimezoneMap($normalizedOutletIds, $fallbackTimezone);
        $cacheKey = 'cashier-aligned-sale-ids:' . sha1(json_encode([
            'outlets' => $normalizedOutletIds,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'timezones' => $timezoneMap,
        ]));

        return Cache::remember($cacheKey, now()->addSeconds(20), function () use ($normalizedOutletIds, $timezoneMap, $dateFrom, $dateTo) {
            $ids = [];
            $groupedOutletIds = [];
            foreach ($normalizedOutletIds as $outletId) {
                $timezone = $timezoneMap[$outletId] ?? TransactionDate::appTimezone();
                $groupedOutletIds[$timezone] ??= [];
                $groupedOutletIds[$timezone][] = $outletId;
            }

            foreach ($groupedOutletIds as $timezone => $tzOutletIds) {
                $window = $this->resolveCashierBusinessWindow($dateFrom, $dateTo, $timezone);
                $candidateQuery = DB::table('sales as s')
                    ->select(['s.id', 's.sale_number', 's.created_at'])
                    ->whereNull('s.deleted_at')
                    ->where('s.status', '=', 'PAID')
                    ->whereIn('s.outlet_id', $tzOutletIds);

                if ($this->isMakassarCashierBusinessTimezone($timezone)) {
                    $candidateDateTo = $window['requested_to']->addDay()->toDateString();
                    $this->applyCashierCandidateScope(
                        $candidateQuery,
                        's.sale_number',
                        's.created_at',
                        $window['requested_from']->toDateString(),
                        $candidateDateTo,
                        $timezone
                    );
                } else {
                    $this->applyCashierCandidateScope(
                        $candidateQuery,
                        's.sale_number',
                        's.created_at',
                        $window['requested_from']->toDateString(),
                        $window['requested_to']->toDateString(),
                        $timezone
                    );
                }

                $candidateIds = $candidateQuery->orderBy('s.created_at')->orderBy('s.sale_number')->get()
                    ->filter(fn ($sale) => $this->saleFallsWithinCashierBusinessWindow($sale->created_at ?? null, $sale->sale_number ?? null, $window['from_local'], $window['to_exclusive_local'], $timezone))
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->all();

                $ids = array_merge($ids, $candidateIds);
            }

            $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
            sort($ids);
            return $ids;
        });
    }

    private function resolveTimezoneMap(array $outletIds, ?string $fallbackTimezone = null): array
    {
        $fallback = TransactionDate::normalizeTimezone($fallbackTimezone, TransactionDate::appTimezone());
        $rows = DB::table('outlets')
            ->whereIn('id', $outletIds)
            ->get(['id', 'timezone']);

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->id] = TransactionDate::normalizeTimezone((string) ($row->timezone ?? ''), $fallback);
        }

        foreach ($outletIds as $outletId) {
            $map[(string) $outletId] = $map[(string) $outletId] ?? $fallback;
        }

        return $map;
    }

    private function isMakassarCashierBusinessTimezone(?string $timezone = null): bool
    {
        return TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone()) === 'Asia/Makassar';
    }

    private function resolveCashierBusinessToday(?string $timezone = null): string
    {
        $tz = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        $now = CarbonImmutable::now($tz);

        if ($this->isMakassarCashierBusinessTimezone($tz)) {
            return $now->subHour()->toDateString();
        }

        return $now->toDateString();
    }

    private function resolveCashierBusinessWindow(?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
    {
        $tz = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        $today = CarbonImmutable::parse($this->resolveCashierBusinessToday($tz), $tz)->startOfDay();

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

        if ($this->isMakassarCashierBusinessTimezone($tz)) {
            $fromLocal = $requestedFrom->addHour();
            $toExclusiveLocal = $requestedTo->addDay()->addHour();
        } else {
            $fromLocal = $requestedFrom->startOfDay();
            $toExclusiveLocal = $requestedTo->addDay()->startOfDay();
        }

        return [
            'timezone' => $tz,
            'requested_from' => $requestedFrom,
            'requested_to' => $requestedTo,
            'from_local' => $fromLocal,
            'to_exclusive_local' => $toExclusiveLocal,
            'to_inclusive_local' => $toExclusiveLocal->subSecond(),
        ];
    }

    private function saleFallsWithinCashierBusinessWindow($createdAt, ?string $saleNumber, CarbonImmutable $fromLocal, CarbonImmutable $toExclusiveLocal, ?string $timezone = null): bool
    {
        $localText = TransactionDate::formatSaleLocal($createdAt, $timezone, $saleNumber);
        if (!$localText) {
            return false;
        }

        try {
            $moment = CarbonImmutable::parse($localText, TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone()));
        } catch (\Throwable $e) {
            return false;
        }

        return $moment->greaterThanOrEqualTo($fromLocal) && $moment->lessThan($toExclusiveLocal);
    }

    private function applyCashierCandidateScope(object $query, ?string $saleNumberColumn, string $createdAtColumn, ?string $dateFrom, ?string $dateTo, ?string $timezone = null): void
    {
        [$fromLocal, $toLocal, $fromUtc, $toUtc] = TransactionDate::dateRange(
            $dateFrom,
            $dateTo,
            $timezone ?: TransactionDate::appTimezone()
        );

        $tokens = TransactionDate::dateTokens($dateFrom, $dateTo, $timezone ?: TransactionDate::appTimezone());
        if (!$saleNumberColumn || empty($tokens)) {
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
