<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrIteration05CheckCommand extends Command
{
    protected $signature = 'hr:iteration-05-check';
    protected $description = 'Validate HR Iteration 05 Daily Report, Late and Recap foundation';

    public function handle(): int
    {
        $checks = [
            'Attendance Iteration 02' => Schema::hasTable('HR_attendances'),
            'Schedule Iteration 03' => Schema::hasTable('HR_shift_schedules'),
            'Approval Iteration 04' => Schema::hasTable('HR_attendance_approvals'),
            'Outlet → PT mapping' => Schema::hasTable('finance_outlet_company_mappings'),
            'Route Daily Report' => Route::has('hr.attendance-reports.daily'),
            'Route Data Terlambat' => Route::has('hr.attendance-reports.late'),
            'Route Rekap Absensi' => Route::has('hr.attendance-reports.recap'),
            'Permission Daily Report' => $this->permissionExists('hr.attendance.daily-report.view'),
            'Permission Data Terlambat' => $this->permissionExists('hr.attendance.late.view'),
            'Permission Rekap Absensi' => $this->permissionExists('hr.attendance.recap.view'),
            'Access Matrix Daily Report' => $this->menuExists('hr-attendance-daily-report'),
            'Access Matrix Data Terlambat' => $this->menuExists('hr-attendance-late-report'),
            'Access Matrix Rekap Absensi' => $this->menuExists('hr-attendance-recap-report'),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(str_pad($label, 38).($ok ? '<info>OK</info>' : '<error>FAIL</error>'));
            if (! $ok) $failed = true;
        }
        $this->newLine();
        $this->line($failed ? '<error>HR Iteration 05 Attendance Reports: FAIL</error>' : '<info>HR Iteration 05 Attendance Reports: PASS</info>');
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function permissionExists(string $name): bool
    {
        return Schema::hasTable('permissions') && DB::table('permissions')->where('name', $name)->exists();
    }

    private function menuExists(string $code): bool
    {
        return Schema::hasTable('access_menus') && DB::table('access_menus')->where('code', $code)->where('is_active', true)->exists();
    }
}
