<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrIteration07CheckCommand extends Command
{
    protected $signature = 'hr:iteration-07-check';
    protected $description = 'Validate HR Iteration 07 Ijin/Cuti + Approval installation';

    public function handle(): int
    {
        $checks = [
            'Table HR_leave_requests' => Schema::hasTable('HR_leave_requests'),
            'Table HR_leave_approval_logs' => Schema::hasTable('HR_leave_approval_logs'),
            'Table HR_leave_quota_ledgers' => Schema::hasTable('HR_leave_quota_ledgers'),
            'Data Squad leave quota source' => Schema::hasTable('HR_squads') && Schema::hasColumn('HR_squads', 'leave_quota'),
            'Route Self Leave list' => Route::has('hr.self.leave.index'),
            'Route Self Leave create' => Route::has('hr.self.leave.store'),
            'Route Self Leave cancel' => Route::has('hr.self.leave.cancel'),
            'Route Approval Leave list' => Route::has('hr.leave-approvals.index'),
            'Route Approval SPV' => Route::has('hr.leave-approvals.spv'),
            'Route Approval HRD' => Route::has('hr.leave-approvals.hrd'),
            'Route HRD Override SPV' => Route::has('hr.leave-approvals.override-spv'),
            'Permission self view' => $this->permissionExists('hr.leave.self.view'),
            'Permission self create' => $this->permissionExists('hr.leave.self.create'),
            'Permission self cancel' => $this->permissionExists('hr.leave.self.cancel'),
            'Permission approval view' => $this->permissionExists('hr.leave.approval.view'),
            'Permission approval SPV' => $this->permissionExists('hr.leave.approval.spv'),
            'Permission approval HRD' => $this->permissionExists('hr.leave.approval.hrd'),
            'Access Matrix Approval Ijin' => $this->menuExists('hr-approval-ijin', '/human-resource/approval-ijin'),
            'Access Matrix Self Izin Cuti' => $this->menuExists('hr-self-leave-request', '/user-dashboard'),
            'Daily Report leave policy' => class_exists(\App\Services\HumanResource\AttendanceReportPolicies\ApprovedLeavePolicy::class),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('%-40s %s', $label, $ok ? '<fg=green>OK</>' : '<fg=red>FAIL</>'));
            $failed = $failed || ! $ok;
        }
        $this->newLine();
        if ($failed) {
            $this->error('HR Iteration 07 Ijin/Cuti + Approval: FAIL');
            return self::FAILURE;
        }
        $this->info('HR Iteration 07 Ijin/Cuti + Approval: PASS');
        return self::SUCCESS;
    }

    private function permissionExists(string $name): bool
    {
        return Schema::hasTable('permissions') && DB::table('permissions')->where('name', $name)->exists();
    }

    private function menuExists(string $code, string $path): bool
    {
        return Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('code', $code)->where('path', $path)->exists();
    }
}
