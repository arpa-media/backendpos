<?php

namespace App\Support;

final class UserManagementCatalog
{
    public static function portals(): array
    {
        return [
            ['code' => 'sales', 'name' => 'POS Outlet', 'description' => 'Portal POS Outlet', 'sort_order' => 10],
            ['code' => 'human-resource', 'name' => 'Human Resource', 'description' => 'Portal Human Resource', 'sort_order' => 20],
            ['code' => 'finance', 'name' => 'Finance', 'description' => 'Portal Finance', 'sort_order' => 30],
            ['code' => 'bank', 'name' => 'Bank Settlement', 'description' => 'Portal Bank Settlement', 'sort_order' => 40],
            ['code' => 'inventory', 'name' => 'Stock Inventory', 'description' => 'Portal Stock Inventory', 'sort_order' => 50],
            ['code' => 'purchasing', 'name' => 'Purchasing', 'description' => 'Portal Purchasing', 'sort_order' => 60],
            ['code' => 'customer', 'name' => 'Customer', 'description' => 'Portal Customer', 'sort_order' => 70],
            ['code' => 'operational', 'name' => 'Chambers Operational', 'description' => 'Portal Chambers Operational', 'sort_order' => 80],
            ['code' => 'warehouse', 'name' => 'HPP/COGS', 'description' => 'Portal HPP/COGS', 'sort_order' => 90],
        ];
    }

    public static function menus(): array
    {
        return [
            ['portal_code' => 'sales', 'code' => 'sales-dashboard', 'name' => 'Dashboard', 'path' => '/dashboard', 'sort_order' => 10, 'permission_view' => 'dashboard.view'],
            ['portal_code' => 'sales', 'code' => 'sales-pos-terminal', 'name' => 'POS', 'path' => '/c/pos', 'sort_order' => 15, 'permission_view' => 'pos.checkout'],
            ['portal_code' => 'sales', 'code' => 'sales-category', 'name' => 'Categories', 'path' => '/categories', 'sort_order' => 50, 'permission_view' => 'category.view', 'permission_create' => 'category.create', 'permission_update' => 'category.update', 'permission_delete' => 'category.delete'],
            ['portal_code' => 'sales', 'code' => 'sales-product', 'name' => 'Product', 'path' => '/products', 'sort_order' => 60, 'permission_view' => 'product.view', 'permission_create' => 'product.create', 'permission_update' => 'product.update', 'permission_delete' => 'product.delete'],
            ['portal_code' => 'sales', 'code' => 'sales-payment-method', 'name' => 'Payment Method', 'path' => '/payment-methods', 'sort_order' => 70, 'permission_view' => 'payment_method.view', 'permission_create' => 'payment_method.create', 'permission_update' => 'payment_method.update', 'permission_delete' => 'payment_method.delete'],
            ['portal_code' => 'sales', 'code' => 'sales-discount', 'name' => 'Discount', 'path' => '/discounts', 'sort_order' => 80, 'permission_view' => 'discount.view', 'permission_create' => 'discount.create', 'permission_update' => 'discount.update', 'permission_delete' => 'discount.delete'],
            ['portal_code' => 'sales', 'code' => 'sales-taxes', 'name' => 'Taxes', 'path' => '/taxes', 'sort_order' => 90, 'permission_view' => 'taxes.view', 'permission_create' => 'taxes.create', 'permission_update' => 'taxes.update', 'permission_delete' => 'taxes.delete'],
            ['portal_code' => 'sales', 'code' => 'sales-addons', 'name' => 'Addons', 'path' => '/addons', 'sort_order' => 100, 'permission_view' => 'addon.view', 'permission_create' => 'addon.create', 'permission_update' => 'addon.update', 'permission_delete' => 'addon.delete'],
            ['portal_code' => 'sales', 'code' => 'sales-pos-customer', 'name' => 'POS Customer', 'path' => '/c/pos/customers', 'sort_order' => 110, 'permission_view' => 'customer.view', 'permission_create' => 'customer.create'],

            ['portal_code' => 'human-resource', 'code' => 'hr-dashboard', 'name' => 'Dashboard', 'path' => '/portal/human-resource/dashboard', 'sort_order' => 10],
            ['portal_code' => 'human-resource', 'code' => 'hr-user-management', 'name' => 'User Management', 'path' => '/user-management', 'sort_order' => 20, 'permission_view' => 'user_management.view', 'permission_update' => 'user_management.edit'],
            ['portal_code' => 'human-resource', 'code' => 'hr-users', 'name' => 'Users', 'path' => '/users', 'sort_order' => 30],

            ['portal_code' => 'customer', 'code' => 'customer-dashboard', 'name' => 'Dashboard', 'path' => '/portal/customer/dashboard', 'sort_order' => 10],
            ['portal_code' => 'customer', 'code' => 'customer-list', 'name' => 'Customer', 'path' => '/customers', 'sort_order' => 20, 'permission_view' => 'customer.view', 'permission_create' => 'customer.create'],

            ['portal_code' => 'operational', 'code' => 'operational-dashboard', 'name' => 'Dashboard', 'path' => '/portal/operational/dashboard', 'sort_order' => 10],
            ['portal_code' => 'operational', 'code' => 'operational-outlet', 'name' => 'Outlet', 'path' => '/settings/outlet', 'sort_order' => 20, 'permission_view' => 'outlet.view', 'permission_update' => 'outlet.update'],

            ['portal_code' => 'inventory', 'code' => 'inventory-dashboard', 'name' => 'Dashboard', 'path' => '/portal/inventory/dashboard', 'sort_order' => 10],
            ['portal_code' => 'inventory', 'code' => 'inventory-check-stock', 'name' => 'Cek Stock', 'path' => '/check-stock', 'sort_order' => 20],
            ['portal_code' => 'inventory', 'code' => 'inventory-request-stock', 'name' => 'Request Stock', 'path' => '/request-stock', 'sort_order' => 30],

            ['portal_code' => 'warehouse', 'code' => 'warehouse-dashboard', 'name' => 'Dashboard', 'path' => '/portal/warehouse/dashboard', 'sort_order' => 10],
            ['portal_code' => 'warehouse', 'code' => 'warehouse-bill-of-material', 'name' => 'Bill of Material', 'path' => '/bill-of-material', 'sort_order' => 20],

            ['portal_code' => 'finance', 'code' => 'finance-dashboard', 'name' => 'Dashboard', 'path' => '/portal/finance/dashboard', 'sort_order' => 10],
            ['portal_code' => 'finance', 'code' => 'sales-list', 'name' => 'Sales', 'path' => '/sales', 'sort_order' => 20, 'permission_view' => 'sale.view'],
            ['portal_code' => 'finance', 'code' => 'sales-report', 'name' => 'Report', 'path' => '/reports', 'sort_order' => 30, 'permission_view' => 'report.view'],
            ['portal_code' => 'finance', 'code' => 'sales-cancel', 'name' => 'Cancel Bill', 'path' => '/cancel-requests', 'sort_order' => 40, 'permission_view' => 'sale.cancel.approve'],
            ['portal_code' => 'bank', 'code' => 'bank-dashboard', 'name' => 'Dashboard', 'path' => '/portal/bank/dashboard', 'sort_order' => 10],
            ['portal_code' => 'purchasing', 'code' => 'purchasing-dashboard', 'name' => 'Dashboard', 'path' => '/portal/purchasing/dashboard', 'sort_order' => 10],
        ];
    }

    public static function permissions(): array
    {
        $permissions = [];
        foreach (self::menus() as $menu) {
            foreach (['permission_view', 'permission_create', 'permission_update', 'permission_delete'] as $key) {
                $permission = $menu[$key] ?? null;
                if ($permission) {
                    $permissions[] = $permission;
                }
            }
        }

        $permissions[] = 'auth.me';
        $permissions[] = 'admin.access';
        $permissions[] = 'pos.checkout';
        $permissions[] = 'sale.cancel.request';

        return array_values(array_unique($permissions));
    }
}
