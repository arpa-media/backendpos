<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrIteration01CheckCommand extends Command
{
    protected $signature = 'hr:iteration-01-check';
    protected $description = 'Verify Human Resource Iteration 01 Data Shift foundation.';

    public function handle(): int
    {
        $checks = [
            'HR_shifts table' => Schema::hasTable('HR_shifts'),
            'HR_shifts outlet_id' => Schema::hasTable('HR_shifts') && Schema::hasColumn('HR_shifts', 'outlet_id'),
            'HR_shifts start_time' => Schema::hasTable('HR_shifts') && Schema::hasColumn('HR_shifts', 'start_time'),
            'HR_shifts end_time' => Schema::hasTable('HR_shifts') && Schema::hasColumn('HR_shifts', 'end_time'),
            'Data Shift access menu' => Schema::hasTable('access_menus') && DB::table('access_menus')->where('code', 'hr-data-shift')->where('is_active', true)->exists(),
            'Spatie view permission' => ! Schema::hasTable('permissions') || DB::table('permissions')->where('name', 'hr.shift.view')->exists(),
            'Spatie create permission' => ! Schema::hasTable('permissions') || DB::table('permissions')->where('name', 'hr.shift.create')->exists(),
            'Route index' => Route::has('hr.shifts.index'),
            'Route bulk create' => Route::has('hr.shifts.bulk-store'),
            'Route update' => Route::has('hr.shifts.update'),
        ];

        $ok = true;
        foreach ($checks as $label => $passed) {
            $this->line(sprintf('%-34s %s', $label, $passed ? '<info>PASS</info>' : '<error>FAIL</error>'));
            $ok = $ok && $passed;
        }

        $this->newLine();
        $ok
            ? $this->info('HR Iteration 01 readiness: PASS')
            : $this->error('HR Iteration 01 readiness: FAIL');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
