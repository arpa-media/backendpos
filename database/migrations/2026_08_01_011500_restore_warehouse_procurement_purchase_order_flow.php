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
            'code' => 'warehouse-purchase-requests',
            'name' => 'Purchase Request',
            'path' => '/warehouse/purchasing/purchase-requests',
            'sort_order' => 400,
            'permission' => 'warehouse.procurement.request',
        ],
        [
            'code' => 'warehouse-supplier-purchase-orders',
            'name' => 'Purchase Order',
            'path' => '/warehouse/purchasing/purchase-orders',
            'sort_order' => 410,
            'permission' => 'warehouse.procurement.order',
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

        $guard = (string) config('auth.defaults.guard', 'web');
        $now = now();

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
                    'permission_view' => $menu['permission'] . '.view',
                    'permission_create' => $menu['permission'] . '.create',
                    'permission_update' => $menu['permission'] . '.update',
                    'permission_delete' => $menu['permission'] . '.delete',
                    'is_active' => true,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ],
            );

            foreach (['view', 'create', 'update', 'delete'] as $action) {
                Permission::findOrCreate($menu['permission'] . '.' . $action, $guard);
            }
        }

        foreach ([
            'warehouse.procurement.request.approve',
            'warehouse.procurement.order.assign',
            'warehouse.procurement.purchase.input',
            'warehouse.procurement.purchase.approve',
            'warehouse.procurement.purchase.override',
            'warehouse.procurement.invoice.upload',
            'warehouse.procurement.invoice.download',
        ] as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Non-destructive. Warehouse procurement remains a valid operational domain.
    }
};
