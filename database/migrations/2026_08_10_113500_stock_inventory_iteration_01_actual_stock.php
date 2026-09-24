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
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;

        $portal = DB::table('access_portals')->where('code', 'inventory')->first();
        if (! $portal) return;

        $now = now();
        $prefix = 'stock_inventory.actual_stock';
        $existing = DB::table('access_menus')->where('code', 'inventory-actual-stock')->first();
        $menuId = (string) ($existing->id ?? Str::ulid());

        $payload = [
            'portal_id' => $portal->id,
            'code' => 'inventory-actual-stock',
            'name' => 'Aktual Stok',
            'path' => '/stock-inventory/actual-stock',
            'sort_order' => 55,
            'permission_view' => $prefix.'.view',
            'permission_create' => $prefix.'.create',
            'permission_update' => $prefix.'.update',
            'permission_delete' => $prefix.'.delete',
            'is_active' => true,
            'updated_at' => $now,
        ];

        if ($existing) {
            DB::table('access_menus')->where('id', $menuId)->update($payload);
        } else {
            DB::table('access_menus')->insert($payload + ['id' => $menuId, 'created_at' => $now]);
        }

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                Permission::findOrCreate($prefix.'.'.$action, $guard);
            }
        }

        $this->copyParStockMatrix($menuId, $now);

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function copyParStockMatrix(string $targetMenuId, $now): void
    {
        if (! Schema::hasTable('access_role_menu_permissions')) return;

        $sourceMenuId = DB::table('access_menus')
            ->where('path', '/stock-inventory/par-stocks')
            ->value('id');

        if ($sourceMenuId) {
            $rows = DB::table('access_role_menu_permissions')
                ->where('menu_id', $sourceMenuId)
                ->get();

            foreach ($rows as $row) {
                $q = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $row->access_role_id)
                    ->where('menu_id', $targetMenuId);
                $row->access_level_id === null
                    ? $q->whereNull('access_level_id')
                    : $q->where('access_level_id', $row->access_level_id);

                if ($q->exists()) continue;

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
            return;
        }

        // Defensive fallback for databases that have not seeded Par Stock matrix.
        if (! Schema::hasTable('access_roles')) return;
        $levels = Schema::hasTable('access_levels') ? DB::table('access_levels')->pluck('id')->all() : [];
        foreach (DB::table('access_roles')->get(['id', 'code']) as $role) {
            $isAdmin = strtoupper(trim((string) $role->code)) === 'ADMIN';
            foreach (array_merge([null], $levels) as $levelId) {
                DB::table('access_role_menu_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $role->id,
                    'access_level_id' => $levelId,
                    'menu_id' => $targetMenuId,
                    'can_view' => $isAdmin,
                    'can_create' => false,
                    'can_edit' => false,
                    'can_delete' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: matrix may already be customized after deployment.
    }
};
