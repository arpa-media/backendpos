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
            ['code' => 'owner-overview', 'name' => 'Owner Overview', 'description' => 'Portal owner overview', 'sort_order' => 100],
            ['code' => 'omzet-report', 'name' => 'Omzet Report', 'description' => 'Portal report omzet seluruh transaksi POS.', 'sort_order' => 110],
            ['code' => 'sales-report', 'name' => 'Sales Report', 'description' => 'Portal report transaksi dengan marking 1.', 'sort_order' => 120],
            ['code' => 'report', 'name' => 'Report', 'description' => 'Portal report operasional.', 'sort_order' => 130],
            ['code' => 'console', 'name' => 'Console', 'description' => 'System console untuk observability, reporting control, dan storage file management.', 'sort_order' => 9999],
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
            ['portal_code' => 'operational', 'code' => 'operational-outlet-pin', 'name' => 'Outlet PIN', 'path' => '/operational/outlet-pin', 'sort_order' => 30, 'permission_view' => 'operational.outlet_pin.view', 'permission_update' => 'operational.outlet_pin.update'],
            ['portal_code' => 'operational', 'code' => 'operational-sales-analytic-daily', 'name' => 'Daily Analytic', 'path' => '/operational/sales-analytic/daily', 'sort_order' => 40, 'permission_view' => 'operational.sales_analytic.daily.view'],
            ['portal_code' => 'operational', 'code' => 'operational-sales-analytic-hourly', 'name' => 'Omzet Per Hour', 'path' => '/operational/sales-analytic/hourly', 'sort_order' => 50, 'permission_view' => 'operational.sales_analytic.hourly.view'],
            ['portal_code' => 'operational', 'code' => 'operational-sales-analytic-hourly-summary', 'name' => 'Summary Per Hour', 'path' => '/operational/sales-analytic/hourly-summary', 'sort_order' => 60, 'permission_view' => 'operational.sales_analytic.hourly_summary.view'],

            ['portal_code' => 'inventory', 'code' => 'inventory-dashboard', 'name' => 'Dashboard Stock Inventory', 'path' => '/portal/inventory/dashboard', 'sort_order' => 10, 'permission_view' => 'stock_inventory.dashboard.view', 'permission_create' => 'stock_inventory.dashboard.create', 'permission_update' => 'stock_inventory.dashboard.update', 'permission_delete' => 'stock_inventory.dashboard.delete'],
            ['portal_code' => 'inventory', 'code' => 'inventory-uom', 'name' => 'Unit of Measure', 'path' => '/stock-inventory/uoms', 'sort_order' => 20, 'permission_view' => 'stock_inventory.uom.view', 'permission_create' => 'stock_inventory.uom.create', 'permission_update' => 'stock_inventory.uom.update', 'permission_delete' => 'stock_inventory.uom.delete'],
            ['portal_code' => 'inventory', 'code' => 'inventory-stock-category', 'name' => 'Stock Category', 'path' => '/stock-inventory/categories', 'sort_order' => 30, 'permission_view' => 'stock_inventory.category.view', 'permission_create' => 'stock_inventory.category.create', 'permission_update' => 'stock_inventory.category.update', 'permission_delete' => 'stock_inventory.category.delete'],
            ['portal_code' => 'inventory', 'code' => 'inventory-sku', 'name' => 'Data SKU', 'path' => '/stock-inventory/skus', 'sort_order' => 40, 'permission_view' => 'stock_inventory.sku.view', 'permission_create' => 'stock_inventory.sku.create', 'permission_update' => 'stock_inventory.sku.update', 'permission_delete' => 'stock_inventory.sku.delete'],
            ['portal_code' => 'inventory', 'code' => 'inventory-par-stock', 'name' => 'Par Stock', 'path' => '/stock-inventory/par-stocks', 'sort_order' => 50, 'permission_view' => 'stock_inventory.par_stock.view', 'permission_create' => 'stock_inventory.par_stock.create', 'permission_update' => 'stock_inventory.par_stock.update', 'permission_delete' => 'stock_inventory.par_stock.delete'],
            ['portal_code' => 'inventory', 'code' => 'inventory-stock-opname', 'name' => 'Stock Opname', 'path' => '/stock-inventory/stock-opname', 'sort_order' => 60, 'permission_view' => 'stock_inventory.opname.view', 'permission_create' => 'stock_inventory.opname.create', 'permission_update' => 'stock_inventory.opname.update', 'permission_delete' => 'stock_inventory.opname.delete'],
            ['portal_code' => 'inventory', 'code' => 'inventory-request-stock', 'name' => 'Request Stock', 'path' => '/stock-inventory/request-stock', 'sort_order' => 70, 'permission_view' => 'stock_inventory.request_stock.view', 'permission_create' => 'stock_inventory.request_stock.create', 'permission_update' => 'stock_inventory.request_stock.update', 'permission_delete' => 'stock_inventory.request_stock.delete'],
            ['portal_code' => 'inventory', 'code' => 'inventory-cancellation-approval', 'name' => 'Cancellation Approval', 'path' => '/stock-inventory/cancellation-approval', 'sort_order' => 75, 'permission_view' => 'stock_inventory.cancellation_approval.view', 'permission_create' => 'stock_inventory.cancellation_approval.create', 'permission_update' => 'stock_inventory.cancellation_approval.update', 'permission_delete' => 'stock_inventory.cancellation_approval.delete'],

            ['portal_code' => 'warehouse', 'code' => 'warehouse-dashboard', 'name' => 'Dashboard HPP/COGS', 'path' => '/portal/warehouse/dashboard', 'sort_order' => 10, 'permission_view' => 'cogs.dashboard.view', 'permission_create' => 'cogs.dashboard.create', 'permission_update' => 'cogs.dashboard.update', 'permission_delete' => 'cogs.dashboard.delete'],
            ['portal_code' => 'warehouse', 'code' => 'cogs-uom-conversion', 'name' => 'UOM Conversion', 'path' => '/cogs/uom-conversions', 'sort_order' => 20, 'permission_view' => 'cogs.uom_conversion.view', 'permission_create' => 'cogs.uom_conversion.create', 'permission_update' => 'cogs.uom_conversion.update', 'permission_delete' => 'cogs.uom_conversion.delete'],
            ['portal_code' => 'warehouse', 'code' => 'cogs-ingredient-recipes', 'name' => 'Ingredient / Recipe', 'path' => '/cogs/ingredient-recipes', 'sort_order' => 30, 'permission_view' => 'cogs.ingredient.view', 'permission_create' => 'cogs.ingredient.create', 'permission_update' => 'cogs.ingredient.update', 'permission_delete' => 'cogs.ingredient.delete'],
            ['portal_code' => 'warehouse', 'code' => 'cogs-history-stock', 'name' => 'History Stock', 'path' => '/cogs/history-stock', 'sort_order' => 40, 'permission_view' => 'cogs.history_stock.view', 'permission_create' => 'cogs.history_stock.create', 'permission_update' => 'cogs.history_stock.update', 'permission_delete' => 'cogs.history_stock.delete'],
            ['portal_code' => 'warehouse', 'code' => 'cogs-item-sold', 'name' => 'Item Sold & Recipe Consumption', 'path' => '/cogs/item-sold', 'sort_order' => 50, 'permission_view' => 'cogs.item_sold.view', 'permission_create' => 'cogs.item_sold.create', 'permission_update' => 'cogs.item_sold.update', 'permission_delete' => 'cogs.item_sold.delete'],
            ['portal_code' => 'warehouse', 'code' => 'cogs-stock-variance', 'name' => 'Stock Variance', 'path' => '/cogs/stock-variance', 'sort_order' => 60, 'permission_view' => 'cogs.stock_variance.view', 'permission_create' => 'cogs.stock_variance.create', 'permission_update' => 'cogs.stock_variance.update', 'permission_delete' => 'cogs.stock_variance.delete'],
            ['portal_code' => 'warehouse', 'code' => 'cogs-calculation', 'name' => 'COGS Calculation & Reconciliation', 'path' => '/cogs/calculation', 'sort_order' => 70, 'permission_view' => 'cogs.calculation.view', 'permission_create' => 'cogs.calculation.create', 'permission_update' => 'cogs.calculation.update', 'permission_delete' => 'cogs.calculation.delete'],
            ['portal_code' => 'warehouse', 'code' => 'cogs-reset', 'name' => 'Reset COGS', 'path' => '/cogs/reset', 'sort_order' => 80, 'permission_view' => 'cogs.reset.view', 'permission_create' => 'cogs.reset.create', 'permission_update' => 'cogs.reset.update', 'permission_delete' => 'cogs.reset.delete'],

            ['portal_code' => 'finance', 'code' => 'finance-dashboard', 'name' => 'Dashboard', 'path' => '/portal/finance/dashboard', 'sort_order' => 10],
            ['portal_code' => 'finance', 'code' => 'finance-overview', 'name' => 'Overview Finance', 'path' => '/finance/overview', 'sort_order' => 14, 'permission_view' => 'report.view'],
            ['portal_code' => 'finance', 'code' => 'finance-sales-collected', 'name' => 'Sales Collected', 'path' => '/finance/sales-collected', 'sort_order' => 15, 'permission_view' => 'sale.view'],
            ['portal_code' => 'finance', 'code' => 'finance-sales-summary', 'name' => 'Sales Summary', 'path' => '/finance/sales-summary', 'sort_order' => 16, 'permission_view' => 'sale.view'],
            ['portal_code' => 'finance', 'code' => 'finance-category-summary', 'name' => 'Category Summary', 'path' => '/finance/category-summary', 'sort_order' => 17, 'permission_view' => 'report.view'],
            ['portal_code' => 'finance', 'code' => 'finance-item-summary', 'name' => 'Item Summary', 'path' => '/finance/item-summary', 'sort_order' => 18, 'permission_view' => 'report.view'],
            ['portal_code' => 'finance', 'code' => 'sales-list', 'name' => 'Sales', 'path' => '/sales', 'sort_order' => 20, 'permission_view' => 'sale.view'],
            ['portal_code' => 'finance', 'code' => 'sales-report', 'name' => 'Report', 'path' => '/reports', 'sort_order' => 30, 'permission_view' => 'report.view'],
            ['portal_code' => 'finance', 'code' => 'finance-cashier-report', 'name' => 'Cashier Report', 'path' => '/finance/cashier-report', 'sort_order' => 35, 'permission_view' => 'report.view'],
            ['portal_code' => 'finance', 'code' => 'finance-i08-expense-report', 'name' => 'Expense Report', 'path' => '/finance/expense-report', 'sort_order' => 260, 'permission_view' => 'finance.expense_report.view', 'permission_update' => 'finance.expense_report.post'],
            ['portal_code' => 'finance', 'code' => 'sales-cancel', 'name' => 'Cancel Bill', 'path' => '/cancel-requests', 'sort_order' => 40, 'permission_view' => 'sale.cancel.approve', 'permission_create' => 'sale.cancel.request', 'permission_update' => 'sale.cancel.approve', 'permission_delete' => 'sale.cancel.approve'],
            ['portal_code' => 'bank', 'code' => 'bank-dashboard', 'name' => 'Dashboard', 'path' => '/portal/bank/dashboard', 'sort_order' => 10],
            ['portal_code' => 'purchasing', 'code' => 'purchasing-dashboard', 'name' => 'Dashboard', 'path' => '/portal/purchasing/dashboard', 'sort_order' => 10],

            ['portal_code' => 'owner-overview', 'code' => 'owner-overview-dashboard', 'name' => 'Owner Overview', 'path' => '/owner-overview', 'sort_order' => 10, 'permission_view' => 'dashboard.view'],
            ['portal_code' => 'owner-overview', 'code' => 'owner-overview-detail-sales', 'name' => 'Detail Sales', 'path' => '/owner-overview/detail-sales', 'sort_order' => 20, 'permission_view' => 'sale.view'],

            ['portal_code' => 'omzet-report', 'code' => 'omzet-report-dashboard', 'name' => 'Dashboard', 'path' => '/omzet-report/dashboard', 'sort_order' => 10, 'permission_view' => 'dashboard.view'],
            ['portal_code' => 'omzet-report', 'code' => 'omzet-report-ledger', 'name' => 'Ledger', 'path' => '/omzet-report/ledger', 'sort_order' => 20, 'permission_view' => 'report.view'],
            ['portal_code' => 'omzet-report', 'code' => 'omzet-report-report', 'name' => 'Report', 'path' => '/omzet-report/report', 'sort_order' => 30, 'permission_view' => 'report.view'],

            ['portal_code' => 'sales-report', 'code' => 'sales-report-dashboard', 'name' => 'Dashboard', 'path' => '/sales-report/dashboard', 'sort_order' => 10, 'permission_view' => 'dashboard.view'],
            ['portal_code' => 'sales-report', 'code' => 'sales-report-sales', 'name' => 'Sales', 'path' => '/sales-report/sales', 'sort_order' => 20, 'permission_view' => 'sale.view'],
            ['portal_code' => 'sales-report', 'code' => 'sales-report-report', 'name' => 'Report', 'path' => '/sales-report/report', 'sort_order' => 30, 'permission_view' => 'report.view'],

            ['portal_code' => 'report', 'code' => 'report-overhandle', 'name' => 'Overhandle', 'path' => '/report/overhandle', 'sort_order' => 10, 'permission_view' => 'report.overhandle.view', 'permission_create' => 'report.overhandle.create', 'permission_update' => 'report.overhandle.update', 'permission_delete' => 'report.overhandle.delete'],
            ['portal_code' => 'report', 'code' => 'report-kpi-squad', 'name' => 'KPI Squad', 'path' => '/report/kpi-squad', 'sort_order' => 30, 'permission_view' => 'hr.kpi.squad.view', 'permission_create' => 'hr.kpi.squad.input', 'permission_update' => 'hr.kpi.squad.update', 'permission_delete' => 'hr.kpi.squad.reopen'],
            ['portal_code' => 'report', 'code' => 'report-expense-request', 'name' => 'Petty Cash', 'path' => '/report/expense-request', 'sort_order' => 40, 'permission_view' => 'report.expense_request.view', 'permission_create' => 'report.expense_request.create', 'permission_update' => 'report.expense_request.update', 'permission_delete' => 'report.expense_request.delete'],
            ['portal_code' => 'report', 'code' => 'report-fund-requests', 'name' => 'Pengajuan Dana', 'path' => '/report/fund-requests', 'sort_order' => 50, 'permission_view' => 'purchasing.fund_request.view', 'permission_create' => 'purchasing.fund_request.create', 'permission_update' => 'purchasing.fund_request.update', 'permission_delete' => 'purchasing.fund_request.delete'],

            ['portal_code' => 'console', 'code' => 'console-control-center', 'name' => 'Control Center', 'path' => '/console/control-center', 'sort_order' => 10, 'permission_view' => 'console.control_center.view', 'permission_create' => 'console.control_center.run', 'permission_update' => 'console.control_center.configure', 'permission_delete' => 'console.control_center.force_rebuild'],
            ['portal_code' => 'console', 'code' => 'console-system-health', 'name' => 'System Health', 'path' => '/console/system-health', 'sort_order' => 20, 'permission_view' => 'console.system_health.view'],
            ['portal_code' => 'console', 'code' => 'console-file-management', 'name' => 'File Management', 'path' => '/console/file-management', 'sort_order' => 30, 'permission_view' => 'console.file_management.view', 'permission_create' => 'console.file_management.create_folder', 'permission_update' => 'console.file_management.move', 'permission_delete' => 'console.file_management.delete'],
            ['portal_code' => 'console', 'code' => 'console-maintenance', 'name' => 'Maintenance', 'path' => '/console/maintenance', 'sort_order' => 40, 'permission_view' => 'console.maintenance.view', 'permission_update' => 'console.maintenance.manage'],
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
        $permissions[] = 'pos.provision.view';
        // Keep cancel permissions registered for existing POS/cancel workflows even
        // after the finance portal menu is removed from the canonical catalog.
        $permissions[] = 'sale.cancel.request';
        $permissions[] = 'sale.cancel.view';
        $permissions[] = 'sale.cancel.approve';
        $permissions[] = 'finance.expense_report.view';
        $permissions[] = 'finance.expense_report.post';
        $permissions[] = 'operational.outlet_pin.view';
        $permissions[] = 'operational.outlet_pin.update';
        $permissions[] = 'operational.sales_analytic.daily.view';
        $permissions[] = 'operational.sales_analytic.hourly.view';
        $permissions[] = 'operational.sales_analytic.hourly_summary.view';

        return array_values(array_unique($permissions));
    }
}
