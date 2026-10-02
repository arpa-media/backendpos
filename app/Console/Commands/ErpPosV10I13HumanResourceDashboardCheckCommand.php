<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\HumanResource\HrDashboardController;
use App\Services\HumanResource\HrDashboardI13Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpPosV10I13HumanResourceDashboardCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i13-human-resource-dashboard-check';
    protected $description = 'Verify ERP POS V10 I13 Human Resource dashboard KPI expansion';

    public function handle(): int
    {
        $checks = [
            'HR dashboard controller' => class_exists(HrDashboardController::class),
            'I13 aggregate service' => class_exists(HrDashboardI13Service::class),
            'HR dashboard route' => collect(Route::getRoutes())->contains(fn ($route) => $route->uri() === 'api/v1/human-resource/dashboard'),
            'HR attendance table' => Schema::hasTable('HR_attendances'),
            'HR approval table' => Schema::hasTable('HR_attendance_approvals'),
            'HR leave table' => Schema::hasTable('HR_leave_requests'),
            'HR applicant table' => Schema::hasTable('HR_applications'),
            'HR payroll table' => Schema::hasTable('HR_payroll_cutoffs') && Schema::hasTable('HR_payroll_slips'),
            'HR bonus table' => Schema::hasTable('HR_bonus_projections'),
            'Attendance dashboard index' => $this->indexExists('HR_attendances', 'hr_att_i13_date_late_idx'),
            'Payroll dashboard index' => $this->indexExists('HR_payroll_cutoffs', 'hr_pay_cut_i13_final_idx'),
            'Bonus dashboard index' => $this->indexExists('HR_bonus_projections', 'hr_bonus_i13_final_idx'),
            'Applicant dashboard index' => $this->indexExists('HR_applications', 'hr_app_i13_created_pos_idx'),
        ];

        $failed = 0;
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('[%s] %s', $ok ? 'PASS' : 'FAIL', $label));
            if (! $ok) $failed++;
        }

        if ($failed) {
            $this->error("ERP POS V10 I13 Human Resource Dashboard check failed: {$failed} check(s).");
            return self::FAILURE;
        }

        $this->info('ERP POS V10 I13 Human Resource Dashboard is READY.');
        return self::SUCCESS;
    }

    private function indexExists(string $table, string $index): bool
    {
        if (! Schema::hasTable($table)) return false;
        try {
            return count(DB::select('SHOW INDEX FROM `'.$table.'` WHERE `Key_name` = ?', [$index])) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
