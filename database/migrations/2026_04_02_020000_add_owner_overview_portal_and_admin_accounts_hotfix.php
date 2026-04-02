<?php

use App\Models\AccessLevel;
use App\Models\AccessRole;
use App\Models\Assignment;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (!Schema::hasTable('access_portals') || !Schema::hasTable('access_menus')) {
            return;
        }

        $upsertWithUlid = static function (string $table, array $match, array $values): string {
            $query = DB::table($table);
            foreach ($match as $column => $value) {
                if ($value === null) {
                    $query->whereNull($column);
                } else {
                    $query->where($column, $value);
                }
            }

            $existing = $query->first();
            if ($existing) {
                $updateValues = $values;
                unset($updateValues['created_at']);
                DB::table($table)->where('id', $existing->id)->update($updateValues);
                return (string) $existing->id;
            }

            $id = (string) Str::ulid();
            DB::table($table)->insert(array_merge(['id' => $id], $match, $values));
            return $id;
        };

        $defaultLevelId = AccessLevel::query()->where('code', 'DEFAULT')->value('id')
            ?: $upsertWithUlid('access_levels', ['code' => 'DEFAULT'], [
                'name' => 'Default',
                'description' => 'Default',
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $portalId = $upsertWithUlid('access_portals', ['code' => 'owner-overview'], [
            'name' => 'Owner Overview',
            'description' => 'Ringkasan owner untuk seluruh outlet.',
            'sort_order' => 18,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $menuId = $upsertWithUlid('access_menus', ['code' => 'owner-overview-dashboard'], [
            'portal_id' => $portalId,
            'name' => 'Overview',
            'path' => '/owner-overview',
            'sort_order' => 10,
            'permission_view' => 'dashboard.view',
            'permission_create' => null,
            'permission_update' => null,
            'permission_delete' => null,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $adminRoleId = AccessRole::query()->where('code', 'ADMIN')->value('id');
        if ($adminRoleId) {
            $upsertWithUlid('access_role_portal_permissions', [
                'access_role_id' => $adminRoleId,
                'access_level_id' => $defaultLevelId,
                'portal_id' => $portalId,
            ], [
                'can_view' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $upsertWithUlid('access_role_menu_permissions', [
                'access_role_id' => $adminRoleId,
                'access_level_id' => $defaultLevelId,
                'menu_id' => $menuId,
            ], [
                'can_view' => 1,
                'can_create' => 0,
                'can_edit' => 0,
                'can_delete' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $managementOutletId = DB::table('outlets')->where('name', 'Management Malang')->value('id');
        if (! $managementOutletId) {
            return;
        }

        $accountSeeds = [
            ['nisj' => '10012501001', 'name' => 'Akun Owner'],
            ['nisj' => '10012501002', 'name' => 'Akun Manager'],
            ['nisj' => '10012501003', 'name' => 'Akun Operator'],
        ];

        foreach ($accountSeeds as $seed) {
            $user = User::query()->where('nisj', $seed['nisj'])->first();
            if (! $user) {
                continue;
            }

            $user->forceFill([
                'name' => $seed['name'],
                'outlet_id' => $managementOutletId,
                'is_active' => true,
            ])->save();

            if ($adminRoleId) {
                $upsertWithUlid('user_access_assignments', ['user_id' => (string) $user->id], [
                    'access_role_id' => $adminRoleId,
                    'access_level_id' => $defaultLevelId,
                    'assigned_by_user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $employee = Employee::query()->firstOrNew(['user_id' => (string) $user->id]);
            $employee->forceFill([
                'user_id' => (string) $user->id,
                'nisj' => (string) $seed['nisj'],
                'full_name' => (string) $seed['name'],
                'nickname' => (string) $seed['name'],
                'employment_status' => 'active',
            ])->save();

            Assignment::query()->where('employee_id', (string) $employee->id)->update(['is_primary' => false]);

            $assignment = Assignment::query()->firstOrNew([
                'employee_id' => (string) $employee->id,
                'outlet_id' => (string) $managementOutletId,
            ]);
            $assignment->forceFill([
                'employee_id' => (string) $employee->id,
                'outlet_id' => (string) $managementOutletId,
                'role_title' => 'Human Resources',
                'start_date' => $assignment->start_date ?: now()->toDateString(),
                'end_date' => null,
                'is_primary' => true,
                'status' => 'active',
            ])->save();

            if ((string) $employee->assignment_id !== (string) $assignment->id) {
                $employee->forceFill(['assignment_id' => (string) $assignment->id])->save();
            }

            $user->syncRoles(['admin']);
        }
    }

    public function down(): void
    {
        // No-op: hotfix provisioning intentionally kept.
    }
};
