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

    private const MENUS = [
        [
            'code' => 'warehouse-sales-dashboard',
            'name' => 'Sales Warehouse',
            'path' => '/warehouse/sales',
            'sort_order' => 300,
            'permission' => 'warehouse.sales.dashboard',
        ],
        [
            'code' => 'warehouse-finance-dashboard',
            'name' => 'Finance Warehouse',
            'path' => '/warehouse/finance',
            'sort_order' => 700,
            'permission' => 'warehouse.finance.dashboard',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $portal = DB::table('access_portals')->where('code', self::PORTAL_CODE)->first();
        if (! $portal) {
            return;
        }

        $now = now();
        DB::table('access_portals')->where('id', $portal->id)->update([
            'name' => 'Warehouse',
            'description' => 'Portal bisnis dan operasional Warehouse yang berdiri mandiri dari Purchasing backoffice.',
            'updated_at' => $now,
        ]);

        $guard = (string) config('auth.defaults.guard', 'web');
        foreach (self::MENUS as $menu) {
            $existing = DB::table('access_menus')->where('code', $menu['code'])->first();
            $menuId = (string) ($existing->id ?? Str::ulid());

            DB::table('access_menus')->updateOrInsert(
                ['code' => $menu['code']],
                [
                    'id' => $menuId,
                    'portal_id' => $portal->id,
                    'name' => $menu['name'],
                    'path' => $menu['path'],
                    'sort_order' => $menu['sort_order'],
                    'permission_view' => $menu['permission'].'.view',
                    'permission_create' => null,
                    'permission_update' => null,
                    'permission_delete' => null,
                    'is_active' => true,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ]
            );

            Permission::findOrCreate($menu['permission'].'.view', $guard);
            $this->seedMatrixRows($menuId, $now);
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function seedMatrixRows(string $menuId, $now): void
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
                    'can_view' => $enabled,
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
        // Non-destructive: Access Matrix dapat sudah dikustomisasi oleh administrator.
    }
};
