<?php

namespace App\Services\Reporting;

use App\Support\AnalyticsResponseCache;
use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * V11 I02 reconciliation layer for report_daily_summary_refresh_queue.
 *
 * It never rebuilds materialized data. It only classifies refresh markers and,
 * in resolve mode, safely acknowledges markers that are provably obsolete or
 * already represented by a matching raw-vs-daily snapshot.
 */
class ReportRefreshReconciliationService
{
    public const STALE_FLAG = 'STALE_FLAG';
    public const VERIFIED_OK = 'VERIFIED_OK';
    public const NEEDS_REBUILD = 'NEEDS_REBUILD';
    public const RACE_CHANGED = 'RACE_CHANGED';

    public function __construct(
        private readonly ReportCorrectnessAuditService $correctnessAudit,
    ) {
    }

    public function reconcileMonth(
        string $month,
        ?array $requestedOutletIds = null,
        bool $resolve = false,
        int $limit = 2000,
        ?string $actorUserId = null,
    ): array {
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new \InvalidArgumentException('Format bulan harus YYYY-MM.');
        }

        $timezone = TransactionDate::appTimezone();
        $start = CarbonImmutable::parse($month . '-01', $timezone)->startOfMonth();
        $end = $start->endOfMonth();

        return $this->reconcileRange(
            $start->toDateString(),
            $end->toDateString(),
            $requestedOutletIds,
            $resolve,
            $limit,
            $actorUserId,
        );
    }

    public function reconcileRange(
        string $fromDate,
        string $toDate,
        ?array $requestedOutletIds = null,
        bool $resolve = false,
        int $limit = 2000,
        ?string $actorUserId = null,
    ): array {
        if (! Schema::hasTable('report_daily_summary_refresh_queue')) {
            throw new \RuntimeException('Tabel refresh queue belum tersedia.');
        }
        if (! Schema::hasTable('report_daily_summary_coverage')) {
            throw new \RuntimeException('Tabel daily coverage belum tersedia.');
        }
        if (! Schema::hasTable('report_refresh_reconciliation_runs') || ! Schema::hasTable('report_refresh_reconciliation_items')) {
            throw new \RuntimeException('Migration V11 I02 belum dijalankan.');
        }

        $timezone = TransactionDate::appTimezone();
        [$fromDate, $toDate] = $this->normalizeRange($fromDate, $toDate, $timezone);
        $outletIds = $this->resolveOutletIds($requestedOutletIds);
        $limit = max(1, min(5000, $limit));
        $runId = (string) Str::ulid();
        $mode = $resolve ? 'resolve' : 'preview';

        $query = DB::table('report_daily_summary_refresh_queue')
            ->whereBetween('business_date', [$fromDate, $toDate])
            ->whereIn('status', ['pending', 'processing'])
            ->orderBy('business_date')
            ->orderBy('outlet_id')
            ->limit($limit);

        if ($outletIds !== []) {
            $query->whereIn('outlet_id', $outletIds);
        } else {
            $query->whereRaw('1 = 0');
        }

        $queueRows = $query->get([
            'outlet_id',
            'business_date',
            'business_timezone',
            'reason',
            'status',
            'touch_count',
            'queued_at',
            'last_touched_at',
            'last_attempted_at',
            'attempt_count',
            'updated_at',
        ]);

        DB::table('report_refresh_reconciliation_runs')->insert([
            'id' => $runId,
            'mode' => $mode,
            'status' => 'running',
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'outlet_count' => count($outletIds),
            'candidate_rows' => $queueRows->count(),
            'created_by_user_id' => $this->cleanId($actorUserId),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $coverageByKey = $this->coverageByKey($queueRows->all());
        $outletTimezoneById = DB::table('outlets')
            ->whereIn('id', $outletIds)
            ->pluck('timezone', 'id')
            ->mapWithKeys(fn ($value, $key) => [(string) $key => (string) ($value ?? '')])
            ->all();
        $counts = [
            self::STALE_FLAG => 0,
            self::VERIFIED_OK => 0,
            self::NEEDS_REBUILD => 0,
            self::RACE_CHANGED => 0,
        ];
        $resolvedRows = 0;

        try {
            $decisions = [];
            $effectiveByDate = [];

            // Phase 1: classify rows that do not require raw-vs-summary verification.
            foreach ($queueRows as $row) {
                $snapshot = $this->snapshot($row, $outletTimezoneById);
                $key = $this->pairKey($snapshot['outlet_id'], $snapshot['business_date']);
                $coverage = $coverageByKey[$key] ?? null;
                $classification = self::NEEDS_REBUILD;
                $message = null;

                if ($snapshot['status'] !== 'pending') {
                    $classification = self::RACE_CHANGED;
                    $message = 'Marker sedang diproses worker lain; tidak disentuh oleh reconciliation.';
                } elseif (! $coverage || trim((string) ($coverage->synced_at ?? '')) === '') {
                    $classification = self::NEEDS_REBUILD;
                    $message = 'Daily coverage belum tersedia.';
                } elseif ($this->coverageWasPremature($coverage->synced_at, $snapshot['business_date'], $snapshot['timezone'])) {
                    $classification = self::NEEDS_REBUILD;
                    $message = 'Coverage dibuat sebelum business date valid selesai dimulai.';
                } elseif (! $this->refreshMarkerIsEffective($snapshot['last_touched_at'], $coverage->synced_at)) {
                    $classification = self::STALE_FLAG;
                    $message = 'Coverage lebih baru atau sama dengan marker refresh.';
                } else {
                    // Defer effective markers and verify all outlets of the same date
                    // in one batched correctness audit.
                    $classification = null;
                    $effectiveByDate[$snapshot['business_date']][] = $snapshot['outlet_id'];
                }

                $decisions[$key] = [
                    'snapshot' => $snapshot,
                    'coverage' => $coverage,
                    'classification' => $classification,
                    'message' => $message,
                    'audit' => null,
                ];
            }

            // Phase 2: full correctness proof, batched per business date.
            foreach ($effectiveByDate as $businessDate => $candidateOutletIds) {
                $candidateOutletIds = array_values(array_unique($candidateOutletIds));
                $auditBatch = $this->correctnessAudit->compareRawToDailyDayByOutlet($candidateOutletIds, $businessDate);
                foreach ($candidateOutletIds as $outletId) {
                    $key = $this->pairKey($outletId, $businessDate);
                    $outletAudit = $auditBatch['outlets'][$outletId] ?? ['match' => false, 'families' => []];
                    $match = (bool) ($outletAudit['match'] ?? false);
                    $decisions[$key]['classification'] = $match ? self::VERIFIED_OK : self::NEEDS_REBUILD;
                    $decisions[$key]['message'] = $match
                        ? 'Raw source dan seluruh daily summary family cocok.'
                        : 'Raw source berbeda dengan daily materialized summary.';
                    $decisions[$key]['audit'] = [
                        'match' => $match,
                        'business_date' => $businessDate,
                        'outlet_id' => $outletId,
                        'families' => $outletAudit['families'] ?? [],
                    ];
                }
            }

            // Phase 3: optional race-safe acknowledgement + audit persistence.
            foreach ($decisions as $decision) {
                $snapshot = $decision['snapshot'];
                $coverage = $decision['coverage'];
                $classification = (string) ($decision['classification'] ?? self::NEEDS_REBUILD);
                $message = $decision['message'];
                $audit = $decision['audit'];
                $resolved = false;

                if ($resolve && in_array($classification, [self::STALE_FLAG, self::VERIFIED_OK], true)) {
                    $deleted = $this->deleteSnapshotIfUnchanged($snapshot);
                    if ($deleted) {
                        $resolved = true;
                        $resolvedRows++;
                    } else {
                        $classification = self::RACE_CHANGED;
                        $message = 'Marker berubah saat validasi; acknowledgement dibatalkan agar mutation baru tidak hilang.';
                    }
                }

                $counts[$classification]++;
                DB::table('report_refresh_reconciliation_items')->insert([
                    'id' => (string) Str::ulid(),
                    'run_id' => $runId,
                    'outlet_id' => $snapshot['outlet_id'],
                    'business_date' => $snapshot['business_date'],
                    'queue_status' => $snapshot['status'],
                    'snapshot_touch_count' => $snapshot['touch_count'],
                    'snapshot_last_touched_at' => $snapshot['last_touched_at'],
                    'coverage_synced_at' => $coverage->synced_at ?? null,
                    'classification' => $classification,
                    'resolved' => $resolved,
                    'message' => $message,
                    'audit_json' => $audit ? json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $summary = $this->buildSummary($runId, $mode, $fromDate, $toDate, $queueRows->count(), $counts, $resolvedRows);
            DB::table('report_refresh_reconciliation_runs')->where('id', $runId)->update([
                'status' => 'completed',
                'stale_flag_rows' => $counts[self::STALE_FLAG],
                'verified_ok_rows' => $counts[self::VERIFIED_OK],
                'needs_rebuild_rows' => $counts[self::NEEDS_REBUILD],
                'race_changed_rows' => $counts[self::RACE_CHANGED],
                'resolved_rows' => $resolvedRows,
                'unresolved_rows' => max(0, $queueRows->count() - $resolvedRows),
                'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            if ($resolvedRows > 0) {
                AnalyticsResponseCache::bumpVersion('v11-i02-refresh-reconcile:' . $resolvedRows);
            }

            return $summary;
        } catch (\Throwable $e) {
            DB::table('report_refresh_reconciliation_runs')->where('id', $runId)->update([
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
        if (! Schema::hasTable('report_refresh_reconciliation_runs')) {
            throw new \RuntimeException('Migration V11 I02 belum dijalankan.');
        }

        $run = DB::table('report_refresh_reconciliation_runs')->where('id', $runId)->first();
        if (! $run) {
            throw new \InvalidArgumentException('Reconciliation run tidak ditemukan.');
        }

        $items = DB::table('report_refresh_reconciliation_items')
            ->where('run_id', $runId)
            ->orderBy('business_date')
            ->orderBy('outlet_id')
            ->limit(max(1, min(5000, $itemLimit)))
            ->get()
            ->map(fn ($row) => [
                'outlet_id' => (string) $row->outlet_id,
                'business_date' => (string) $row->business_date,
                'classification' => (string) $row->classification,
                'resolved' => (bool) $row->resolved,
                'message' => $row->message,
                'coverage_synced_at' => $row->coverage_synced_at,
                'snapshot_last_touched_at' => $row->snapshot_last_touched_at,
                'snapshot_touch_count' => (int) $row->snapshot_touch_count,
            ])->all();

        return [
            'run' => (array) $run,
            'items' => $items,
        ];
    }

    private function buildSummary(string $runId, string $mode, string $fromDate, string $toDate, int $candidateRows, array $counts, int $resolvedRows): array
    {
        return [
            'contract' => 'erp_pos_v11_i02_refresh_reconciliation',
            'run_id' => $runId,
            'mode' => $mode,
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'candidate_rows' => $candidateRows,
            'stale_flag_rows' => $counts[self::STALE_FLAG],
            'verified_ok_rows' => $counts[self::VERIFIED_OK],
            'needs_rebuild_rows' => $counts[self::NEEDS_REBUILD],
            'race_changed_rows' => $counts[self::RACE_CHANGED],
            'resolvable_rows' => $counts[self::STALE_FLAG] + $counts[self::VERIFIED_OK],
            'resolved_rows' => $resolvedRows,
            'remaining_candidate_rows' => max(0, $candidateRows - $resolvedRows),
            'next_action' => $counts[self::NEEDS_REBUILD] > 0
                ? 'NEEDS_SMART_REPAIR_I03'
                : ($counts[self::RACE_CHANGED] > 0 ? 'RECHECK_CHANGED_ROWS' : 'NO_REBUILD_REQUIRED'),
        ];
    }

    private function coverageByKey(array $queueRows): array
    {
        $outletIds = [];
        $dates = [];
        foreach ($queueRows as $row) {
            $outletIds[] = (string) ($row->outlet_id ?? '');
            $dates[] = (string) ($row->business_date ?? '');
        }
        $outletIds = array_values(array_unique(array_filter($outletIds)));
        $dates = array_values(array_unique(array_filter($dates)));
        if ($outletIds === [] || $dates === []) {
            return [];
        }

        $rows = DB::table('report_daily_summary_coverage')
            ->whereIn('outlet_id', $outletIds)
            ->whereBetween('business_date', [min($dates), max($dates)])
            ->get(['outlet_id', 'business_date', 'synced_at']);

        $map = [];
        foreach ($rows as $row) {
            $map[$this->pairKey((string) $row->outlet_id, (string) $row->business_date)] = $row;
        }
        return $map;
    }

    private function snapshot(object $row, array $outletTimezoneById = []): array
    {
        $outletId = (string) ($row->outlet_id ?? '');
        $fallbackTimezone = (string) ($outletTimezoneById[$outletId] ?? TransactionDate::appTimezone());
        return [
            'outlet_id' => $outletId,
            'business_date' => (string) ($row->business_date ?? ''),
            'timezone' => TransactionDate::normalizeTimezone((string) ($row->business_timezone ?? ''), $fallbackTimezone),
            'status' => (string) ($row->status ?? ''),
            'touch_count' => (int) ($row->touch_count ?? 0),
            'last_touched_at' => $row->last_touched_at ?? null,
        ];
    }

    private function deleteSnapshotIfUnchanged(array $snapshot): bool
    {
        $query = DB::table('report_daily_summary_refresh_queue')
            ->where('outlet_id', $snapshot['outlet_id'])
            ->where('business_date', $snapshot['business_date'])
            ->where('status', $snapshot['status'])
            ->where('touch_count', $snapshot['touch_count']);

        if ($snapshot['last_touched_at'] === null) {
            $query->whereNull('last_touched_at');
        } else {
            $query->where('last_touched_at', $snapshot['last_touched_at']);
        }

        return $query->delete() === 1;
    }

    private function refreshMarkerIsEffective(mixed $markerAt, mixed $coverageAt): bool
    {
        $marker = trim((string) ($markerAt ?? ''));
        $coverage = trim((string) ($coverageAt ?? ''));
        if ($marker === '' || $coverage === '') {
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
        return array_values(array_filter(
            array_unique(array_map('strval', $requested)),
            fn ($id) => isset($allow[$id])
        ));
    }

    private function normalizeRange(string $fromDate, string $toDate, string $timezone): array
    {
        $from = CarbonImmutable::parse($fromDate, $timezone)->toDateString();
        $to = CarbonImmutable::parse($toDate, $timezone)->toDateString();
        return $to < $from ? [$to, $from] : [$from, $to];
    }

    private function pairKey(string $outletId, string $date): string
    {
        return $outletId . '#' . $date;
    }

    private function cleanId(?string $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : substr($value, 0, 26);
    }
}
