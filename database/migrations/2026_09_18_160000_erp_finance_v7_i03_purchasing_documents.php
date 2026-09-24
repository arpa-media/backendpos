<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            $chamberPermission = Permission::findOrCreate('purchasing.fund_request.approve_chamber', $guard);
            foreach ([
                'purchasing.fund_request.view', 'purchasing.fund_request.create',
                'purchasing.fund_request.update', 'purchasing.fund_request.delete',
            ] as $permission) {
                Permission::findOrCreate($permission, $guard);
            }

            if (Schema::hasTable('roles') && Schema::hasTable('role_has_permissions')) {
                $legacy = Permission::query()
                    ->where('name', 'purchasing.fund_request.approve_executive')
                    ->where('guard_name', $guard)
                    ->first();
                if ($legacy) {
                    DB::table('role_has_permissions')
                        ->where('permission_id', $legacy->id)
                        ->get(['role_id'])
                        ->each(function ($row) use ($chamberPermission): void {
                            DB::table('role_has_permissions')->updateOrInsert([
                                'permission_id' => $chamberPermission->id,
                                'role_id' => $row->role_id,
                            ]);
                        });
                }
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $portal = DB::table('access_portals')->where('code', 'report')->first();
        if (! $portal) {
            return;
        }

        $now = now();
        $existing = DB::table('access_menus')->where('code', 'report-fund-requests')->first();
        DB::table('access_menus')->updateOrInsert(['code' => 'report-fund-requests'], [
            'id' => (string) ($existing->id ?? Str::ulid()),
            'portal_id' => $portal->id,
            'name' => 'Pengajuan Dana',
            'path' => '/report/fund-requests',
            'sort_order' => 50,
            'permission_view' => 'purchasing.fund_request.view',
            'permission_create' => 'purchasing.fund_request.create',
            'permission_update' => 'purchasing.fund_request.update',
            'permission_delete' => 'purchasing.fund_request.delete',
            'is_active' => true,
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ]);

    }

    public function down(): void
    {
        // Non-destructive: access settings and audit references may already use this menu.
    }
};
