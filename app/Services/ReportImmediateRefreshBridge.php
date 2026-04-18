<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Sale;
use App\Support\AnalyticsResponseCache;
use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ReportImmediateRefreshBridge
{
    public function refreshForSaleId(?string $saleId): void
    {
        $saleId = trim((string) ($saleId ?? ''));
        if ($saleId === '') {
            return;
        }

        $sale = Sale::query()
            ->with('outlet:id,timezone')
            ->select(['id', 'outlet_id', 'sale_number', 'created_at'])
            ->whereKey($saleId)
            ->first();

        if (! $sale) {
            return;
        }

        $this->refreshForSale($sale);
    }

    public function refreshForSale($sale): void
    {
        if (! $sale) {
            return;
        }

        $outletId = trim((string) ($sale->outlet_id ?? ''));
        if ($outletId === '') {
            return;
        }

        [$businessDate, $timezone] = $this->resolveBusinessDateAndTimezone(
            trim((string) ($sale->id ?? '')),
            $outletId,
            $sale->created_at ?? null,
            isset($sale->sale_number) ? (string) $sale->sale_number : null,
            isset($sale->outlet->timezone) ? (string) $sale->outlet->timezone : null,
        );

        if ($businessDate === '') {
            return;
        }

        $this->refreshSingleDate($outletId, $businessDate, $timezone);
    }

    public function refreshSingleDate(string $outletId, string $businessDate, ?string $timezone = null): void
    {
        $outletId = trim($outletId);
        $businessDate = trim($businessDate);
        if ($outletId === '' || $businessDate === '') {
            return;
        }

        $timezone = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        $lockKey = 'report-immediate-refresh:' . md5($outletId . '|' . $businessDate);

        try {
            $lock = Cache::lock($lockKey, 20);
            if (! $lock->get()) {
                return;
            }
        } catch (\Throwable) {
            $lock = null;
        }

        try {
            if ($this->refreshThroughQueueService($outletId, $businessDate, $timezone)) {
                return;
            }

            $this->refreshDirectCoverage($outletId, $businessDate, $timezone);
        } catch (\Throwable $e) {
            Log::warning('Immediate report refresh skipped after ledger action.', [
                'outlet_id' => $outletId,
                'business_date' => $businessDate,
                'timezone' => $timezone,
                'error' => $e->getMessage(),
            ]);
        } finally {
            try {
                if ($lock) {
                    $lock->release();
                }
            } catch (\Throwable) {
                // noop
            }
        }
    }

    private function refreshThroughQueueService(string $outletId, string $businessDate, string $timezone): bool
    {
        if (! class_exists(ReportDailySummaryRefreshService::class)) {
            return false;
        }

        /** @var ReportDailySummaryRefreshService $service */
        $service = app(ReportDailySummaryRefreshService::class);
        $service->markOutletDate($outletId, $businessDate, $timezone, 'immediate_action');
        $result = $service->processPending(1, 1, 1);

        return (int) ($result['processed_rows'] ?? 0) > 0;
    }

    private function refreshDirectCoverage(string $outletId, string $businessDate, string $timezone): void
    {
        if (! class_exists(ReportSaleBusinessDateIndexService::class) || ! class_exists(ReportDailySummaryService::class)) {
            return;
        }

        /** @var ReportSaleBusinessDateIndexService $businessDateIndex */
        $businessDateIndex = app(ReportSaleBusinessDateIndexService::class);
        $businessDateIndex->refreshExactCoverage([$outletId], $businessDate, $businessDate, $timezone, [
            'outlet_chunk' => 1,
            'date_chunk_days' => 1,
        ]);

        /** @var ReportDailySummaryService $dailySummaryService */
        $dailySummaryService = app(ReportDailySummaryService::class);
        $dailySummaryService->refreshExactCoverage([$outletId], $businessDate, $businessDate, $timezone, [
            'outlet_chunk' => 1,
            'date_chunk_days' => 1,
        ]);

        try {
            AnalyticsResponseCache::bumpVersion('immediate-direct-refresh:' . $outletId . ':' . $businessDate);
        } catch (\Throwable) {
            // noop
        }
    }

    private function resolveBusinessDateAndTimezone(?string $saleId, string $outletId, $createdAt, ?string $saleNumber, ?string $timezone = null): array
    {
        $saleId = trim((string) ($saleId ?? ''));

        if ($saleId !== '' && Schema::hasTable('report_sale_business_dates')) {
            try {
                $row = DB::table('report_sale_business_dates')
                    ->where('sale_id', $saleId)
                    ->select(['business_date', 'business_timezone'])
                    ->first();

                if ($row && ! empty($row->business_date)) {
                    return [
                        (string) $row->business_date,
                        TransactionDate::normalizeTimezone((string) ($row->business_timezone ?? $timezone), TransactionDate::appTimezone()),
                    ];
                }
            } catch (\Throwable) {
                // fallback below
            }
        }

        $resolvedTimezone = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        if ($resolvedTimezone === TransactionDate::appTimezone()) {
            try {
                $outletTimezone = Outlet::query()->whereKey($outletId)->value('timezone');
                $resolvedTimezone = TransactionDate::normalizeTimezone((string) ($outletTimezone ?? ''), $resolvedTimezone);
            } catch (\Throwable) {
                // keep fallback
            }
        }

        $localText = TransactionDate::formatSaleLocal($createdAt, $resolvedTimezone, $saleNumber);
        if (! $localText) {
            return ['', $resolvedTimezone];
        }

        try {
            $moment = CarbonImmutable::parse($localText, $resolvedTimezone);
        } catch (\Throwable) {
            return ['', $resolvedTimezone];
        }

        $startHour = TransactionDate::businessDayStartHour($resolvedTimezone);
        if ($startHour > 0) {
            $moment = $moment->subHours($startHour);
        }

        return [$moment->toDateString(), $resolvedTimezone];
    }
}
