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

        // Keep Request Stock as the anchor, then place the active Receiving Stock
        // immediately below it. The legacy receive-stock menus stay inactive.
        DB::table('access_menus')->where('code', 'inventory-request-stock')->update([
            'sort_order' => 70,
            'updated_at' => $now,
        ]);

        $receiving = DB::table('access_menus')->where('code', 'inventory-warehouse-receiving')->first();
        if (! $receiving) {
            $receivingId = (string) Str::ulid();
            DB::table('access_menus')->insert([
                'id' => $receivingId,
                'portal_id' => $portal->id,
                'code' => 'inventory-warehouse-receiving',
                'name' => 'Receiving Stock',
                'path' => '/stock-inventory/warehouse-receiving',
                'sort_order' => 71,
                'permission_view' => 'warehouse.receiving.outlet.view',
                'permission_create' => 'warehouse.receiving.outlet.create',
                'permission_update' => 'warehouse.receiving.outlet.update',
                'permission_delete' => 'warehouse.receiving.outlet.delete',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('access_menus')->where('id', $receiving->id)->update([
                'portal_id' => $portal->id,
                'name' => 'Receiving Stock',
                'path' => '/stock-inventory/warehouse-receiving',
                'sort_order' => 71,
                'is_active' => true,
                'updated_at' => $now,
            ]);
        }

        DB::table('access_menus')
            ->whereIn('code', ['inventory-receive-stock', 'inventory-receiving-stock'])
            ->update(['is_active' => false, 'updated_at' => $now]);

        $actualPrefix = 'stock_inventory.actual_stock';
        $actual = DB::table('access_menus')->where('code', 'inventory-actual-stock')->first();
        $actualId = (string) ($actual->id ?? Str::ulid());
        $actualPayload = [
            'portal_id' => $portal->id,
            'name' => 'Aktual Stock',
            'path' => '/stock-inventory/actual-stock',
            'sort_order' => 72,
            'permission_view' => $actualPrefix.'.view',
            'permission_create' => $actualPrefix.'.create',
            'permission_update' => $actualPrefix.'.update',
            'permission_delete' => $actualPrefix.'.delete',
            'is_active' => true,
            'updated_at' => $now,
        ];

        if ($actual) {
            DB::table('access_menus')->where('id', $actualId)->update($actualPayload);
        } else {
            DB::table('access_menus')->insert($actualPayload + [
                'id' => $actualId,
                'code' => 'inventory-actual-stock',
                'created_at' => $now,
            ]);
            $this->copyMenuMatrix('/stock-inventory/par-stocks', $actualId, $now);
        }

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                Permission::findOrCreate($actualPrefix.'.'.$action, $guard);
            }
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                Permission::findOrCreate('warehouse.receiving.outlet.'.$action, $guard);
            }
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function copyMenuMatrix(string $sourcePath, string $targetMenuId, $now): void
    {
        if (! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $sourceMenuId = DB::table('access_menus')->where('path', $sourcePath)->value('id');
        if (! $sourceMenuId) {
            return;
        }

        foreach (DB::table('access_role_menu_permissions')->where('menu_id', $sourceMenuId)->get() as $row) {
            $query = DB::table('access_role_menu_permissions')
                ->where('access_role_id', $row->access_role_id)
                ->where('menu_id', $targetMenuId);
            $row->access_level_id === null
                ? $query->whereNull('access_level_id')
                : $query->where('access_level_id', $row->access_level_id);

            if ($query->exists()) {
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

    public function down(): void
    {
        // Non-destructive: menu/access configuration may have been customized after deployment.
    }
};
