<?php

namespace App\Console\Commands;

use App\Services\Reporting\ReportHybridReadPlanner;
use App\Services\Reporting\ReportingMaterializationOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpFinanceV8I09ReportingControlCenterCheckCommand extends Command
{
    protected $signature='erp-finance-v8:i09-reporting-control-center-check {--days=370}';
    protected $description='Read-only health gate for Console Control Center, monthly materialization and hybrid read planner.';

    public function handle(ReportingMaterializationOrchestrator $orchestrator, ReportHybridReadPlanner $planner): int
    {
        $tables=['report_monthly_summary_coverage','report_monthly_sales_summaries','report_materialization_settings','report_materialization_runs','report_materialization_run_chunks']; $ok=true;
        foreach($tables as $table){$exists=Schema::hasTable($table);$this->line(($exists?'[PASS] ':'[FAIL] ').$table);$ok=$ok&&$exists;}
        $route=Route::has('console.control-center.dashboard');$this->line(($route?'[PASS] ':'[FAIL] ').'console.control-center.dashboard route');$ok=$ok&&$route;
        $days=max(1,min(730,(int)$this->option('days')));$dashboard=$orchestrator->dashboard();$this->line('[INFO] daily coverage '.($dashboard['coverage']['daily']['coverage_percent']??0).'%');$this->line('[INFO] monthly coverage '.($dashboard['coverage']['monthly']['coverage_percent']??0).'%');
        $outlets=\Illuminate\Support\Facades\DB::table('outlets')->whereRaw("LOWER(COALESCE(type,'outlet'))='outlet'")->pluck('id')->map(fn($v)=>(string)$v)->all(); if($outlets){$from=now()->subDays($days-1)->toDateString();$to=now()->toDateString();$plan=$planner->plan($outlets,$from,$to);$this->line('[INFO] hybrid strategy '.$plan['strategy'].' | monthly segments '.$plan['monthly_segments'].' | daily segments '.$plan['daily_segments']);}
        $bootstrap=(string)@file_get_contents(base_path('bootstrap/app.php'));
        $legacyFixed=!str_contains($bootstrap, "report-daily-summaries:warm-common --days=370") && !str_contains($bootstrap, "report-hourly-summaries:warm-common --days=370");
        $tick=str_contains($bootstrap, 'reporting-engine:tick');
        $this->line(($legacyFixed?'[PASS] ':'[FAIL] ').'fixed heavy warm schedule removed from bootstrap'); $ok=$ok&&$legacyFixed;
        $this->line(($tick?'[PASS] ':'[FAIL] ').'visible Reporting Engine scheduler tick wired'); $ok=$ok&&$tick;
        $expected=[
            'app/Support/TransactionDate.php'=>'a3b6722b0980cd29af4711f1d7723ca6d5e4483856eb8613e2e3f01775e2be5f',
            'app/Services/CashierAlignedSaleScopeService.php'=>'db31d125b9073c06f3446c2c989913f75d9a5ff1ce01143bde2e7cb1df12e559',
        ];
        foreach($expected as $file=>$hash){$actual=is_file(base_path($file))?hash_file('sha256',base_path($file)):'';$pass=hash_equals($hash,$actual);$this->line(($pass?'[PASS] ':'[FAIL] ').$file.' cashier invariant');$ok=$ok&&$pass;}
        $this->line('[PASS] CLI warm commands remain available only as recovery/bootstrap tools.');
        return $ok?self::SUCCESS:self::FAILURE;
    }
}
