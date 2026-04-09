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
        $now = now();
        $guard = 'web';

        if (class_exists(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $permissionId = null;

        if (Schema::hasTable('permissions')) {
            $permission = Permission::findOrCreate('pos.provision.view', $guard);
            $permissionId = $permission->getKey();

            if (Schema::hasTable('roles') && Schema::hasTable('role_has_permissions')) {
                $sourcePermissionId = DB::table('permissions')
                    ->where('name', 'pos.checkout')
                    ->where('guard_name', $guard)
                    ->value('id');

                if ($sourcePermissionId) {
                    $roleIds = DB::table('role_has_permissions')
                        ->where('permission_id', $sourcePermissionId)
                        ->pluck('role_id');

                    foreach ($roleIds as $roleId) {
                        DB::table('role_has_permissions')->updateOrInsert([
                            'permission_id' => $permissionId,
                            'role_id' => $roleId,
                        ], []);
                    }
                }
            }
        }

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            if (class_exists(PermissionRegistrar::class)) {
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }
            return;
        }

        $portalId = DB::table('access_portals')->where('code', 'pos')->value('id');
        if (! $portalId) {
            $portalId = (string) Str::ulid();
            DB::table('access_portals')->insert([
                'id' => $portalId,
                'code' => 'pos',
                'name' => 'POS',
                'description' => 'Portal POS',
                'sort_order' => 15,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $targetMenu = DB::table('access_menus')
            ->where('path', '/c/provision')
            ->orWhere('code', 'pos-provision')
            ->first();

        $menuPayload = [
            'portal_id' => $portalId,
            'code' => 'pos-provision',
            'name' => 'Provision',
            'path' => '/c/provision',
            'sort_order' => 34,
            'permission_view' => 'pos.provision.view',
            'permission_create' => null,
            'permission_update' => null,
            'permission_delete' => null,
            'is_active' => 1,
            'updated_at' => $now,
        ];

        if ($targetMenu) {
            DB::table('access_menus')->where('id', $targetMenu->id)->update($menuPayload);
            $targetMenuId = $targetMenu->id;
        } else {
            $targetMenuId = (string) Str::ulid();
            DB::table('access_menus')->insert($menuPayload + [
                'id' => $targetMenuId,
                'created_at' => $now,
            ]);
        }

        if (Schema::hasTable('access_role_menu_permissions')) {
            $sourceMenuId = DB::table('access_menus')->where('path', '/c/pos')->value('id');
            if ($sourceMenuId && $targetMenuId) {
                $rows = DB::table('access_role_menu_permissions')->where('menu_id', $sourceMenuId)->get();
                foreach ($rows as $row) {
                    $existing = DB::table('access_role_menu_permissions')
                        ->where('access_role_id', $row->access_role_id)
                        ->where(function ($query) use ($row) {
                            if ($row->access_level_id) {
                                $query->where('access_level_id', $row->access_level_id);
                                return;
                            }
                            $query->whereNull('access_level_id');
                        })
                        ->where('menu_id', $targetMenuId)
                        ->value('id');

                    $payload = [
                        'can_view' => (bool) $row->can_view,
                        'can_create' => false,
                        'can_edit' => false,
                        'can_delete' => false,
                        'updated_at' => $now,
                    ];

                    if ($existing) {
                        DB::table('access_role_menu_permissions')->where('id', $existing)->update($payload);
                        continue;
                    }

                    DB::table('access_role_menu_permissions')->insert($payload + [
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $row->access_role_id,
                        'access_level_id' => $row->access_level_id,
                        'menu_id' => $targetMenuId,
                        'created_at' => $now,
                    ]);
                }
            }
        }

        if (class_exists(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')->where('path', '/c/provision')->orWhere('code', 'pos-provision')->delete();
        }

        if (Schema::hasTable('permissions')) {
            $permissionId = DB::table('permissions')
                ->where('name', 'pos.provision.view')
                ->where('guard_name', 'web')
                ->value('id');

            if ($permissionId && Schema::hasTable('role_has_permissions')) {
                DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            }

            if ($permissionId) {
                DB::table('permissions')->where('id', $permissionId)->delete();
            }
        }

        if (class_exists(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
