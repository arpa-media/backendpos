<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class HrIteration19CheckCommand extends Command
{
    protected $signature='hr:iteration-19-check';
    protected $description='Validate HR Iteration 19 Mapping KPI, Cutoff Bonus and Finance budget bridge.';

    public function handle(): int
    {
        $checks=[
            'KPI period table'=>Schema::hasTable('HR_kpi_periods'),
            'KPI score table'=>Schema::hasTable('HR_kpi_period_scores'),
            'Bonus policy table'=>Schema::hasTable('HR_bonus_policies'),
            'Bonus projection table'=>Schema::hasTable('HR_bonus_projections'),
            'Bonus line table'=>Schema::hasTable('HR_bonus_projection_lines'),
            'Bonus event table'=>Schema::hasTable('HR_bonus_projection_events'),
            'Finance bonus bridge'=>Schema::hasColumn('finance_payroll_posting_inbox','hr_bonus_projection_id')&&Schema::hasColumn('finance_payroll_posting_inbox','bonus_budget_percentage'),
            'Bonus policy V1'=>Schema::hasTable('HR_bonus_policies')&&DB::table('HR_bonus_policies')->where('code','BONUS_WORKBOOK_V1')->where('version',1)->exists(),
            'Mapping KPI menu'=>Schema::hasTable('access_menus')&&DB::table('access_menus')->where('code','hr-mapping-kpi')->where('is_active',true)->exists(),
            'Proyeksi Bonus menu'=>Schema::hasTable('access_menus')&&DB::table('access_menus')->where('code','hr-bonus-projection')->where('is_active',true)->exists(),
            'Cutoff Bonus active'=>Schema::hasTable('access_menus')&&DB::table('access_menus')->where('code','hr-bonus-cutoff')->where('is_active',true)->exists(),
            'KPI route'=>Route::has('hr.kpi.mapping.recalculate'),
            'Bonus submit route'=>Route::has('hr.bonus.projection.submit'),
            'Finance budget route'=>Route::has('finance.payroll.bonus-budget.update'),
        ];
        $failed=false;foreach($checks as$name=>$ok){$this->line(($ok?'<info>PASS</info>':'<error>FAIL</error>').' '.$name);$failed=$failed||!$ok;}
        if($failed){$this->error('HR Iteration 19 check failed.');return self::FAILURE;}$this->info('HR Iteration 19 check passed.');return self::SUCCESS;
    }
}
