<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PORTAL_CODE = 'warehouse-operations';
    private const MENU_CODE = 'warehouse-sales-orders';

    public function up(): void
    {
        $permissions = [
            'warehouse.sales.order.view',
            'warehouse.sales.order.create',
            'warehouse.sales.order.update',
            'warehouse.sales.order.approve',
            'warehouse.sales.order.cancel',
        ];

        $this->registerPermissions($permissions);

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $portal = DB::table('access_portals')->where('code', self::PORTAL_CODE)->first();
        if (! $portal) {
            return;
        }

        $now = now();
        $existing = DB::table('access_menus')->where('code', self::MENU_CODE)->first();
        $menuId = (string) ($existing->id ?? Str::ulid());

        DB::table('access_menus')->updateOrInsert(
            ['code' => self::MENU_CODE],
            [
                'id' => $menuId,
                'portal_id' => $portal->id,
                'name' => 'Sales Order Warehouse',
                'path' => '/warehouse/sales/orders',
                'sort_order' => 310,
                'permission_view' => 'warehouse.sales.order.view',
                'permission_create' => 'warehouse.sales.order.create',
                'permission_update' => 'warehouse.sales.order.update',
                'permission_delete' => 'warehouse.sales.order.cancel',
                'is_active' => true,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        $this->seedMenuMatrix($menuId, $now, true, true, true, true);
        $this->forgetPermissionCache();
    }

    private function registerPermissions(array $permissions): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $guard = config('auth.defaults.guard', 'web');
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, $guard);
        }
    }

    private function seedMenuMatrix(string $menuId, $now, bool $create, bool $edit, bool $delete, bool $view): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $roles = DB::table('access_roles')->select('id', 'code')->get();
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];

        foreach ($roles as $role) {
            $enabled = in_array(strtoupper(trim((string) $role->code)), ['ADMIN', 'WAREHOUSE'], true);
            foreach (array_merge([null], $levels) as $levelId) {
                $query = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $role->id)
                    ->where('menu_id', $menuId);
                $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);

                if ($query->exists()) {
                    continue;
                }

                DB::table('access_role_menu_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $role->id,
                    'access_level_id' => $levelId,
                    'menu_id' => $menuId,
                    'can_view' => $enabled && $view,
                    'can_create' => $enabled && $create,
                    'can_edit' => $enabled && $edit,
                    'can_delete' => $enabled && $delete,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function forgetPermissionCache(): void
    {
        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Non-destructive: Access Matrix may already be customized by an administrator.
    }
};
