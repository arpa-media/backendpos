<?php

namespace App\Console\Commands;

use App\Services\Reporting\ReportSmartRepairService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ErpPosV11I03SmartRepairCheckCommand extends Command
{
    protected $signature = 'erp-pos-v11:i03-check';
    protected $description = 'Static/schema check untuk ERP POS V11 I03 Smart Repair.';

    public function handle(): int
    {
        $checks = [
            'I02 reconciliation run table' => Schema::hasTable('report_refresh_reconciliation_runs'),
            'I02 reconciliation item table' => Schema::hasTable('report_refresh_reconciliation_items'),
            'I03 smart repair run table' => Schema::hasTable('report_smart_repair_runs'),
            'I03 smart repair item table' => Schema::hasTable('report_smart_repair_items'),
            'Smart Repair plan method' => method_exists(ReportSmartRepairService::class, 'planMonth'),
            'Smart Repair execute method' => method_exists(ReportSmartRepairService::class, 'repairMonth'),
            'Smart Repair detail method' => method_exists(ReportSmartRepairService::class, 'runDetail'),
        ];

        $rows = [];
        $failed = 0;
        foreach ($checks as $label => $ok) {
            $rows[] = [$label, $ok ? 'PASS' : 'FAIL'];
            if (! $ok) {
                $failed++;
            }
        }
        $this->table(['Check', 'Result'], $rows);

        if ($failed > 0) {
            $this->error("V11 I03 CHECK: FAIL ({$failed})");
            return self::FAILURE;
        }

        $this->info('V11 I03 CHECK: PASS');
        return self::SUCCESS;
    }
}
