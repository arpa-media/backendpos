<?php

namespace App\Services\HumanResource;

use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Iteration 08 dashboard decorator; extends Iteration 07 leave decorator. */
class HrUserDashboardPayrollService extends HrUserDashboardLeaveService
{
    public function dashboard(User $user): array
    {
        $data = parent::dashboard($user);
        if (! Schema::hasTable('HR_payroll_slips') || ! $this->hasPermission($user, 'hr.payroll.self.view')) return $data;
        $employeeId = DB::table('employees')->where('user_id',(string)$user->id)->value('id');
        $count = $employeeId ? DB::table('HR_payroll_slips')->where('employee_id',(string)$employeeId)->where('status','finalized')->count() : 0;
        $data['availability']['salary_slip'] = true;
        $data['summary']['salary_slip_count'] = $count;
        $data['meta']['contract'] = 'hr-user-dashboard-v1+schedule-iteration-03+leave-iteration-07+payroll-iteration-08';
        return $data;
    }

    private function hasPermission(User $user, string $permission): bool
    {
        if ($user->can($permission)) return true;
        $snapshot = app(UserManagementService::class)->currentSessionSnapshot($user);
        if (collect($snapshot['permissions'] ?? [])->contains($permission)) return true;
        foreach (data_get($snapshot,'access.menus',[]) as $menu) {
            if (is_array($menu) && ($menu['can_view'] ?? false) && ($menu['permission_view'] ?? null) === $permission) return true;
        }
        return false;
    }
}
