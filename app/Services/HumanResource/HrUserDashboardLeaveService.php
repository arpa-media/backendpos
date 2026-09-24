<?php

namespace App\Services\HumanResource;

use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Iteration 07 dashboard decorator; extends Iteration 03 schedule decorator. */
class HrUserDashboardLeaveService extends HrUserDashboardScheduleService
{
    public function dashboard(User $user): array
    {
        $data = parent::dashboard($user);
        if (! Schema::hasTable('HR_leave_requests') || ! $this->hasPermission($user, 'hr.leave.self.view')) {
            return $data;
        }

        $data['availability']['leave_request'] = true;
        $quota = $this->quota($user, data_get($data, 'profile.nisj'));
        if ($quota !== null) $data['summary']['leave_quota'] = $quota;

        $pending = DB::table('HR_leave_requests')
            ->where('user_id', (string) $user->id)
            ->whereIn('status', ['pending_spv', 'pending_hrd'])
            ->count();
        $data['summary']['leave_pending'] = $pending;
        $data['meta']['contract'] = 'hr-user-dashboard-v1+schedule-iteration-03+leave-iteration-07';
        return $data;
    }

    private function quota(User $user, mixed $nisj): ?int
    {
        if (! Schema::hasTable('HR_squads')) return null;
        $base = DB::table('HR_squads');
        if (Schema::hasColumn('HR_squads', 'deleted_at')) $base->whereNull('deleted_at');
        $row = Schema::hasColumn('HR_squads', 'user_id')
            ? (clone $base)->where('user_id', (string) $user->id)->first(['leave_quota'])
            : null;
        if (! $row && filled($nisj) && Schema::hasColumn('HR_squads', 'nisj')) {
            $row = (clone $base)->whereRaw('LOWER(TRIM(nisj)) = ?', [Str::lower(trim((string) $nisj))])->first(['leave_quota']);
        }
        return $row ? max(0, (int) ($row->leave_quota ?? 0)) : null;
    }

    private function hasPermission(User $user, string $permission): bool
    {
        if ($user->can($permission)) return true;
        $snapshot = app(UserManagementService::class)->currentSessionSnapshot($user);
        if (collect($snapshot['permissions'] ?? [])->contains($permission)) return true;
        foreach (data_get($snapshot, 'access.menus', []) as $menu) {
            if (is_array($menu) && ($menu['can_view'] ?? false) && ($menu['permission_view'] ?? null) === $permission) return true;
        }
        return false;
    }
}
