<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

class HrIteration12CheckCommand extends Command
{
    protected $signature = 'hr:iteration-12-check';
    protected $description = 'Validate HR Iteration 12 manual attendance and manual leave installation';

    public function handle(): int
    {
        $checks = [
            'Attendance manual actor column' => Schema::hasColumn('HR_attendances', 'manual_created_by_user_id'),
            'Attendance manual reason column' => Schema::hasColumn('HR_attendances', 'manual_reason'),
            'Attendance manual audit table' => Schema::hasTable('HR_attendance_manual_logs'),
            'Leave source column' => Schema::hasColumn('HR_leave_requests', 'source'),
            'Leave manual actor column' => Schema::hasColumn('HR_leave_requests', 'manual_created_by_user_id'),
            'Manual attendance create route' => Route::has('hr.attendance-approvals.manual.store'),
            'Manual attendance update route' => Route::has('hr.attendance-approvals.manual.update'),
            'Manual leave create route' => Route::has('hr.leave-approvals.manual.store'),
        ];

        if (Schema::hasTable('permissions')) {
            foreach (['hr.attendance.manual.create', 'hr.attendance.manual.update', 'hr.leave.manual.create'] as $permission) {
                $checks["Permission {$permission}"] = Permission::query()->where('name', $permission)->exists();
            }
        }

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('%s %s', $ok ? '<info>PASS</info>' : '<error>FAIL</error>', $label));
            $failed = $failed || ! $ok;
        }

        if ($failed) {
            $this->error('Iteration 12 check failed. Run migrations and clear caches, then retry.');
            return self::FAILURE;
        }

        $this->info('HR Iteration 12 check passed.');
        return self::SUCCESS;
    }
}
