<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('access_portals') || !Schema::hasTable('access_menus')) {
            return;
        }

        $now = now();

        $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
        if (!$portal) {
            $portalId = (string) Str::ulid();
            DB::table('access_portals')->insert([
                'id' => $portalId,
                'code' => 'human-resource',
                'name' => 'Human Resource',
                'description' => 'Portal Human Resource',
                'sort_order' => 20,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $portalId = $portal->id;
            DB::table('access_portals')->where('id', $portalId)->update([
                'name' => 'Human Resource',
                'description' => $portal->description ?: 'Portal Human Resource',
                'is_active' => true,
                'updated_at' => $now,
            ]);
        }

        $menus = [
            [
                'code' => 'hr-data-master',
                'name' => 'Data Master',
                'path' => '/human-resource/data-master',
                'sort_order' => 12,
            ],
            [
                'code' => 'hr-data-squad',
                'name' => 'Data Squad',
                'path' => '/human-resource/data-squad',
                'sort_order' => 14,
            ],
        ];

        foreach ($menus as $menu) {
            $existing = DB::table('access_menus')
                ->where('code', $menu['code'])
                ->orWhere('path', $menu['path'])
                ->first();

            $payload = [
                'portal_id' => $portalId,
                'name' => $menu['name'],
                'path' => $menu['path'],
                'sort_order' => $menu['sort_order'],
                'permission_view' => null,
                'permission_create' => null,
                'permission_update' => null,
                'permission_delete' => null,
                'is_active' => true,
                'updated_at' => $now,
            ];

            if ($existing) {
                DB::table('access_menus')->where('id', $existing->id)->update($payload + ['code' => $menu['code']]);
            } else {
                DB::table('access_menus')->insert($payload + [
                    'id' => (string) Str::ulid(),
                    'code' => $menu['code'],
                    'created_at' => $now,
                ]);
            }
        }

        if (!Schema::hasTable('access_roles') || !Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $menuRows = DB::table('access_menus')
            ->whereIn('code', array_column($menus, 'code'))
            ->get();

        if ($menuRows->isEmpty()) {
            return;
        }

        $roleScopes = collect();

        if (Schema::hasTable('access_role_portal_permissions')) {
            $roleScopes = DB::table('access_role_portal_permissions')
                ->where('portal_id', $portalId)
                ->where('can_view', true)
                ->get(['access_role_id', 'access_level_id']);
        }

        if ($roleScopes->isEmpty()) {
            $adminRoleIds = DB::table('access_roles')
                ->whereIn('code', ['ADMIN', 'MANAGER'])
                ->pluck('id')
                ->map(fn ($id) => (object) ['access_role_id' => $id, 'access_level_id' => null]);

            $roleScopes = collect($adminRoleIds);
        }

        foreach ($roleScopes as $scope) {
            if (!$scope->access_role_id) {
                continue;
            }

            foreach ($menuRows as $menu) {
                $query = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $scope->access_role_id)
                    ->where('menu_id', $menu->id);

                if ($scope->access_level_id) {
                    $query->where('access_level_id', $scope->access_level_id);
                } else {
                    $query->whereNull('access_level_id');
                }

                $existing = $query->first();
                $row = [
                    'can_view' => true,
                    'can_create' => true,
                    'can_edit' => true,
                    'can_delete' => true,
                    'updated_at' => $now,
                ];

                if ($existing) {
                    DB::table('access_role_menu_permissions')->where('id', $existing->id)->update($row);
                } else {
                    DB::table('access_role_menu_permissions')->insert($row + [
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $scope->access_role_id,
                        'access_level_id' => $scope->access_level_id,
                        'menu_id' => $menu->id,
                        'created_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('access_menus')) {
            return;
        }

        $menuIds = DB::table('access_menus')
            ->whereIn('code', ['hr-data-master', 'hr-data-squad'])
            ->pluck('id')
            ->all();

        if (!empty($menuIds) && Schema::hasTable('access_role_menu_permissions')) {
            DB::table('access_role_menu_permissions')->whereIn('menu_id', $menuIds)->delete();
        }

        DB::table('access_menus')->whereIn('code', ['hr-data-master', 'hr-data-squad'])->delete();
    }
};
