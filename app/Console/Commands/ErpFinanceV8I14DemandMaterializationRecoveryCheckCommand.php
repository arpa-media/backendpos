<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpFinanceV8I14DemandMaterializationRecoveryCheckCommand extends Command
{
    protected $signature = 'erp-finance-v8:i14-demand-recovery-check';
    protected $description = 'Validate V8 I14 demand materialization recovery and lightweight Control Center status.';

    public function handle(): int
    {
        $checks = [
            'recovery_request_table' => Schema::hasTable('report_materialization_recovery_requests'),
            'chunk_priority_column' => Schema::hasTable('report_materialization_run_chunks') && Schema::hasColumn('report_materialization_run_chunks', 'priority'),
            'recovery_route' => Route::has('reporting.materialization.recovery.i14'),
            'recovery_status_route' => Route::has('reporting.materialization.recovery-status.i14'),
            'control_center_status_route' => Route::has('console.control-center.status.i14'),
        ];

        $ok = true;
        foreach ($checks as $label => $passed) {
            $this->line(($passed ? '<info>[PASS]</info> ' : '<error>[FAIL]</error> ').$label);
            $ok = $ok && $passed;
        }

        if (Schema::hasTable('report_materialization_recovery_requests')) {
            $this->line('Recovery queued   : '.DB::table('report_materialization_recovery_requests')->where('status', 'queued')->count());
            $this->line('Recovery attached : '.DB::table('report_materialization_recovery_requests')->where('status', 'attached')->count());
        }

        if (Schema::hasTable('report_materialization_run_chunks')) {
            $this->line('In-flight chunks  : '.DB::table('report_materialization_run_chunks')->whereIn('status', ['dispatched', 'running'])->count());
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
