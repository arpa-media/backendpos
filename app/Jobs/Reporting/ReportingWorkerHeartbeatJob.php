<?php

namespace App\Jobs\Reporting;

use App\Services\Reporting\ReportingMaterializationSelfHealingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting worker liveness pulse.
 *
 * I04 made the pulse self-renew from the reporting queue. I06 also uses the
 * pulse as a lightweight recovery boundary: stale refresh claims are released
 * safely and an active persisted run is nudged so expired chunk leases can be
 * reclaimed without waiting for schedule:run.
 */
class ReportingWorkerHeartbeatJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;
    public int $uniqueFor = 180;

    public function __construct()
    {
        $this->onConnection('reporting');
        $this->onQueue('reporting');
    }

    public function uniqueId(): string
    {
        return 'erp-pos-v11-reporting-worker-liveness-pulse';
    }

    public function handle(ReportingMaterializationSelfHealingService $selfHealing): void
    {
        if (! Schema::hasTable('report_materialization_settings')) return;

        DB::table('report_materialization_settings')->where('id', 'default')->update([
            'last_worker_heartbeat_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $selfHealing->heal(true);
        } catch (\Throwable) {
            // Heartbeat must stay alive even if recovery encounters a transient
            // database/queue issue. The next pulse will retry safely.
        }

        // ShouldBeUniqueUntilProcessing releases its lock immediately before
        // handle(), allowing the next delayed liveness pulse to be queued.
        self::dispatch()->delay(now()->addSeconds(60));
    }
}
