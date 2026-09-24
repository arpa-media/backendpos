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
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $portal = DB::table('access_portals')->where('code', 'inventory')->first();
        if (! $portal) {
            return;
        }

        $now = now();
        $existing = DB::table('access_menus')->where('code', 'inventory-sku')->first();
        $menuId = (string) ($existing->id ?? Str::ulid());

        DB::table('access_menus')->updateOrInsert(
            ['code' => 'inventory-sku'],
            [
                'id' => $menuId,
                'portal_id' => $portal->id,
                'name' => 'Data SKU',
                'path' => '/stock-inventory/skus',
                'sort_order' => 40,
                'permission_view' => 'stock_inventory.sku.view',
                'permission_create' => 'stock_inventory.sku.create',
                'permission_update' => 'stock_inventory.sku.update',
                'permission_delete' => 'stock_inventory.sku.delete',
                'is_active' => true,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                Permission::findOrCreate('stock_inventory.sku.'.$action, $guard);
            }
        }

        // Tidak mengubah keputusan can_view/can_create/can_edit/can_delete yang
        // sudah dikustomisasi administrator. Hanya membuat row kosong bila menu
        // belum pernah diregistrasikan pada suatu kombinasi role + level.
        if (Schema::hasTable('access_roles') && Schema::hasTable('access_role_menu_permissions')) {
            $roles = DB::table('access_roles')->select('id', 'code')->get();
            $levels = Schema::hasTable('access_levels') ? DB::table('access_levels')->pluck('id')->all() : [];
            foreach ($roles as $role) {
                $isAdmin = strtoupper(trim((string) $role->code)) === 'ADMIN';
                foreach (array_merge([null], $levels) as $levelId) {
                    $query = DB::table('access_role_menu_permissions')
                        ->where('access_role_id', $role->id)
                        ->where('menu_id', $menuId);
                    $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
                    if ($query->exists()) continue;

                    $portalCanView = $isAdmin;
                    if (Schema::hasTable('access_role_portal_permissions')) {
                        $portalQuery = DB::table('access_role_portal_permissions')
                            ->where('access_role_id', $role->id)
                            ->where('portal_id', $portal->id);
                        $levelId === null ? $portalQuery->whereNull('access_level_id') : $portalQuery->where('access_level_id', $levelId);
                        $portalCanView = $portalCanView || (bool) ($portalQuery->value('can_view') ?? false);
                    }

                    DB::table('access_role_menu_permissions')->insert([
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $role->id,
                        'access_level_id' => $levelId,
                        'menu_id' => $menuId,
                        'can_view' => $portalCanView,
                        'can_create' => $isAdmin,
                        'can_edit' => $isAdmin,
                        'can_delete' => $isAdmin,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Non-destructive: pertahankan konfigurasi Access Matrix administrator.
    }
};
