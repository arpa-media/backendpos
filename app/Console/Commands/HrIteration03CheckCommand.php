<?php

namespace App\Console\Commands;

use App\Services\HrUserDashboardService;
use App\Services\HumanResource\HrUserDashboardScheduleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrIteration03CheckCommand extends Command
{
    protected $signature = 'hr:iteration-03-check';
    protected $description = 'Validasi instalasi HR Iteration 03 Mapping Schedule';

    public function handle(): int
    {
        $checks = [
            'Table HR_shift_schedules' => Schema::hasTable('HR_shift_schedules'),
            'Master shift Iteration 01' => Schema::hasTable('HR_shifts'),
            'Route admin schedule' => Route::has('hr.shift-schedules.index'),
            'Route self schedule' => Route::has('hr.self.schedule.index'),
            'Dashboard schedule decorator' => app(HrUserDashboardService::class) instanceof HrUserDashboardScheduleService,
            'Permission mapping view' => ! Schema::hasTable('permissions') || DB::table('permissions')->where('name', 'hr.schedule.view')->exists(),
            'Permission self view' => ! Schema::hasTable('permissions') || DB::table('permissions')->where('name', 'hr.schedule.self.view')->exists(),
            'Access Matrix Mapping Schedule' => ! Schema::hasTable('access_menus') || DB::table('access_menus')->where('code', 'hr-mapping-schedule')->exists(),
            'Access Matrix Jadwal Shift' => ! Schema::hasTable('access_menus') || DB::table('access_menus')->where('code', 'hr-self-shift-schedule')->exists(),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('%-38s %s', $label, $ok ? '<info>OK</info>' : '<error>FAIL</error>'));
            $failed = $failed || ! $ok;
        }

        if ($failed) {
            $this->error('HR Iteration 03 belum lengkap. Periksa migration/cache/route.');
            return self::FAILURE;
        }

        $this->info('HR Iteration 03 Mapping Schedule: PASS');
        return self::SUCCESS;
    }
}
