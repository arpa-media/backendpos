<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrIteration14CheckCommand extends Command
{
    protected $signature = 'hr:iteration-14-check';
    protected $description = 'Validate HR Iteration 14 payroll workflow, Finance bridge, BPJS bulk, and bonus placeholder.';

    public function handle(): int
    {
        $checks = [
            'Cutoff submitted metadata' => Schema::hasColumn('HR_payroll_cutoffs','submitted_at'),
            'Cutoff Finance bridge' => Schema::hasColumn('HR_payroll_cutoffs','finance_posting_id') && Schema::hasColumn('finance_payroll_posting_inbox','hr_cutoff_id'),
            'BPJS columns' => Schema::hasColumn('HR_payroll_slips','bpjs_health') && Schema::hasColumn('HR_payroll_slips','bpjs_total'),
            'Cutoff event audit' => Schema::hasTable('HR_payroll_cutoff_events'),
            'Import audit' => Schema::hasTable('HR_payroll_import_batches'),
            'Submit route' => Route::has('hr.payroll.i14.submit'),
            'Reopen route' => Route::has('hr.payroll.i14.reopen'),
            'Finance BPJS route' => Route::has('finance.payroll.bpjs.import'),
            'Bonus placeholder menu' => Schema::hasTable('access_menus') && DB::table('access_menus')->where('code','hr-bonus-cutoff')->where('is_active',true)->exists(),
            'Submit permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name','hr.payroll.cutoff.submit')->exists(),
            'BPJS import permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name','finance.payroll_posting.bpjs.import')->exists(),
        ];
        $failed = 0;
        foreach ($checks as $label => $ok) { $this->line(($ok ? '<info>PASS</info>' : '<error>FAIL</error>').' '.$label); if (!$ok) $failed++; }
        if ($failed) { $this->error("HR Iteration 14 check failed: {$failed} issue(s)."); return self::FAILURE; }
        $this->info('HR Iteration 14 check passed.'); return self::SUCCESS;
    }
}
