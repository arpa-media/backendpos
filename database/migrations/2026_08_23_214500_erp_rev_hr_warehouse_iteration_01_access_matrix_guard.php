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
        foreach ([
            'hr.schedule.view', 'hr.schedule.create', 'hr.schedule.update', 'hr.schedule.delete',
            'hr.contract.view', 'hr.contract.create', 'hr.contract.update', 'hr.contract.delete',
        ] as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        if (! Schema::hasTable('access_menus')) {
            return;
        }

        // Do not create a duplicate menu. Guard the canonical menu records that
        // already exist in the HR portal and make their action mapping explicit.
        $scheduleBinding = [
            'permission_view' => 'hr.schedule.view',
            'permission_create' => 'hr.schedule.create',
            'permission_update' => 'hr.schedule.update',
            'permission_delete' => 'hr.schedule.delete',
            'is_active' => true,
        ];
        $contractBinding = [
            'permission_view' => 'hr.contract.view',
            'permission_create' => 'hr.contract.create',
            'permission_update' => 'hr.contract.update',
            'permission_delete' => 'hr.contract.delete',
            'is_active' => true,
        ];

        if (Schema::hasColumn('access_menus', 'updated_at')) {
            $scheduleBinding['updated_at'] = now();
            $contractBinding['updated_at'] = now();
        }

        DB::table('access_menus')->where('path', '/human-resource/mapping-schedule')->update($scheduleBinding);
        DB::table('access_menus')->where('path', '/human-resource/mapping-contract')->update($contractBinding);

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Intentionally no destructive rollback. This migration only repairs
        // canonical Access Matrix bindings and creates missing permissions.
    }
};
