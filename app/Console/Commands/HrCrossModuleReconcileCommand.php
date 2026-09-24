<?php

namespace App\Console\Commands;

use App\Services\HumanResource\HrCrossModuleReconciliationService;
use Illuminate\Console\Command;

class HrCrossModuleReconcileCommand extends Command
{
    protected $signature = 'hr:cross-module-reconcile {--sample=20 : Maksimal sample per finding} {--json= : Simpan report JSON ke path} {--strict : Return non-zero bila ada critical finding}';
    protected $description = 'Dry-run reconciliation HR lintas Squad/User/Assignment/Contract/Attendance/Punishment/KPI/Payroll/Finance.';

    public function handle(HrCrossModuleReconciliationService $service): int
    {
        $report = $service->run((int)$this->option('sample'));
        $this->info('HR Cross-Module Reconciliation — DRY RUN');
        $this->line('Generated: '.$report['generated_at']);
        $rows=[];
        foreach($report['issues'] as$issue){
            $rows[]=[strtoupper($issue['severity']),$issue['code'],$issue['count'],$issue['message']];
        }
        $this->table(['Severity','Check','Count','Description'],$rows);
        $this->line('Critical findings: '.(int)$report['counts']['critical']);
        $this->line('Warning findings : '.(int)$report['counts']['warning']);

        if($path=trim((string)$this->option('json'))){
            $absolute=str_starts_with($path,'/')?$path:base_path($path);
            @mkdir(dirname($absolute),0775,true);
            file_put_contents($absolute,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $this->info('JSON report: '.$absolute);
        }

        return $this->option('strict') && (int)$report['counts']['critical']>0 ? self::FAILURE : self::SUCCESS;
    }
}
