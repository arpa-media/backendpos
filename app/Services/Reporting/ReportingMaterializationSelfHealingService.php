<?php

namespace App\Services\Reporting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * V11 I06 runtime recovery that is deliberately non-destructive.
 *
 * It never clears a valid pending marker and never force-rebuilds report data.
 * It only releases refresh rows that were left in `processing` beyond a safe
 * lease window, then nudges the persisted materialization run so the existing
 * orchestrator can reclaim expired chunk leases and continue normally.
 */
class ReportingMaterializationSelfHealingService
{
    public function __construct(
        private readonly ReportingMaterializationOrchestrator $orchestrator,
    ) {
    }

    public function heal(bool $kickActiveRun = true): array
    {
        $releasedRefreshRows = $this->releaseStaleRefreshProcessing();
        $kick = null;

        if ($kickActiveRun && Schema::hasTable('report_materialization_runs')) {
            try {
                // I06 patches kickRun() so this also reclaims expired chunk leases.
                $kick = $this->orchestrator->kickActiveRun(1);
            } catch (\Throwable $e) {
                $kick = [
                    'state' => 'recovery_kick_failed',
                    'error' => mb_substr($e->getMessage(), 0, 1000),
                ];
            }
        }

        return [
            'released_stale_refresh_rows' => $releasedRefreshRows,
            'active_run_kick' => $kick,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * A daily refresh claim has no dedicated lease column. Recover only rows
     * older than the reporting worker lease plus a safety margin. A normal
     * mutation that re-touches the same outlet/date changes status back to
     * pending, so it is never matched by this recovery query.
     */
    public function releaseStaleRefreshProcessing(): int
    {
        if (! Schema::hasTable('report_daily_summary_refresh_queue')) {
            return 0;
        }

        $leaseSeconds = 3600;
        if (Schema::hasTable('report_materialization_settings')) {
            $configured = (int) (DB::table('report_materialization_settings')
                ->where('id', 'default')
                ->value('worker_lease_seconds') ?? 3600);
            $leaseSeconds = max(1800, min(14400, $configured));
        }

        $cutoff = now()->subSeconds($leaseSeconds + 600);
        $rows = DB::table('report_daily_summary_refresh_queue')
            ->where('status', 'processing')
            ->where(function ($q) use ($cutoff): void {
                $q->where(function ($q2) use ($cutoff): void {
                    $q2->whereNotNull('last_attempted_at')->where('last_attempted_at', '<', $cutoff);
                })->orWhere(function ($q2) use ($cutoff): void {
                    $q2->whereNull('last_attempted_at')->where('updated_at', '<', $cutoff);
                });
            })
            ->orderBy('business_date')
            ->limit(500)
            ->get(['outlet_id', 'business_date', 'touch_count', 'last_touched_at', 'last_error']);

        $released = 0;
        foreach ($rows as $row) {
            $query = DB::table('report_daily_summary_refresh_queue')
                ->where('outlet_id', (string) $row->outlet_id)
                ->where('business_date', (string) $row->business_date)
                ->where('status', 'processing')
                ->where('touch_count', (int) ($row->touch_count ?? 0));

            if (($row->last_touched_at ?? null) === null) {
                $query->whereNull('last_touched_at');
            } else {
                $query->where('last_touched_at', $row->last_touched_at);
            }

            $previous = trim((string) ($row->last_error ?? ''));
            $note = 'V11 I06 recovered stale processing claim at '.now()->toDateTimeString();
            $error = mb_substr($previous === '' ? $note : $previous."\n".$note, 0, 1000);

            $released += $query->update([
                'status' => 'pending',
                'last_error' => $error,
                'updated_at' => now(),
            ]);
        }

        return $released;
    }

    public function diagnostics(): array
    {
        $settings = $this->orchestrator->settings();
        $leaseSeconds = max(1800, min(14400, (int) ($settings['worker_lease_seconds'] ?? 3600)));
        $refreshCutoff = now()->subSeconds($leaseSeconds + 600);

        $staleRefreshProcessing = Schema::hasTable('report_daily_summary_refresh_queue')
            ? DB::table('report_daily_summary_refresh_queue')
                ->where('status', 'processing')
                ->where(function ($q) use ($refreshCutoff): void {
                    $q->where(function ($q2) use ($refreshCutoff): void {
                        $q2->whereNotNull('last_attempted_at')->where('last_attempted_at', '<', $refreshCutoff);
                    })->orWhere(function ($q2) use ($refreshCutoff): void {
                        $q2->whereNull('last_attempted_at')->where('updated_at', '<', $refreshCutoff);
                    });
                })->count()
            : null;

        $staleChunkLeases = Schema::hasTable('report_materialization_run_chunks')
            && Schema::hasColumn('report_materialization_run_chunks', 'lease_expires_at')
            ? DB::table('report_materialization_run_chunks')
                ->whereIn('status', ['running', 'dispatched'])
                ->whereNotNull('lease_expires_at')
                ->where('lease_expires_at', '<', now())
                ->count()
            : null;

        $activeRuns = Schema::hasTable('report_materialization_runs')
            ? DB::table('report_materialization_runs')
                ->whereIn('status', ['queued', 'running', 'pause_requested', 'paused', 'cancel_requested', 'waiting_window'])
                ->count()
            : null;

        return [
            'stale_refresh_processing' => $staleRefreshProcessing,
            'stale_chunk_leases' => $staleChunkLeases,
            'active_runs' => $activeRuns,
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
