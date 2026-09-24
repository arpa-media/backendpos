<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrIteration08CheckCommand extends Command
{
    protected $signature = 'hr:iteration-08-check';
    protected $description = 'Validate HR Iteration 08 payroll/cutoff installation';

    public function handle(): int
    {
        $checks = [
            'Attendance report Iteration 05' => Schema::hasTable('HR_shift_schedules') && Schema::hasTable('HR_attendances'),
            'Leave Iteration 07' => Schema::hasTable('HR_leave_requests'),
            'Table HR_payroll_cutoffs' => Schema::hasTable('HR_payroll_cutoffs'),
            'Table HR_payroll_slips' => Schema::hasTable('HR_payroll_slips'),
            'Route projection' => Route::has('hr.payroll.projection'),
            'Route cutoff' => Route::has('hr.payroll.cutoffs.index'),
            'Route self slip' => Route::has('hr.self.payroll.index'),
            'Access Matrix projection' => Schema::hasTable('access_menus') && DB::table('access_menus')->where('code','hr-payroll-projection')->exists(),
            'Access Matrix cutoff' => Schema::hasTable('access_menus') && DB::table('access_menus')->where('code','hr-payroll-cutoff')->exists(),
            'Access Matrix self slip' => Schema::hasTable('access_menus') && DB::table('access_menus')->where('code','hr-self-payroll-slip')->exists(),
        ];
        $failed=false;
        foreach($checks as $label=>$ok){$this->line(str_pad($label,38).($ok?' OK':' FAIL')); if(!$ok)$failed=true;}
        $this->newLine(); $this->line($failed?'HR Iteration 08 Payroll: FAIL':'HR Iteration 08 Payroll: PASS');
        return $failed?self::FAILURE:self::SUCCESS;
    }
}
