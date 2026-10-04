<?php

namespace App\Services\Reporting;

use App\Services\Operational\ReportHourlySummaryService;
use App\Services\ReportDailySummaryService;
use App\Support\AnalyticsResponseCache;
use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * V11 I03 selective repair pipeline.
 *
 * Important safety contract:
 * - always reconciles first;
 * - only I02 NEEDS_REBUILD outlet/date pairs are force-refreshed;
 * - raw-vs-daily verification must pass before queue acknowledgement;
 * - acknowledgement is conditional on the original queue snapshot so a newer
 *   transaction mutation can never be deleted by an older repair process.
 */
class ReportSmartRepairService
{
    public const REPAIRED = 'REPAIRED';
    public const FAILED_VERIFY = 'FAILED_VERIFY';
    public const FAILED_REFRESH = 'FAILED_REFRESH';
    public const RACE_CHANGED = 'RACE_CHANGED';
    public const ALREADY_RESOLVED = 'ALREADY_RESOLVED';

    public function __construct(
        private readonly ReportRefreshReconciliationService $reconciliation,
        private readonly ReportDailySummaryService $daily,
        private readonly ReportCorrectnessAuditService $audit,
        private readonly ReportHourlySummaryService $hourly,
        private readonly ReportMonthlySummaryService $monthly,
        private readonly ReportReadinessService $readiness,
    ) {
    }

    public function planMonth(
        string $month,
        ?array $requestedOutletIds = null,
        int $limit = 2000,
        ?string $actorUserId = null,
    ): array {
        $this->assertSchema();
        $result = $this->reconciliation->reconcileMonth(
            $month,
            $requestedOutletIds,
            false,
            $limit,
            $actorUserId,
        );

        return [
            'contract' => 'erp_pos_v11_i03_smart_repair_plan',
            'month' => $month,
            'reconciliation_run_id' => $result['run_id'] ?? null,
            'candidate_rows' => (int) ($result['candidate_rows'] ?? 0),
            'safe_without_rebuild' => (int) ($result['resolvable_rows'] ?? 0),
            'needs_rebuild_rows' => (int) ($result['needs_rebuild_rows'] ?? 0),
            'race_changed_rows' => (int) ($result['race_changed_rows'] ?? 0),
            'will_force_full_month' => false,
            'next_action' => (int) ($result['needs_rebuild_rows'] ?? 0) > 0
                ? 'RUN_SELECTIVE_REPAIR'
                : ((int) ($result['resolvable_rows'] ?? 0) > 0 ? 'RESOLVE_SAFE_FLAGS' : 'NO_DAILY_REBUILD_REQUIRED'),
        ];
    }

    public function repairMonth(
        string $month,
        ?array $requestedOutletIds = null,
        int $limit = 2000,
        ?string $actorUserId = null,
    ): array {
        $this->assertSchema();
        [$fromDate, $toDate] = $this->monthRange($month);
        $outletIds = $this->resolveOutletIds($requestedOutletIds);
        $limit = max(1, min(5000, $limit));

        // I02 resolve is the safety gate: stale/verified flags are cleared first,
        // and only NEEDS_REBUILD remains eligible for forced repair.
        $reconcile = $this->reconciliation->reconcileMonth(
            $month,
            $outletIds,
            true,
            $limit,
            $actorUserId,
        );
        $reconcileRunId = (string) ($reconcile['run_id'] ?? '');

        $targets = DB::table('report_refresh_reconciliation_items')
            ->where('run_id', $reconcileRunId)
            ->where('classification', ReportRefreshReconciliationService::NEEDS_REBUILD)
            ->where('resolved', false)
            ->orderBy('business_date')
            ->orderBy('outlet_id')
            ->limit($limit)
            ->get([
                'id', 'outlet_id', 'business_date', 'snapshot_touch_count',
                'snapshot_last_touched_at', 'audit_json',
            ]);

        $runId = (string) Str::ulid();
        DB::table('report_smart_repair_runs')->insert([
            'id' => $runId,
            'reconciliation_run_id' => $reconcileRunId !== '' ? $reconcileRunId : null,
            'mode' => 'repair',
            'status' => 'running',
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'outlet_count' => count($outletIds),
            'candidate_rows' => $targets->count(),
            'created_by_user_id' => $this->cleanId($actorUserId),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $counts = [
            self::REPAIRED => 0,
            self::FAILED_VERIFY => 0,
            self::FAILED_REFRESH => 0,
            self::RACE_CHANGED => 0,
            self::ALREADY_RESOLVED => 0,
        ];
        $verifiedRows = 0;
        $impactedMonths = [];
        $monthlyResults = [];

        try {
            foreach ($targets as $target) {
                $outletId = (string) $target->outlet_id;
                $businessDate = (string) $target->business_date;
                $snapshotTouch = (int) $target->snapshot_touch_count;
                $snapshotTouchedAt = $target->snapshot_last_touched_at;
                $status = self::FAILED_REFRESH;
                $message = null;
                $dailyRebuilt = false;
                $dailyVerified = false;
                $hourlyRefreshed = false;
                $queueResolved = false;
                $verifyPayload = null;

                $current = DB::table('report_daily_summary_refresh_queue')
                    ->where('outlet_id', $outletId)
                    ->where('business_date', $businessDate)
                    ->first();

                if (! $current) {
                    $status = self::ALREADY_RESOLVED;
                    $message = 'Refresh marker sudah selesai oleh proses lain sebelum Smart Repair berjalan.';
                    $counts[$status]++;
                    $this->persistItem($runId, $outletId, $businessDate, $snapshotTouch, $snapshotTouchedAt, $status, false, false, false, false, $message, null);
                    continue;
                }

                if (! $this->queueMatchesSnapshot($current, $snapshotTouch, $snapshotTouchedAt)) {
                    $status = self::RACE_CHANGED;
                    $message = 'Refresh marker berubah/sementara diproses; repair dilewati agar mutation terbaru tidak hilang.';
                    $counts[$status]++;
                    $this->persistItem($runId, $outletId, $businessDate, $snapshotTouch, $snapshotTouchedAt, $status, false, false, false, false, $message, null);
                    continue;
                }

                $timezone = $this->resolveTimezone($outletId, (string) ($current->business_timezone ?? ''));

                try {
                    // Force only this outlet-date; never the full month.
                    $this->daily->refreshExactCoverage([$outletId], $businessDate, $businessDate, $timezone, [
                        'outlet_chunk' => 1,
                        'date_chunk_days' => 1,
                    ]);
                    $dailyRebuilt = true;

                    $verifyPayload = $this->audit->compareRawToDailyDay([$outletId], $businessDate);
                    $dailyVerified = (bool) ($verifyPayload['match'] ?? false);
                    if (! $dailyVerified) {
                        $status = self::FAILED_VERIFY;
                        $message = 'Force daily selesai tetapi raw-vs-daily masih mismatch; marker dipertahankan.';
                        $this->markQueueErrorIfSnapshotUnchanged($outletId, $businessDate, $snapshotTouch, $snapshotTouchedAt, $message);
                    } else {
                        $verifiedRows++;
                        // Hourly follows repaired daily before queue acknowledgement. If this
                        // fails, the marker remains pending and no false READY is produced.
                        $this->hourly->refreshDate([$outletId], $businessDate);
                        $hourlyRefreshed = true;

                        if ($this->deleteQueueIfSnapshotUnchanged($outletId, $businessDate, $snapshotTouch, $snapshotTouchedAt)) {
                            $queueResolved = true;
                            $status = self::REPAIRED;
                            $message = 'Daily force + raw verify + hourly refresh lulus; marker di-acknowledge secara race-safe.';
                            $monthKey = CarbonImmutable::parse($businessDate)->startOfMonth()->toDateString();
                            $impactedMonths[$monthKey][$outletId] = true;
                        } else {
                            $status = self::RACE_CHANGED;
                            $message = 'Data berubah setelah verify; marker baru dipertahankan untuk pass berikutnya.';
                        }
                    }
                } catch (\Throwable $e) {
                    $status = self::FAILED_REFRESH;
                    $message = substr($e->getMessage(), 0, 680);
                    $this->markQueueErrorIfSnapshotUnchanged($outletId, $businessDate, $snapshotTouch, $snapshotTouchedAt, $message);
                }

                $counts[$status]++;
                $this->persistItem(
                    $runId,
                    $outletId,
                    $businessDate,
                    $snapshotTouch,
                    $snapshotTouchedAt,
                    $status,
                    $dailyRebuilt,
                    $dailyVerified,
                    $hourlyRefreshed,
                    $queueResolved,
                    $message,
                    $verifyPayload,
                );
            }

            // Refresh monthly only after daily markers have safely cleared. The monthly
            // service itself enforces the I01 daily readiness dependency.
            foreach ($impactedMonths as $businessMonth => $outletMap) {
                $monthOutlets = array_values(array_keys($outletMap));
                try {
                    $dailyStatus = $this->readiness->dailyMatrixStatus(
                        $monthOutlets,
                        $businessMonth,
                        CarbonImmutable::parse($businessMonth)->endOfMonth()->toDateString(),
                        TransactionDate::appTimezone(),
                    );
                    if (! (bool) ($dailyStatus['ready'] ?? false)) {
                        $monthlyResults[$businessMonth] = [
                            'refreshed' => false,
                            'reason' => 'daily_not_ready',
                            'daily_readiness_percent' => (float) ($dailyStatus['readiness_percent'] ?? 0),
                            'effective_pending' => (int) ($dailyStatus['effective_pending_refresh_rows'] ?? 0),
                        ];
                        continue;
                    }

                    $refresh = $this->monthly->refreshMonth($monthOutlets, $businessMonth);
                    $monthAudit = $this->audit->auditMonth($monthOutlets, $businessMonth);
                    $monthlyResults[$businessMonth] = [
                        'refreshed' => true,
                        'refresh' => $refresh,
                        'verified' => (bool) ($monthAudit['ready'] ?? false),
                    ];
                } catch (\Throwable $e) {
                    $monthlyResults[$businessMonth] = [
                        'refreshed' => false,
                        'reason' => 'exception',
                        'error' => substr($e->getMessage(), 0, 680),
                    ];
                }
            }

            // Even when there were no NEEDS_REBUILD rows, repair a stale monthly layer
            // when the daily month is already fully ready. This makes I03 rerunnable.
            $finalDaily = $this->readiness->dailyMatrixStatus($outletIds, $fromDate, $toDate, TransactionDate::appTimezone());
            $finalMonthly = $this->monthly->readContractStatus($outletIds, $fromDate);
            if ((bool) ($finalDaily['ready'] ?? false) && ! (bool) ($finalMonthly['ready'] ?? false) && $outletIds !== []) {
                try {
                    $this->monthly->refreshMonth($outletIds, $fromDate);
                    $finalMonthly = $this->monthly->readContractStatus($outletIds, $fromDate);
                    $monthlyResults[$fromDate] = array_merge($monthlyResults[$fromDate] ?? [], [
                        'refreshed' => true,
                        'final_month_repair' => true,
                        'verified' => (bool) ($finalMonthly['ready'] ?? false),
                    ]);
                } catch (\Throwable $e) {
                    $monthlyResults[$fromDate] = array_merge($monthlyResults[$fromDate] ?? [], [
                        'refreshed' => false,
                        'final_month_repair' => true,
                        'error' => substr($e->getMessage(), 0, 680),
                    ]);
                }
            }

            $finalDaily = $this->readiness->dailyMatrixStatus($outletIds, $fromDate, $toDate, TransactionDate::appTimezone());
            $finalMonthly = $this->monthly->readContractStatus($outletIds, $fromDate);
            $finalReadiness = min(
                (float) ($finalDaily['readiness_percent'] ?? 0),
                (float) ($finalMonthly['readiness_percent'] ?? 0),
            );
            $finalReady = (bool) ($finalDaily['ready'] ?? false) && (bool) ($finalMonthly['ready'] ?? false);
            $failedRows = $counts[self::FAILED_VERIFY] + $counts[self::FAILED_REFRESH];
            $runStatus = $finalReady && $failedRows === 0 && $counts[self::RACE_CHANGED] === 0
                ? 'completed'
                : 'completed_with_issues';

            $summary = [
                'contract' => 'erp_pos_v11_i03_smart_repair',
                'run_id' => $runId,
                'reconciliation_run_id' => $reconcileRunId,
                'month' => $month,
                'candidate_rows' => $targets->count(),
                'repaired_rows' => $counts[self::REPAIRED],
                'verified_rows' => $verifiedRows,
                'race_changed_rows' => $counts[self::RACE_CHANGED],
                'failed_rows' => $failedRows,
                'already_resolved_rows' => $counts[self::ALREADY_RESOLVED],
                'monthly' => $monthlyResults,
                'final_daily_readiness_percent' => (float) ($finalDaily['readiness_percent'] ?? 0),
                'final_monthly_readiness_percent' => (float) ($finalMonthly['readiness_percent'] ?? 0),
                'final_readiness_percent' => $finalReadiness,
                'final_ready' => $finalReady,
                'effective_pending_refresh_rows' => (int) ($finalDaily['effective_pending_refresh_rows'] ?? 0),
                'raw_pending_refresh_rows' => (int) ($finalDaily['raw_pending_refresh_rows'] ?? 0),
                'next_action' => $finalReady
                    ? 'READY'
                    : ($failedRows > 0 ? 'REVIEW_FAILED_ITEMS' : ($counts[self::RACE_CHANGED] > 0 ? 'RECONCILE_AGAIN' : 'CHECK_REMAINING_READINESS_ISSUES')),
            ];

            DB::table('report_smart_repair_runs')->where('id', $runId)->update([
                'status' => $runStatus,
                'repaired_rows' => $counts[self::REPAIRED],
                'verified_rows' => $verifiedRows,
                'race_changed_rows' => $counts[self::RACE_CHANGED],
                'failed_rows' => $failedRows,
                'already_resolved_rows' => $counts[self::ALREADY_RESOLVED],
                'monthly_refreshed' => collect($monthlyResults)->filter(fn ($row) => (bool) ($row['refreshed'] ?? false))->count(),
                'final_readiness_percent' => $finalReadiness,
                'final_ready' => $finalReady,
                'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            if ($counts[self::REPAIRED] > 0) {
                AnalyticsResponseCache::bumpVersion('v11-i03-smart-repair:' . $counts[self::REPAIRED]);
            }

            return $summary;
        } catch (\Throwable $e) {
            DB::table('report_smart_repair_runs')->where('id', $runId)->update([
                'status' => 'failed',
                'summary_json' => json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            throw $e;
        }
    }

    public function runDetail(string $runId, int $itemLimit = 1000): array
    {
        $this->assertSchema();
        $run = DB::table('report_smart_repair_runs')->where('id', $runId)->first();
        if (! $run) {
            throw new \InvalidArgumentException('Smart Repair run tidak ditemukan.');
        }

        $items = DB::table('report_smart_repair_items')
            ->where('run_id', $runId)
            ->orderBy('business_date')
            ->orderBy('outlet_id')
            ->limit(max(1, min(5000, $itemLimit)))
            ->get()
            ->map(fn ($row) => [
                'outlet_id' => (string) $row->outlet_id,
                'business_date' => (string) $row->business_date,
                'status' => (string) $row->status,
                'daily_rebuilt' => (bool) $row->daily_rebuilt,
                'daily_verified' => (bool) $row->daily_verified,
                'hourly_refreshed' => (bool) $row->hourly_refreshed,
                'queue_resolved' => (bool) $row->queue_resolved,
                'message' => $row->message,
            ])->all();

        return ['run' => (array) $run, 'items' => $items];
    }

    private function persistItem(
        string $runId,
        string $outletId,
        string $businessDate,
        int $snapshotTouch,
        mixed $snapshotTouchedAt,
        string $status,
        bool $dailyRebuilt,
        bool $dailyVerified,
        bool $hourlyRefreshed,
        bool $queueResolved,
        ?string $message,
        ?array $verifyPayload,
    ): void {
        DB::table('report_smart_repair_items')->insert([
            'id' => (string) Str::ulid(),
            'run_id' => $runId,
            'outlet_id' => $outletId,
            'business_date' => $businessDate,
            'status' => $status,
            'snapshot_touch_count' => $snapshotTouch,
            'snapshot_last_touched_at' => $snapshotTouchedAt,
            'daily_rebuilt' => $dailyRebuilt,
            'daily_verified' => $dailyVerified,
            'hourly_refreshed' => $hourlyRefreshed,
            'queue_resolved' => $queueResolved,
            'message' => $message,
            'verify_json' => $verifyPayload ? json_encode($verifyPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function queueMatchesSnapshot(object $row, int $touchCount, mixed $lastTouchedAt): bool
    {
        return (string) ($row->status ?? '') === 'pending'
            && (int) ($row->touch_count ?? 0) === $touchCount
            && $this->sameTimestamp($row->last_touched_at ?? null, $lastTouchedAt);
    }

    private function deleteQueueIfSnapshotUnchanged(string $outletId, string $businessDate, int $touchCount, mixed $lastTouchedAt): bool
    {
        $query = DB::table('report_daily_summary_refresh_queue')
            ->where('outlet_id', $outletId)
            ->where('business_date', $businessDate)
            ->where('status', 'pending')
            ->where('touch_count', $touchCount);
        $this->applyTimestampPredicate($query, 'last_touched_at', $lastTouchedAt);
        return $query->delete() === 1;
    }

    private function markQueueErrorIfSnapshotUnchanged(string $outletId, string $businessDate, int $touchCount, mixed $lastTouchedAt, string $message): void
    {
        $query = DB::table('report_daily_summary_refresh_queue')
            ->where('outlet_id', $outletId)
            ->where('business_date', $businessDate)
            ->where('status', 'pending')
            ->where('touch_count', $touchCount);
        $this->applyTimestampPredicate($query, 'last_touched_at', $lastTouchedAt);
        $query->update([
            'last_error' => substr($message, 0, 1000),
            'last_attempted_at' => now(),
            'attempt_count' => DB::raw('attempt_count + 1'),
            'updated_at' => now(),
        ]);
    }

    private function applyTimestampPredicate($query, string $column, mixed $value): void
    {
        if ($value === null || trim((string) $value) === '') {
            $query->whereNull($column);
        } else {
            $query->where($column, $value);
        }
    }

    private function sameTimestamp(mixed $left, mixed $right): bool
    {
        $left = trim((string) ($left ?? ''));
        $right = trim((string) ($right ?? ''));
        if ($left === '' || $right === '') {
            return $left === $right;
        }
        try {
            return CarbonImmutable::parse($left)->equalTo(CarbonImmutable::parse($right));
        } catch (\Throwable) {
            return $left === $right;
        }
    }

    private function resolveTimezone(string $outletId, string $queueTimezone): string
    {
        $fallback = (string) (DB::table('outlets')->where('id', $outletId)->value('timezone') ?? TransactionDate::appTimezone());
        return TransactionDate::normalizeTimezone($queueTimezone, $fallback);
    }

    private function monthRange(string $month): array
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new \InvalidArgumentException('Format bulan harus YYYY-MM.');
        }
        $timezone = TransactionDate::appTimezone();
        $start = CarbonImmutable::parse($month . '-01', $timezone)->startOfMonth();
        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }

    private function resolveOutletIds(?array $requested): array
    {
        $all = DB::table('outlets')
            ->whereRaw("LOWER(COALESCE(type,'outlet'))='outlet'")
            ->pluck('id')
            ->map(fn ($value) => (string) $value)
            ->filter()
            ->values()
            ->all();
        if (! is_array($requested) || $requested === []) {
            return $all;
        }
        $allow = array_flip($all);
        return array_values(array_filter(array_unique(array_map('strval', $requested)), fn ($id) => isset($allow[$id])));
    }

    private function cleanId(?string $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : substr($value, 0, 26);
    }

    private function assertSchema(): void
    {
        foreach ([
            'report_daily_summary_refresh_queue',
            'report_refresh_reconciliation_runs',
            'report_refresh_reconciliation_items',
            'report_smart_repair_runs',
            'report_smart_repair_items',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new \RuntimeException("Tabel {$table} belum tersedia. Jalankan migration V11 I02/I03.");
            }
        }
    }
}
