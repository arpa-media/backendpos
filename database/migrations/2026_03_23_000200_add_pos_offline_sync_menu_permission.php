<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('permissions')) {
            $permissionId = DB::table('permissions')
                ->where('name', 'pos.offline_sync.view')
                ->where('guard_name', 'web')
                ->value('id');

            if (! $permissionId) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'name' => 'pos.offline_sync.view',
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if (Schema::hasTable('roles') && Schema::hasTable('role_has_permissions')) {
                $sourcePermissionId = DB::table('permissions')
                    ->where('name', 'pos.checkout')
                    ->where('guard_name', 'web')
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
            ->where('path', '/c/offline-transactions')
            ->orWhere('code', 'sales-offline-sync')
            ->orWhere('code', 'pos-offline-transactions')
            ->first();

        if ($targetMenu) {
            DB::table('access_menus')
                ->where('id', $targetMenu->id)
                ->update([
                    'portal_id' => $portalId,
                    'code' => 'pos-offline-transactions',
                    'name' => 'Unsync Transactions',
                    'path' => '/c/offline-transactions',
                    'sort_order' => 35,
                    'permission_view' => 'pos.offline_sync.view',
                    'permission_create' => null,
                    'permission_update' => null,
                    'permission_delete' => null,
                    'is_active' => 1,
                    'updated_at' => $now,
                ]);
            $targetMenuId = $targetMenu->id;
        } else {
            $targetMenuId = (string) Str::ulid();
            DB::table('access_menus')->insert([
                'id' => $targetMenuId,
                'portal_id' => $portalId,
                'code' => 'pos-offline-transactions',
                'name' => 'Unsync Transactions',
                'path' => '/c/offline-transactions',
                'sort_order' => 35,
                'permission_view' => 'pos.offline_sync.view',
                'permission_create' => null,
                'permission_update' => null,
                'permission_delete' => null,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (Schema::hasTable('access_role_menu_permissions')) {
            $sourceMenuId = DB::table('access_menus')->where('path', '/c/pos')->value('id');
            if ($sourceMenuId && $targetMenuId) {
                $rows = DB::table('access_role_menu_permissions')
                    ->where('menu_id', $sourceMenuId)
                    ->get();

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

                    if ($existing) {
                        DB::table('access_role_menu_permissions')
                            ->where('id', $existing)
                            ->update([
                                'can_view' => (bool) $row->can_view,
                                'can_create' => false,
                                'can_edit' => false,
                                'can_delete' => false,
                                'updated_at' => $now,
                            ]);
                        continue;
                    }

                    DB::table('access_role_menu_permissions')->insert([
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $row->access_role_id,
                        'access_level_id' => $row->access_level_id,
                        'menu_id' => $targetMenuId,
                        'can_view' => (bool) $row->can_view,
                        'can_create' => false,
                        'can_edit' => false,
                        'can_delete' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')
                ->where('path', '/c/offline-transactions')
                ->orWhere('code', 'pos-offline-transactions')
                ->orWhere('code', 'sales-offline-sync')
                ->delete();
        }

        if (Schema::hasTable('permissions')) {
            $permissionId = DB::table('permissions')
                ->where('name', 'pos.offline_sync.view')
                ->where('guard_name', 'web')
                ->value('id');

            if ($permissionId && Schema::hasTable('role_has_permissions')) {
                DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            }

            if ($permissionId) {
                DB::table('permissions')->where('id', $permissionId)->delete();
            }
        }
    }
};
