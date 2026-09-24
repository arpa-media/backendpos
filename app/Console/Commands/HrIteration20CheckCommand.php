<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrIteration20CheckCommand extends Command
{
    protected $signature = 'hr:iteration-20-check';
    protected $description = 'Static/runtime contract check HR Iterasi 20.';

    public function handle(): int
    {
        $checks=[
            'Go-live run table'=>Schema::hasTable('HR_go_live_runs'),
            'Go-live result table'=>Schema::hasTable('HR_go_live_check_results'),
            'Punishment soft delete'=>Schema::hasColumn('HR_violations','deleted_at') && Schema::hasColumn('HR_warning_letters','deleted_at'),
            'Career account table'=>Schema::hasTable('HR_career_accounts'),
            'KPI period table'=>Schema::hasTable('HR_kpi_periods'),
            'Bonus projection table'=>Schema::hasTable('HR_bonus_projections'),
            'Finance payroll bonus bridge'=>Schema::hasColumn('finance_payroll_posting_inbox','hr_bonus_projection_id'),
            'Finance payroll cutoff bridge'=>Schema::hasColumn('finance_payroll_posting_inbox','hr_cutoff_id'),
            'User Management active'=>$this->menuActive('hr-user-management','/user-management'),
            'Legacy Users hidden'=>$this->menuInactiveOrMissing('hr-users'),
            'Interview menu canonical'=>$this->menuActive('hr-recruitment-interview','/human-resource/recruitment/interview'),
            'Cutoff Bonus canonical'=>$this->menuActive('hr-bonus-cutoff','/human-resource/cutoff-bonus'),
        ];
        $failed=0;
        foreach($checks as$name=>$ok){$this->line(($ok?'PASS':'FAIL').' '.$name);if(!$ok)$failed++;}
        if($failed){$this->error("HR Iteration 20 check failed: {$failed} check(s).");return self::FAILURE;}
        $this->info('HR Iteration 20 check passed.');return self::SUCCESS;
    }

    private function menuActive(string $code,string $path): bool
    {
        if(!Schema::hasTable('access_menus'))return false;$r=DB::table('access_menus')->where('code',$code)->first();return $r && (bool)$r->is_active && (string)$r->path===$path;
    }
    private function menuInactiveOrMissing(string $code): bool
    {
        if(!Schema::hasTable('access_menus'))return false;$r=DB::table('access_menus')->where('code',$code)->first();return !$r || !(bool)$r->is_active;
    }
}
