<?php

namespace App\Services\HumanResource;

use App\Models\User;
use App\Services\HrUserDashboardService;
use App\Services\UserManagementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Iterasi 03 decorator untuk Dashboard User.
 * Binding dilakukan dari route module Iterasi 03 sehingga file Iterasi 02 tidak perlu ditimpa.
 */
class HrUserDashboardScheduleService extends HrUserDashboardService
{
    public function dashboard(User $user): array
    {
        $data = parent::dashboard($user);

        if (! Schema::hasTable('HR_shift_schedules')) {
            return $data;
        }

        $identity = app(HrAttendanceIdentityService::class)->resolve($user);
        $employee = $identity['employee'] ?? null;
        if (! $employee || ! $this->hasScheduleViewPermission($user)) {
            return $data;
        }

        $data['availability']['shift_schedule'] = true;
        $today = (string) ($data['today']['date'] ?? '');
        if ($today !== '') {
            $schedule = DB::table('HR_shift_schedules')
                ->where('employee_id', (string) $employee->id)
                ->whereDate('work_date', $today)
                ->first();
            $this->applyTodaySchedule($data, $schedule);
        }

        $dates = collect($data['quick_history'] ?? [])
            ->pluck('date')->filter()->unique()->values();
        if ($dates->isNotEmpty()) {
            $mapped = DB::table('HR_shift_schedules')
                ->where('employee_id', (string) $employee->id)
                ->whereIn('work_date', $dates->all())
                ->get()
                ->keyBy(fn ($row) => (string) $row->work_date);

            $data['quick_history'] = collect($data['quick_history'] ?? [])->map(function ($row) use ($mapped) {
                $schedule = $mapped->get((string) ($row['date'] ?? ''));
                if ($schedule) {
                    $row['shift_name'] = ($schedule->schedule_type ?? '') === 'off'
                        ? 'OFF'
                        : (string) ($schedule->shift_name_snapshot ?? 'Shift');
                }
                return $row;
            })->values()->all();
        }

        $data['meta']['contract'] = 'hr-user-dashboard-v1+schedule-iteration-03';
        return $data;
    }

    private function hasScheduleViewPermission(User $user): bool
    {
        $permission = 'hr.schedule.self.view';
        if ($user->can($permission)) return true;

        $snapshot = app(UserManagementService::class)->currentSessionSnapshot($user);
        if (collect($snapshot['permissions'] ?? [])->contains($permission)) return true;

        foreach (data_get($snapshot, 'access.menus', []) as $menu) {
            if (! is_array($menu)) continue;
            if (($menu['can_view'] ?? false) && ($menu['permission_view'] ?? null) === $permission) {
                return true;
            }
        }

        return false;
    }

    private function applyTodaySchedule(array &$data, ?object $schedule): void
    {
        if (! $schedule) {
            $data['today']['shift_name'] = 'Unmapped';
            $data['today']['shift_time'] = '--:-- - --:--';
            if (! str_contains((string) ($data['today']['late_status'] ?? ''), 'approval')) {
                $data['today']['late_status'] = 'Unmapped — tidak dihitung sebagai hari/jam kerja sampai jadwal dipetakan';
            }
            return;
        }

        if (($schedule->schedule_type ?? '') === 'off') {
            $data['today']['shift_name'] = 'OFF';
            $data['today']['shift_time'] = 'Hari libur';
            if (! str_contains((string) ($data['today']['late_status'] ?? ''), 'approval')) {
                $data['today']['late_status'] = 'OFF — tidak ada kalkulasi keterlambatan';
            }
            return;
        }

        $start = $this->timeHm($schedule->start_time_snapshot ?? null);
        $end = $this->timeHm($schedule->end_time_snapshot ?? null);
        $data['today']['shift_name'] = (string) ($schedule->shift_name_snapshot ?? 'Shift');
        $data['today']['shift_time'] = ($start ?: '--:--').' - '.($end ?: '--:--');
        if (! str_contains((string) ($data['today']['late_status'] ?? ''), 'approval')) {
            $data['today']['late_status'] = 'Mapped — kalkulasi keterlambatan diproses di Daily Report';
        }
    }

    private function timeHm(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        return $raw === '' ? null : substr($raw, 0, 5);
    }
}
