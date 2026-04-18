<?php

namespace App\Services;

use App\Models\Sale;
use App\Support\AnalyticsResponseCache;
use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportDailySummaryRefreshService
{
    private const DEFAULT_RECENT_DAYS = 90;

    public function __construct(
        private readonly ReportSaleBusinessDateIndexService $businessDateIndex,
        private readonly ReportDailySummaryService $dailySummaryService,
    ) {
    }

    public function markSale(Sale|string $saleOrId, string $reason = 'sale_mutation'): void
    {
        $saleId = $saleOrId instanceof Sale ? (string) $saleOrId->id : trim((string) $saleOrId);
        if ($saleId === '') {
            return;
        }

        $row = DB::table('sales as s')
            ->leftJoin('report_sale_business_dates as rsbd', 'rsbd.sale_id', '=', 's.id')
            ->leftJoin('outlets as o', 'o.id', '=', 's.outlet_id')
            ->where('s.id', $saleId)
            ->select([
                's.id',
                's.outlet_id',
                's.sale_number',
                's.created_at',
                'rsbd.business_date',
                'rsbd.business_timezone',
                'o.timezone as outlet_timezone',
            ])
            ->first();

        if (! $row) {
            return;
        }

        $outletId = trim((string) ($row->outlet_id ?? ''));
        if ($outletId === '') {
            return;
        }

        $timezone = TransactionDate::normalizeTimezone((string) ($row->business_timezone ?: $row->outlet_timezone ?: config('app.timezone', 'Asia/Jakarta')));
        $businessDate = trim((string) ($row->business_date ?? ''));

        if ($businessDate === '') {
            $businessDate = $this->resolveBusinessDate(
                $row->created_at ?? null,
                isset($row->sale_number) ? (string) $row->sale_number : null,
                $timezone,
            );
        }

        if ($businessDate === '') {
            return;
        }

        $this->markOutletDate($outletId, $businessDate, $timezone, $reason);
    }

    public function markOutletDate(string $outletId, string $businessDate, ?string $timezone = null, string $reason = 'manual'): void
    {
        $outletId = trim($outletId);
        $businessDate = trim($businessDate);
        if ($outletId === '' || $businessDate === '') {
            return;
        }

        $timestamp = now()->format('Y-m-d H:i:s');
        $normalizedTimezone = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        $normalizedReason = substr(trim($reason) !== '' ? trim($reason) : 'manual', 0, 80);

        $exists = DB::table('report_daily_summary_refresh_queue')
            ->where('outlet_id', $outletId)
            ->where('business_date', $businessDate)
            ->exists();

        if ($exists) {
            DB::table('report_daily_summary_refresh_queue')
                ->where('outlet_id', $outletId)
                ->where('business_date', $businessDate)
                ->update([
                    'business_timezone' => $normalizedTimezone,
                    'reason' => $normalizedReason,
                    'status' => 'pending',
                    'queued_at' => $timestamp,
                    'last_touched_at' => $timestamp,
                    'last_error' => null,
                    'updated_at' => $timestamp,
                    'touch_count' => DB::raw('touch_count + 1'),
                ]);

            return;
        }

        DB::table('report_daily_summary_refresh_queue')->insert([
            'outlet_id' => $outletId,
            'business_date' => $businessDate,
            'business_timezone' => $normalizedTimezone,
            'reason' => $normalizedReason,
            'status' => 'pending',
            'touch_count' => 1,
            'queued_at' => $timestamp,
            'last_touched_at' => $timestamp,
            'last_attempted_at' => null,
            'attempt_count' => 0,
            'last_error' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function markOutletRecentCoverage(string $outletId, int $days = self::DEFAULT_RECENT_DAYS, string $reason = 'bulk_mutation'): int
    {
        $outletId = trim($outletId);
        if ($outletId === '') {
            return 0;
        }

        $days = max(1, min(180, $days));
        $rows = DB::table('report_sale_business_date_coverage')
            ->where('outlet_id', $outletId)
            ->where('business_date', '>=', now()->subDays($days - 1)->toDateString())
            ->orderBy('business_date')
            ->get(['business_date', 'business_timezone']);

        if ($rows->isEmpty()) {
            $timezone = DB::table('outlets')->where('id', $outletId)->value('timezone');
            $this->markOutletDate($outletId, now()->toDateString(), is_string($timezone) ? $timezone : null, $reason);
            return 1;
        }

        foreach ($rows as $row) {
            $this->markOutletDate(
                $outletId,
                (string) ($row->business_date ?? ''),
                (string) ($row->business_timezone ?? ''),
                $reason
            );
        }

        return $rows->count();
    }

    public function processPending(int $limit = 60, int $outletChunk = 4, int $dateChunk = 2): array
    {
        $limit = max(1, min(500, $limit));
        $outletChunk = max(1, min(20, $outletChunk));
        $dateChunk = max(1, min(31, $dateChunk));
        $claimed = $this->claimPendingRows($limit);

        if ($claimed->isEmpty()) {
            return [
                'claimed' => 0,
                'processed_windows' => 0,
                'processed_rows' => 0,
                'failed_rows' => 0,
            ];
        }

        $processedWindows = 0;
        $processedRows = 0;
        $failedRows = 0;

        $groups = $this->groupClaimedRows($claimed, $dateChunk);
        foreach (array_chunk($groups, $outletChunk) as $groupChunk) {
            foreach ($groupChunk as $group) {
                $processedWindows++;
                try {
                    $this->businessDateIndex->refreshExactCoverage($group['outlet_ids'], $group['date_from'], $group['date_to'], $group['timezone'], [
                        'outlet_chunk' => 1,
                        'date_chunk_days' => $dateChunk,
                    ]);
                    $this->dailySummaryService->refreshExactCoverage($group['outlet_ids'], $group['date_from'], $group['date_to'], $group['timezone'], [
                        'outlet_chunk' => 1,
                        'date_chunk_days' => $dateChunk,
                    ]);

                    DB::table('report_daily_summary_refresh_queue')
                        ->whereIn(DB::raw("CONCAT(outlet_id, '#', business_date)"), $group['queue_keys'])
                        ->delete();

                    $processedRows += count($group['queue_keys']);
                } catch (\Throwable $e) {
                    $failedRows += count($group['queue_keys']);
                    DB::table('report_daily_summary_refresh_queue')
                        ->whereIn(DB::raw("CONCAT(outlet_id, '#', business_date)"), $group['queue_keys'])
                        ->update([
                            'status' => 'pending',
                            'last_error' => substr($e->getMessage(), 0, 1000),
                            'updated_at' => now(),
                        ]);
                }
            }
        }

        if ($processedRows > 0) {
            AnalyticsResponseCache::bumpVersion('daily-summary-refresh:' . $processedRows . ':' . $processedWindows);
        }

        return [
            'claimed' => $claimed->count(),
            'processed_windows' => $processedWindows,
            'processed_rows' => $processedRows,
            'failed_rows' => $failedRows,
        ];
    }

    private function claimPendingRows(int $limit): Collection
    {
        return DB::transaction(function () use ($limit) {
            $rows = DB::table('report_daily_summary_refresh_queue')
                ->where('status', 'pending')
                ->orderBy('business_date')
                ->orderBy('outlet_id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                return collect();
            }

            $keys = $rows->map(fn ($row) => sprintf('%s#%s', (string) ($row->outlet_id ?? ''), (string) ($row->business_date ?? '')))->all();
            DB::table('report_daily_summary_refresh_queue')
                ->whereIn(DB::raw("CONCAT(outlet_id, '#', business_date)"), $keys)
                ->update([
                    'status' => 'processing',
                    'last_attempted_at' => now(),
                    'updated_at' => now(),
                    'attempt_count' => DB::raw('attempt_count + 1'),
                ]);

            return $rows;
        }, 3);
    }

    private function groupClaimedRows(Collection $rows, int $dateChunk): array
    {
        $bucketed = [];
        foreach ($rows as $row) {
            $outletId = (string) ($row->outlet_id ?? '');
            $timezone = TransactionDate::normalizeTimezone((string) ($row->business_timezone ?? ''), TransactionDate::appTimezone());
            $date = (string) ($row->business_date ?? '');
            if ($outletId === '' || $date === '') {
                continue;
            }
            $bucketed[$timezone][$outletId][] = [
                'date' => $date,
                'key' => sprintf('%s#%s', $outletId, $date),
            ];
        }

        $groups = [];
        foreach ($bucketed as $timezone => $byOutlet) {
            foreach ($byOutlet as $outletId => $items) {
                usort($items, fn ($a, $b) => strcmp($a['date'], $b['date']));
                $windowDates = [];
                $windowKeys = [];
                $prev = null;
                foreach ($items as $item) {
                    $curr = CarbonImmutable::parse($item['date'], $timezone);
                    $isContiguous = $prev && $curr->equalTo($prev->addDay());
                    if ($windowDates !== [] && (! $isContiguous || count($windowDates) >= $dateChunk)) {
                        $groups[] = [
                            'timezone' => $timezone,
                            'outlet_ids' => [$outletId],
                            'date_from' => $windowDates[0],
                            'date_to' => end($windowDates),
                            'queue_keys' => $windowKeys,
                        ];
                        $windowDates = [];
                        $windowKeys = [];
                    }
                    $windowDates[] = $item['date'];
                    $windowKeys[] = $item['key'];
                    $prev = $curr;
                }
                if ($windowDates !== []) {
                    $groups[] = [
                        'timezone' => $timezone,
                        'outlet_ids' => [$outletId],
                        'date_from' => $windowDates[0],
                        'date_to' => end($windowDates),
                        'queue_keys' => $windowKeys,
                    ];
                }
            }
        }

        return $groups;
    }

    private function resolveBusinessDate(mixed $createdAt, ?string $saleNumber, ?string $timezone = null): string
    {
        $timezone = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        $localText = TransactionDate::formatSaleLocal($createdAt, $timezone, $saleNumber);
        if (! $localText) {
            return '';
        }

        try {
            $moment = CarbonImmutable::parse($localText, $timezone);
        } catch (\Throwable $e) {
            return '';
        }

        $startHour = TransactionDate::businessDayStartHour($timezone);
        if ($startHour > 0) {
            $moment = $moment->subHours($startHour);
        }

        return $moment->toDateString();
    }
}
