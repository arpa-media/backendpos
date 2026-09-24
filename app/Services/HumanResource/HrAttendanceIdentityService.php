<?php

namespace App\Services\HumanResource;

use App\Models\Employee;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HrAttendanceIdentityService
{
    public function resolve(User $user): array
    {
        $squad = $this->findSquad($user);
        $employee = Employee::query()
            ->with('assignment.outlet')
            ->where('user_id', $user->id)
            ->first();

        if (! $employee && filled($user->nisj)) {
            $employee = Employee::query()
                ->with('assignment.outlet')
                ->whereRaw('LOWER(TRIM(nisj)) = ?', [Str::lower(trim((string) $user->nisj))])
                ->first();
        }

        $assignmentOutlet = $employee?->assignment?->outlet;

        if (! $assignmentOutlet && $user->outlet_id) {
            $assignmentOutlet = Outlet::query()->find($user->outlet_id);
        }

        if (! $assignmentOutlet && $squad && filled($squad->assignment ?? null)) {
            $assignmentName = trim((string) $squad->assignment);
            $assignmentOutlet = Outlet::query()
                ->whereRaw('LOWER(TRIM(name)) = ?', [Str::lower($assignmentName)])
                ->first();
        }

        return [
            'user' => $user,
            'squad' => $squad,
            'employee' => $employee,
            'assignment_outlet' => $assignmentOutlet,
        ];
    }

    private function findSquad(User $user): ?object
    {
        if (! Schema::hasTable('HR_squads')) {
            return null;
        }

        if (Schema::hasColumn('HR_squads', 'user_id')) {
            $row = DB::table('HR_squads')
                ->where('user_id', $user->id)
                ->whereNull('deleted_at')
                ->first();
            if ($row) {
                return $row;
            }
        }

        $candidates = array_values(array_filter(array_unique(array_map(
            fn ($value) => Str::lower(trim((string) $value)),
            [$user->nisj, $user->username, $user->email]
        ))));

        if ($candidates === []) {
            return null;
        }

        return DB::table('HR_squads')
            ->whereNull('deleted_at')
            ->where(function ($query) use ($candidates): void {
                foreach ($candidates as $candidate) {
                    foreach (['nisj', 'username', 'email'] as $column) {
                        if (Schema::hasColumn('HR_squads', $column)) {
                            $query->orWhereRaw("LOWER(TRIM(`{$column}`)) = ?", [$candidate]);
                        }
                    }
                }
            })
            ->first();
    }
}
