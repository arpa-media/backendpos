<?php

namespace App\Jobs\Reporting;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportingWorkerHeartbeatJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;
    public int $uniqueFor = 86400;

    public function __construct()
    {
        $this->onConnection('reporting');
        $this->onQueue('reporting');
    }

    public function uniqueId(): string
    {
        return 'erp-finance-v8-reporting-worker-heartbeat';
    }

    public function handle(): void
    {
        if (!Schema::hasTable('report_materialization_settings')) return;
        DB::table('report_materialization_settings')->where('id', 'default')->update([
            'last_worker_heartbeat_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
