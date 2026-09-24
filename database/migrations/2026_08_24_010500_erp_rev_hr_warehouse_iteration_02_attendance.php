<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $bindings = [
            'hr-attendance-daily-report' => 'hr.attendance.daily-report.export',
            'hr-attendance-late-report' => 'hr.attendance.late.export',
            'hr-attendance-recap-report' => 'hr.attendance.recap.export',
        ];

        foreach ($bindings as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        if (! Schema::hasTable('access_menus')) {
            $this->forgetPermissionCache();
            return;
        }

        foreach ($bindings as $menuCode => $permission) {
            $menu = DB::table('access_menus')->where('code', $menuCode)->first();
            if (! $menu) continue;

            $payload = ['permission_create' => $permission, 'is_active' => true];
            if (Schema::hasColumn('access_menus', 'updated_at')) $payload['updated_at'] = now();
            DB::table('access_menus')->where('id', $menu->id)->update($payload);

            // Export is a new capability in Iterasi 02. Existing viewers should
            // receive it by default, while Access Matrix can still revoke the
            // Create/Export action per role + level afterwards.
            if (Schema::hasTable('access_role_menu_permissions')) {
                $matrixPayload = ['can_create' => true];
                if (Schema::hasColumn('access_role_menu_permissions', 'updated_at')) $matrixPayload['updated_at'] = now();
                DB::table('access_role_menu_permissions')
                    ->where('menu_id', $menu->id)
                    ->where('can_view', true)
                    ->update($matrixPayload);
            }
        }

        $this->forgetPermissionCache();
    }

    public function down(): void
    {
        // Non-destructive by design. Access Matrix values may have been customized
        // after deployment and must not be silently reverted by rollback.
    }

    private function forgetPermissionCache(): void
    {
        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
