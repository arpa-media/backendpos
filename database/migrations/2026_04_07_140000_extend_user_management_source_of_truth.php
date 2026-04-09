<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('access_portals') || ! DB::getSchemaBuilder()->hasTable('access_menus')) {
            return;
        }

        $portals = [
            ['code' => 'owner-overview', 'name' => 'Owner Overview', 'description' => 'Portal owner overview', 'sort_order' => 100],
            ['code' => 'omzet-report', 'name' => 'Omzet Report', 'description' => 'Portal report omzet seluruh transaksi POS.', 'sort_order' => 110],
            ['code' => 'sales-report', 'name' => 'Sales Report', 'description' => 'Portal report transaksi dengan marking 1.', 'sort_order' => 120],
        ];

        foreach ($portals as $portal) {
            DB::table('access_portals')->updateOrInsert(
                ['code' => $portal['code']],
                [
                    'id' => DB::table('access_portals')->where('code', $portal['code'])->value('id') ?: (string) Str::ulid(),
                    'name' => $portal['name'],
                    'description' => $portal['description'],
                    'sort_order' => $portal['sort_order'],
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => DB::table('access_portals')->where('code', $portal['code'])->value('created_at') ?: now(),
                ]
            );
        }

        $portalIds = DB::table('access_portals')->pluck('id', 'code');
        $menus = [
            ['portal_code' => 'owner-overview', 'code' => 'owner-overview-dashboard', 'name' => 'Owner Overview', 'path' => '/owner-overview', 'sort_order' => 10, 'permission_view' => 'dashboard.view'],
            ['portal_code' => 'owner-overview', 'code' => 'owner-overview-detail-sales', 'name' => 'Detail Sales', 'path' => '/owner-overview/detail-sales', 'sort_order' => 20, 'permission_view' => 'sale.view'],
            ['portal_code' => 'omzet-report', 'code' => 'omzet-report-dashboard', 'name' => 'Dashboard', 'path' => '/omzet-report/dashboard', 'sort_order' => 10, 'permission_view' => 'dashboard.view'],
            ['portal_code' => 'omzet-report', 'code' => 'omzet-report-ledger', 'name' => 'Ledger', 'path' => '/omzet-report/ledger', 'sort_order' => 20, 'permission_view' => 'report.view'],
            ['portal_code' => 'omzet-report', 'code' => 'omzet-report-report', 'name' => 'Report', 'path' => '/omzet-report/report', 'sort_order' => 30, 'permission_view' => 'report.view'],
            ['portal_code' => 'sales-report', 'code' => 'sales-report-dashboard', 'name' => 'Dashboard', 'path' => '/sales-report/dashboard', 'sort_order' => 10, 'permission_view' => 'dashboard.view'],
            ['portal_code' => 'sales-report', 'code' => 'sales-report-sales', 'name' => 'Sales', 'path' => '/sales-report/sales', 'sort_order' => 20, 'permission_view' => 'sale.view'],
            ['portal_code' => 'sales-report', 'code' => 'sales-report-report', 'name' => 'Report', 'path' => '/sales-report/report', 'sort_order' => 30, 'permission_view' => 'report.view'],
        ];

        foreach ($menus as $menu) {
            $portalId = $portalIds[$menu['portal_code']] ?? null;
            if (! $portalId) {
                continue;
            }

            DB::table('access_menus')->updateOrInsert(
                ['code' => $menu['code']],
                [
                    'id' => DB::table('access_menus')->where('code', $menu['code'])->value('id') ?: (string) Str::ulid(),
                    'portal_id' => $portalId,
                    'name' => $menu['name'],
                    'path' => $menu['path'],
                    'sort_order' => $menu['sort_order'],
                    'permission_view' => $menu['permission_view'] ?? null,
                    'permission_create' => $menu['permission_create'] ?? null,
                    'permission_update' => $menu['permission_update'] ?? null,
                    'permission_delete' => $menu['permission_delete'] ?? null,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => DB::table('access_menus')->where('code', $menu['code'])->value('created_at') ?: now(),
                ]
            );
        }
    }

    public function down(): void
    {
        // keep seeded access catalog rows for safety
    }
};
