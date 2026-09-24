<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrIteration04CheckCommand extends Command
{
    protected $signature = 'hr:iteration-04-check';
    protected $description = 'Validasi instalasi HR Iteration 04 Data Absen dan Approval';

    public function handle(): int
    {
        $permissions = [
            'hr.attendance.data.view',
            'hr.attendance.approval.view', 'hr.attendance.approval.spv', 'hr.attendance.approval.hrd', 'hr.attendance.approval.override_spv',
            'hr.attendance.duty.view', 'hr.attendance.duty.spv', 'hr.attendance.duty.hrd', 'hr.attendance.duty.override_spv',
        ];
        $menus = ['hr-data-attendance', 'hr-approval-attendance', 'hr-approval-duty'];

        $checks = [
            'Attendance Iteration 02' => Schema::hasTable('HR_attendances'),
            'Table approval states' => Schema::hasTable('HR_attendance_approvals'),
            'Table approval audit logs' => Schema::hasTable('HR_attendance_approval_logs'),
            'Route Data Absen' => Route::has('hr.attendance-data.index'),
            'Route Approval Absen' => Route::has('hr.attendance-approvals.absence.index'),
            'Route Approval Dinas' => Route::has('hr.attendance-approvals.duty.index'),
            'Route SPV/HRD approval' => Route::has('hr.attendance-approvals.absence.spv') && Route::has('hr.attendance-approvals.absence.hrd'),
            'Route HRD override SPV' => Route::has('hr.attendance-approvals.absence.override-spv'),
            'Spatie permissions' => ! Schema::hasTable('permissions') || collect($permissions)->every(fn ($permission) => DB::table('permissions')->where('name', $permission)->exists()),
            'Access Matrix menus' => ! Schema::hasTable('access_menus') || collect($menus)->every(fn ($code) => DB::table('access_menus')->where('code', $code)->exists()),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('%-38s %s', $label, $ok ? '<info>OK</info>' : '<error>FAIL</error>'));
            $failed = $failed || ! $ok;
        }

        if ($failed) {
            $this->error('HR Iteration 04 belum lengkap. Periksa urutan patch, migration, permission cache, dan route cache.');
            return self::FAILURE;
        }

        $this->info('HR Iteration 04 Data Absen + Approval: PASS');
        return self::SUCCESS;
    }
}
