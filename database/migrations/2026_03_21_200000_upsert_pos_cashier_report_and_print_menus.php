<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $portalId = DB::table('access_portals')->where('code', 'sales')->value('id');
        if (!$portalId) {
            return;
        }

        $menus = [
            ['code' => 'sales-pos', 'name' => 'Point of Sales', 'path' => '/c/pos', 'sort_order' => 15, 'permission_view' => 'pos.checkout'],
            ['code' => 'sales-cashier-report', 'name' => 'Cashier Report', 'path' => '/c/cashier-report', 'sort_order' => 25, 'permission_view' => 'report.view'],
            ['code' => 'sales-printer-settings', 'name' => 'Printer Settings', 'path' => '/c/printer', 'sort_order' => 95, 'permission_view' => 'pos.checkout'],
            ['code' => 'sales-receipt-print', 'name' => 'Receipt Print', 'path' => '/receipts/:id/print', 'sort_order' => 110, 'permission_view' => 'sale.view'],
            ['code' => 'sales-kitchen-print', 'name' => 'Kitchen Print', 'path' => '/kitchen/:id/print', 'sort_order' => 111, 'permission_view' => 'sale.view'],
            ['code' => 'sales-bar-print', 'name' => 'Bar Print', 'path' => '/bar/:id/print', 'sort_order' => 112, 'permission_view' => 'sale.view'],
            ['code' => 'sales-table-print', 'name' => 'Table Print', 'path' => '/table/:id/print', 'sort_order' => 113, 'permission_view' => 'sale.view'],
            ['code' => 'sales-pizza-print', 'name' => 'Pizza Print', 'path' => '/pizza/:id/print', 'sort_order' => 114, 'permission_view' => 'sale.view'],
            ['code' => 'sales-cashier-report-print', 'name' => 'Cashier Report Print', 'path' => '/cashier-report/print', 'sort_order' => 115, 'permission_view' => 'report.view'],
        ];

        foreach ($menus as $row) {
            $existing = DB::table('access_menus')->where('code', $row['code'])->first();
            $payload = [
                'portal_id' => $portalId,
                'name' => $row['name'],
                'path' => $row['path'],
                'sort_order' => $row['sort_order'],
                'permission_view' => $row['permission_view'],
                'permission_create' => null,
                'permission_update' => null,
                'permission_delete' => null,
                'is_active' => true,
                'updated_at' => $now,
            ];

            if ($existing) {
                DB::table('access_menus')->where('id', $existing->id)->update($payload);
            } else {
                DB::table('access_menus')->insert($payload + [
                    'id' => (string) Str::ulid(),
                    'code' => $row['code'],
                    'created_at' => $now,
                ]);
            }
        }

        $roleIds = DB::table('access_roles')->pluck('id', 'code');
        $menuRows = DB::table('access_menus')->whereIn('code', array_column($menus, 'code'))->get()->keyBy('code');

        $permissionSets = [
            'ADMIN' => ['*'],
            'MANAGER' => ['dashboard.view', 'outlet.view', 'outlet.update', 'category.view', 'category.create', 'category.update', 'category.delete', 'product.view', 'product.create', 'product.update', 'product.delete', 'payment_method.view', 'payment_method.create', 'payment_method.update', 'payment_method.delete', 'discount.view', 'discount.create', 'discount.update', 'discount.delete', 'taxes.view', 'taxes.create', 'taxes.update', 'taxes.delete', 'sale.view', 'sale.cancel.approve', 'customer.view', 'customer.create', 'report.view', 'user_management.view', 'pos.checkout'],
            'WAREHOUSE' => ['dashboard.view', 'outlet.view', 'category.view', 'product.view', 'sale.view', 'report.view'],
            'CASHIER' => ['dashboard.view', 'outlet.view', 'category.view', 'product.view', 'payment_method.view', 'discount.view', 'sale.view', 'customer.view', 'customer.create', 'report.view', 'pos.checkout'],
        ];

        foreach ($permissionSets as $roleCode => $permissions) {
            $roleId = $roleIds[$roleCode] ?? null;
            if (!$roleId) {
                continue;
            }

            foreach ($menus as $menuDef) {
                $menu = $menuRows[$menuDef['code']] ?? null;
                if (!$menu) {
                    continue;
                }

                $canView = in_array('*', $permissions, true) || in_array($menuDef['permission_view'], $permissions, true);

                $existing = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $roleId)
                    ->whereNull('access_level_id')
                    ->where('menu_id', $menu->id)
                    ->first();

                $payload = [
                    'can_view' => $canView,
                    'can_create' => false,
                    'can_edit' => false,
                    'can_delete' => false,
                    'updated_at' => $now,
                ];

                if ($existing) {
                    DB::table('access_role_menu_permissions')->where('id', $existing->id)->update($payload);
                } else {
                    DB::table('access_role_menu_permissions')->insert($payload + [
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $roleId,
                        'access_level_id' => null,
                        'menu_id' => $menu->id,
                        'created_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $codes = [
            'sales-pos',
            'sales-cashier-report',
            'sales-printer-settings',
            'sales-receipt-print',
            'sales-kitchen-print',
            'sales-bar-print',
            'sales-table-print',
            'sales-pizza-print',
            'sales-cashier-report-print',
        ];

        $menuIds = DB::table('access_menus')->whereIn('code', $codes)->pluck('id')->all();
        if (!empty($menuIds)) {
            DB::table('access_role_menu_permissions')->whereIn('menu_id', $menuIds)->delete();
        }
        DB::table('access_menus')->whereIn('code', $codes)->delete();
    }
};
