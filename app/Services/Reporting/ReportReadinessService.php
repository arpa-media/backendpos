<?php

namespace App\Services\Reporting;

use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * V11 I01 single source of truth for daily materialization readiness.
 *
 * This service is deliberately read-only. It never warms, rebuilds, deletes or
 * acknowledges refresh queue rows. A physical coverage row is counted as ready
 * only when it is not premature and has no refresh marker newer than synced_at.
 */
class ReportReadinessService
{
    public function dailyMatrixStatus(array $outletIds, string $fromDate, string $toDate, ?string $timezone = null): array
    {
        $outletIds = $this->normalizeOutletIds($outletIds);
        $timezone = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        [$fromDate, $toDate] = $this->normalizeRange($fromDate, $toDate, $timezone);

        $days = CarbonImmutable::parse($fromDate, $timezone)
            ->diffInDays(CarbonImmutable::parse($toDate, $timezone)) + 1;
        $expectedRows = count($outletIds) * $days;

        $base = [
            'contract' => 'erp_pos_v11_i01_real_readiness',
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'range_days' => $days,
            'outlet_count' => count($outletIds),
            'expected_rows' => $expectedRows,
            'physical_covered_rows' => 0,
            'physical_missing_rows' => $expectedRows,
            'physical_coverage_percent' => $expectedRows > 0 ? 0.0 : 100.0,
            'ready_rows' => 0,
            'not_ready_rows' => $expectedRows,
            'readiness_percent' => $expectedRows > 0 ? 0.0 : 100.0,
            'freshness_percent' => $expectedRows > 0 ? 0.0 : 100.0,
            'premature_rows' => 0,
            'raw_pending_refresh_rows' => 0,
            'effective_pending_refresh_rows' => 0,
            'stale_refresh_flag_rows' => 0,
            'missing_dates' => [],
            'premature_dates' => [],
            'effective_pending_dates' => [],
            'stale_refresh_flag_dates' => [],
            'dirty_dates' => [],
            'dirty_outlet_ids' => [],
            'oldest_synced_at' => null,
            'latest_synced_at' => null,
            'ready' => $expectedRows === 0,
        ];

        if ($expectedRows === 0) {
            return $base;
        }

        if (! Schema::hasTable('report_daily_summary_coverage')) {
            $allDates = $this->allDates($fromDate, $toDate, $timezone);
            return array_merge($base, [
                'missing_dates' => $allDates,
                'dirty_dates' => $allDates,
                'dirty_outlet_ids' => $outletIds,
            ]);
        }

        $allDates = $this->allDates($fromDate, $toDate, $timezone);

        $coverageRows = DB::table('report_daily_summary_coverage')
            ->whereIn('outlet_id', $outletIds)
            ->whereBetween('business_date', [$fromDate, $toDate])
            ->get(['outlet_id', 'business_date', 'synced_at']);

        $coverageByKey = [];
        $coverageCountByDate = [];
        $prematureKeys = [];
        $prematureDates = [];
        $dirtyOutlets = [];
        $oldestSyncedAt = null;
        $latestSyncedAt = null;

        foreach ($coverageRows as $row) {
            $outletId = (string) ($row->outlet_id ?? '');
            $businessDate = (string) ($row->business_date ?? '');
            if ($outletId === '' || $businessDate === '') {
                continue;
            }

            $key = $this->pairKey($outletId, $businessDate);
            $coverageByKey[$key] = $row;
            $coverageCountByDate[$businessDate] = ($coverageCountByDate[$businessDate] ?? 0) + 1;

            $syncedAt = trim((string) ($row->synced_at ?? ''));
            if ($syncedAt !== '') {
                $oldestSyncedAt = $oldestSyncedAt === null || $syncedAt < $oldestSyncedAt ? $syncedAt : $oldestSyncedAt;
                $latestSyncedAt = $latestSyncedAt === null || $syncedAt > $latestSyncedAt ? $syncedAt : $latestSyncedAt;
            }

            if ($this->coverageWasPremature($row->synced_at ?? null, $businessDate, $timezone)) {
                $prematureKeys[$key] = true;
                $prematureDates[$businessDate] = true;
                $dirtyOutlets[$outletId] = true;
            }
        }

        $missingDates = [];
        $requiredOutlets = count($outletIds);
        foreach ($allDates as $date) {
            if ((int) ($coverageCountByDate[$date] ?? 0) < $requiredOutlets) {
                $missingDates[$date] = true;
            }
        }

        // Missing outlet IDs are required for per-outlet/month readiness. The matrix is
        // bounded (normally <= 20 outlets x 370 days), so this read-only pass is cheap
        // compared with report aggregation itself and avoids false READY states.
        foreach ($outletIds as $outletId) {
            foreach ($allDates as $date) {
                if (! isset($coverageByKey[$this->pairKey($outletId, $date)])) {
                    $dirtyOutlets[$outletId] = true;
                    break;
                }
            }
        }

        $rawPending = 0;
        $effectivePendingKeys = [];
        $effectivePendingDates = [];
        $staleFlagKeys = [];
        $staleFlagDates = [];

        if (Schema::hasTable('report_daily_summary_refresh_queue')) {
            $queueRows = DB::table('report_daily_summary_refresh_queue')
                ->whereIn('outlet_id', $outletIds)
                ->whereBetween('business_date', [$fromDate, $toDate])
                ->whereIn('status', ['pending', 'processing'])
                ->get(['outlet_id', 'business_date', 'last_touched_at', 'queued_at', 'updated_at']);

            foreach ($queueRows as $queueRow) {
                $outletId = (string) ($queueRow->outlet_id ?? '');
                $businessDate = (string) ($queueRow->business_date ?? '');
                if ($outletId === '' || $businessDate === '') {
                    continue;
                }

                $rawPending++;
                $key = $this->pairKey($outletId, $businessDate);
                $coverage = $coverageByKey[$key] ?? null;
                $markerAt = $queueRow->last_touched_at ?? $queueRow->queued_at ?? $queueRow->updated_at ?? null;
                $coverageAt = $coverage->synced_at ?? null;

                if ($this->refreshMarkerIsEffective($markerAt, $coverageAt)) {
                    $effectivePendingKeys[$key] = true;
                    $effectivePendingDates[$businessDate] = true;
                    $dirtyOutlets[$outletId] = true;
                } else {
                    $staleFlagKeys[$key] = true;
                    $staleFlagDates[$businessDate] = true;
                }
            }
        }

        $physicalCoveredRows = min($expectedRows, count($coverageByKey));
        $physicalMissingRows = max(0, $expectedRows - $physicalCoveredRows);
        $effectivePendingCoveredKeys = array_intersect_key($effectivePendingKeys, $coverageByKey);
        $notReadyCoveredKeys = $prematureKeys + $effectivePendingCoveredKeys;
        $readyRows = max(0, $physicalCoveredRows - count($notReadyCoveredKeys));
        $notReadyRows = max(0, $expectedRows - $readyRows);

        $dirtyDates = array_values(array_unique(array_merge(
            array_keys($missingDates),
            array_keys($prematureDates),
            array_keys($effectivePendingDates),
        )));
        sort($dirtyDates);

        return array_merge($base, [
            'physical_covered_rows' => $physicalCoveredRows,
            'physical_missing_rows' => $physicalMissingRows,
            'physical_coverage_percent' => $this->percent($physicalCoveredRows, $expectedRows),
            'ready_rows' => $readyRows,
            'not_ready_rows' => $notReadyRows,
            'readiness_percent' => $this->percent($readyRows, $expectedRows),
            'freshness_percent' => $physicalCoveredRows > 0 ? $this->percent($readyRows, $physicalCoveredRows) : 0.0,
            'premature_rows' => count($prematureKeys),
            'raw_pending_refresh_rows' => $rawPending,
            'effective_pending_refresh_rows' => count($effectivePendingKeys),
            'stale_refresh_flag_rows' => count($staleFlagKeys),
            'missing_dates' => array_values(array_keys($missingDates)),
            'premature_dates' => array_values(array_keys($prematureDates)),
            'effective_pending_dates' => array_values(array_keys($effectivePendingDates)),
            'stale_refresh_flag_dates' => array_values(array_keys($staleFlagDates)),
            'dirty_dates' => $dirtyDates,
            'dirty_outlet_ids' => array_values(array_keys($dirtyOutlets)),
            'oldest_synced_at' => $oldestSyncedAt,
            'latest_synced_at' => $latestSyncedAt,
            'ready' => $readyRows === $expectedRows,
        ]);
    }

    private function refreshMarkerIsEffective(mixed $markerAt, mixed $coverageAt): bool
    {
        $marker = trim((string) ($markerAt ?? ''));
        $coverage = trim((string) ($coverageAt ?? ''));

        if ($coverage === '' || $marker === '') {
            return true;
        }

        try {
            return CarbonImmutable::parse($marker)->greaterThan(CarbonImmutable::parse($coverage));
        } catch (\Throwable) {
            return true;
        }
    }

    private function coverageWasPremature(mixed $syncedAt, string $businessDate, string $timezone): bool
    {
        $raw = trim((string) ($syncedAt ?? ''));
        if ($raw === '') {
            return true;
        }

        try {
            $synced = CarbonImmutable::parse($raw, config('app.timezone', 'UTC'))->setTimezone($timezone);
            $businessStart = CarbonImmutable::parse($businessDate, $timezone)
                ->startOfDay()
                ->addHours(TransactionDate::businessDayStartHour($timezone));

            return $synced->lessThan($businessStart);
        } catch (\Throwable) {
            return true;
        }
    }

    private function allDates(string $fromDate, string $toDate, string $timezone): array
    {
        $dates = [];
        $end = CarbonImmutable::parse($toDate, $timezone);
        for ($cursor = CarbonImmutable::parse($fromDate, $timezone); $cursor->lessThanOrEqualTo($end); $cursor = $cursor->addDay()) {
            $dates[] = $cursor->toDateString();
        }
        return $dates;
    }

    private function normalizeRange(string $fromDate, string $toDate, string $timezone): array
    {
        $from = CarbonImmutable::parse($fromDate, $timezone)->toDateString();
        $to = CarbonImmutable::parse($toDate, $timezone)->toDateString();
        return $to < $from ? [$to, $from] : [$from, $to];
    }

    private function normalizeOutletIds(array $outletIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $outletIds))));
        sort($ids);
        return $ids;
    }

    private function pairKey(string $outletId, string $date): string
    {
        return $outletId . '#' . $date;
    }

    private function percent(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : 100.0;
    }
}
