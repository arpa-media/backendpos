<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $this->addUserRetirementMarker();
        $this->backfillSquadEmployees();
        $this->repairSchedulePlacement();
        $this->repairSelfServiceAccess();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function addUserRetirementMarker(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'hr_retired_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('hr_retired_at')->nullable()->index('users_hr_retired_at_idx');
        });
    }

    private function backfillSquadEmployees(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('employees') || ! Schema::hasTable('HR_squads')) {
            return;
        }

        if (! Schema::hasColumn('HR_squads', 'user_id') || ! Schema::hasColumn('employees', 'user_id')) {
            return;
        }

        $query = DB::table('HR_squads as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->whereNull('s.deleted_at')
            ->whereNotNull('s.user_id');

        if (Schema::hasColumn('users', 'hr_retired_at')) {
            $query->whereNull('u.hr_retired_at');
        }
        if (Schema::hasColumn('users', 'is_active')) {
            $query->where('u.is_active', true);
        }
        if (Schema::hasColumn('HR_squads', 'role_name')) {
            $query->whereNotIn(DB::raw("UPPER(TRIM(COALESCE(s.role_name, '')))" ), ['STAKEHOLDER', 'OBSERVER']);
        }
        if (Schema::hasColumn('HR_squads', 'access_role')) {
            $query->whereNotIn(DB::raw("UPPER(TRIM(COALESCE(s.access_role, '')))" ), ['STAKEHOLDER', 'OBSERVER']);
        }

        $query->select([
            's.user_id', 's.nisj', 's.full_name', 's.nickname', 's.employee_type',
            'u.name as user_name',
        ])->orderBy('s.id')->chunk(200, function ($rows): void {
            foreach ($rows as $row) {
                $userId = trim((string) ($row->user_id ?? ''));
                if ($userId === '' || DB::table('employees')->where('user_id', $userId)->exists()) {
                    continue;
                }

                $nisj = trim((string) ($row->nisj ?? ''));
                $existingByNisj = $nisj === ''
                    ? null
                    : DB::table('employees')
                        ->whereRaw("LOWER(TRIM(COALESCE(nisj, ''))) = ?", [mb_strtolower($nisj)])
                        ->first();

                if ($existingByNisj) {
                    if (empty($existingByNisj->user_id)) {
                        DB::table('employees')->where('id', $existingByNisj->id)->update([
                            'user_id' => $userId,
                            'updated_at' => now(),
                        ]);
                    }
                    continue;
                }

                DB::table('employees')->insert([
                    'id' => (string) Str::ulid(),
                    'user_id' => $userId,
                    'assignment_id' => null,
                    'hr_employee_id' => null,
                    'nisj' => $nisj !== '' ? $nisj : null,
                    'full_name' => trim((string) ($row->full_name ?? $row->user_name ?? '')) ?: null,
                    'nickname' => trim((string) ($row->nickname ?? '')) ?: null,
                    'employment_status' => trim((string) ($row->employee_type ?? '')) ?: 'hr-squad',
                    'source_updated_at' => null,
                    'imported_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    private function repairSchedulePlacement(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $now = now();
        $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
        if (! $portal) {
            return;
        }

        $menu = DB::table('access_menus')->where('code', 'hr-mapping-schedule')->first();
        if ($menu) {
            DB::table('access_menus')->where('id', $menu->id)->update([
                'name' => 'Schedule',
                'path' => '/human-resource/mapping-schedule',
                'portal_id' => $portal->id,
                'sort_order' => 31,
                'permission_view' => 'hr.schedule.view',
                'permission_create' => 'hr.schedule.create',
                'permission_update' => 'hr.schedule.update',
                'permission_delete' => 'hr.schedule.delete',
                'is_active' => true,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('access_menus')->insert([
                'id' => (string) Str::ulid(),
                'portal_id' => (string) $portal->id,
                'code' => 'hr-mapping-schedule',
                'name' => 'Schedule',
                'path' => '/human-resource/mapping-schedule',
                'sort_order' => 31,
                'permission_view' => 'hr.schedule.view',
                'permission_create' => 'hr.schedule.create',
                'permission_update' => 'hr.schedule.update',
                'permission_delete' => 'hr.schedule.delete',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Deactivate accidental duplicate legacy rows without destroying their matrix history.
        DB::table('access_menus')
            ->where('portal_id', $portal->id)
            ->where('code', '<>', 'hr-mapping-schedule')
            ->where('path', '/human-resource/data-absensi/mapping-schedule')
            ->update(['is_active' => false, 'updated_at' => $now]);
    }

    private function repairSelfServiceAccess(): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_menus') || ! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $guard = config('auth.defaults.guard', 'web');
        foreach (['hr.schedule.self.view', 'hr.leave.self.view', 'hr.leave.self.create', 'hr.leave.self.cancel'] as $permission) {
            if (Schema::hasTable('permissions')) {
                Permission::findOrCreate($permission, $guard);
            }
        }

        $scheduleMenu = DB::table('access_menus')->where('code', 'hr-self-shift-schedule')->first();
        $leaveMenu = DB::table('access_menus')->where('code', 'hr-self-leave-request')->first();
        if (! $scheduleMenu && ! $leaveMenu) {
            return;
        }

        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];
        $now = now();

        foreach (DB::table('access_roles')->get(['id', 'code']) as $role) {
            $roleCode = strtoupper(trim((string) $role->code));
            $allowed = ! in_array($roleCode, ['STAKEHOLDER', 'OBSERVER'], true);

            foreach (array_merge([null], $levels) as $levelId) {
                if ($scheduleMenu) {
                    $this->upsertMatrix((string) $role->id, $levelId, (string) $scheduleMenu->id, [
                        'can_view' => $allowed,
                        'can_create' => false,
                        'can_edit' => false,
                        'can_delete' => false,
                    ], $now);
                }
                if ($leaveMenu) {
                    $this->upsertMatrix((string) $role->id, $levelId, (string) $leaveMenu->id, [
                        'can_view' => $allowed,
                        'can_create' => $allowed,
                        'can_edit' => false,
                        'can_delete' => $allowed,
                    ], $now);
                }
            }
        }
    }

    private function upsertMatrix(string $roleId, ?string $levelId, string $menuId, array $rights, $now): void
    {
        $query = DB::table('access_role_menu_permissions')
            ->where('access_role_id', $roleId)
            ->where('menu_id', $menuId);
        $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
        $existing = $query->first();

        $payload = array_merge($rights, ['updated_at' => $now]);
        if ($existing) {
            DB::table('access_role_menu_permissions')->where('id', $existing->id)->update($payload);
            return;
        }

        DB::table('access_role_menu_permissions')->insert(array_merge($payload, [
            'id' => (string) Str::ulid(),
            'access_role_id' => $roleId,
            'access_level_id' => $levelId,
            'menu_id' => $menuId,
            'created_at' => $now,
        ]));
    }

    public function down(): void
    {
        // Non-destructive by design. Access Matrix may be customized after deployment,
        // and hr_retired_at preserves account/audit referential integrity.
    }
};
