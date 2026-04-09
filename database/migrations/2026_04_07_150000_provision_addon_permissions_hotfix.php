<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        $permissions = [
            'addon.view',
            'addon.create',
            'addon.update',
            'addon.delete',
        ];

        foreach ($permissions as $permissionName) {
            Permission::findOrCreate($permissionName, $guard);
        }

        $adminRole = Role::query()->where('name', 'admin')->where('guard_name', $guard)->first();
        if ($adminRole) {
            $adminRole->givePermissionTo($permissions);
        }

        $managerRole = Role::query()->where('name', 'manager')->where('guard_name', $guard)->first();
        if ($managerRole) {
            $managerRole->givePermissionTo($permissions);
        }
    }

    public function down(): void
    {
        // Keep permissions in place to avoid breaking existing route middleware or access snapshots.
    }
};
