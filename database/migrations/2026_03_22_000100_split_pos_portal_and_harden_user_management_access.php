<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('access_portals') || !Schema::hasTable('access_menus')) {
            return;
        }

        $now = now();

        $salesPortalId = DB::table('access_portals')->where('code', 'sales')->value('id');
        if (!$salesPortalId) {
            return;
        }

        $posPortalId = DB::table('access_portals')->where('code', 'pos')->value('id');
        if ($posPortalId) {
            DB::table('access_portals')
                ->where('id', $posPortalId)
                ->update([
                    'name' => 'POS',
                    'description' => 'Portal POS',
                    'sort_order' => 15,
                    'is_active' => 1,
                    'updated_at' => $now,
                ]);
        } else {
            $posPortalId = (string) Str::ulid();
            DB::table('access_portals')->insert([
                'id' => $posPortalId,
                'code' => 'pos',
                'name' => 'POS',
                'description' => 'Portal POS',
                'sort_order' => 15,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('access_portals')
            ->where('id', $salesPortalId)
            ->update([
                'name' => 'POS Outlet',
                'description' => 'Portal POS Outlet',
                'sort_order' => 10,
                'is_active' => 1,
                'updated_at' => $now,
            ]);

        $menuSeeds = [
            [
                'codes' => ['sales-pos', 'sales-pos-terminal'],
                'name' => 'Point of Sales',
                'path' => '/c/pos',
                'sort_order' => 10,
                'permission_view' => 'pos.checkout',
            ],
            [
                'codes' => ['sales-cashier-report', 'cashier-report'],
                'name' => 'Cashier Report',
                'path' => '/c/cashier-report',
                'sort_order' => 20,
                'permission_view' => 'report.view',
            ],
            [
                'codes' => ['sales-printer-settings', 'sales-printer'],
                'name' => 'Printer Settings',
                'path' => '/c/printer',
                'sort_order' => 30,
                'permission_view' => 'pos.checkout',
            ],
            [
                'codes' => ['sales-receipt-print'],
                'name' => 'Receipt Print',
                'path' => '/receipts/:id/print',
                'sort_order' => 40,
                'permission_view' => 'sale.view',
            ],
            [
                'codes' => ['sales-kitchen-print'],
                'name' => 'Kitchen Print',
                'path' => '/kitchen/:id/print',
                'sort_order' => 41,
                'permission_view' => 'sale.view',
            ],
            [
                'codes' => ['sales-bar-print'],
                'name' => 'Bar Print',
                'path' => '/bar/:id/print',
                'sort_order' => 42,
                'permission_view' => 'sale.view',
            ],
            [
                'codes' => ['sales-table-print'],
                'name' => 'Table Print',
                'path' => '/table/:id/print',
                'sort_order' => 43,
                'permission_view' => 'sale.view',
            ],
            [
                'codes' => ['sales-pizza-print'],
                'name' => 'Pizza Print',
                'path' => '/pizza/:id/print',
                'sort_order' => 44,
                'permission_view' => 'sale.view',
            ],
            [
                'codes' => ['sales-cashier-report-print', 'cashier-report-print'],
                'name' => 'Cashier Report Print',
                'path' => '/cashier-report/print',
                'sort_order' => 45,
                'permission_view' => 'report.view',
            ],
        ];

        foreach ($menuSeeds as $seed) {
            $menu = DB::table('access_menus')
                ->whereIn('code', $seed['codes'])
                ->orWhere('path', $seed['path'])
                ->orderByRaw("case when code = ? then 0 else 1 end", [$seed['codes'][0]])
                ->first();

            if ($menu) {
                DB::table('access_menus')
                    ->where('id', $menu->id)
                    ->update([
                        'portal_id' => $posPortalId,
                        'code' => $seed['codes'][0],
                        'name' => $seed['name'],
                        'path' => $seed['path'],
                        'sort_order' => $seed['sort_order'],
                        'permission_view' => $seed['permission_view'],
                        'is_active' => 1,
                        'updated_at' => $now,
                    ]);

                DB::table('access_menus')
                    ->where('id', '!=', $menu->id)
                    ->whereIn('code', $seed['codes'])
                    ->delete();

                continue;
            }

            DB::table('access_menus')->insert([
                'id' => (string) Str::ulid(),
                'portal_id' => $posPortalId,
                'code' => $seed['codes'][0],
                'name' => $seed['name'],
                'path' => $seed['path'],
                'sort_order' => $seed['sort_order'],
                'permission_view' => $seed['permission_view'],
                'permission_create' => null,
                'permission_update' => null,
                'permission_delete' => null,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (Schema::hasTable('access_role_portal_permissions')) {
            $sourceRows = DB::table('access_role_portal_permissions')
                ->where('portal_id', $salesPortalId)
                ->get();

            foreach ($sourceRows as $row) {
                $existingId = DB::table('access_role_portal_permissions')
                    ->where('access_role_id', $row->access_role_id)
                    ->where(function ($query) use ($row) {
                        if ($row->access_level_id) {
                            $query->where('access_level_id', $row->access_level_id);
                        } else {
                            $query->whereNull('access_level_id');
                        }
                    })
                    ->where('portal_id', $posPortalId)
                    ->value('id');

                if ($existingId) {
                    DB::table('access_role_portal_permissions')
                        ->where('id', $existingId)
                        ->update([
                            'can_view' => (bool) $row->can_view,
                            'updated_at' => $now,
                        ]);
                    continue;
                }

                DB::table('access_role_portal_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $row->access_role_id,
                    'access_level_id' => $row->access_level_id,
                    'portal_id' => $posPortalId,
                    'can_view' => (bool) $row->can_view,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('access_portals') || !Schema::hasTable('access_menus')) {
            return;
        }

        $now = now();
        $salesPortalId = DB::table('access_portals')->where('code', 'sales')->value('id');
        $posPortalId = DB::table('access_portals')->where('code', 'pos')->value('id');

        if ($salesPortalId && $posPortalId) {
            DB::table('access_menus')
                ->whereIn('code', [
                    'sales-pos',
                    'sales-pos-terminal',
                    'sales-cashier-report',
                    'cashier-report',
                    'sales-printer-settings',
                    'sales-printer',
                    'sales-receipt-print',
                    'sales-kitchen-print',
                    'sales-bar-print',
                    'sales-table-print',
                    'sales-pizza-print',
                    'sales-cashier-report-print',
                    'cashier-report-print',
                ])
                ->update([
                    'portal_id' => $salesPortalId,
                    'updated_at' => $now,
                ]);

            DB::table('access_role_portal_permissions')
                ->where('portal_id', $posPortalId)
                ->delete();

            DB::table('access_portals')
                ->where('id', $posPortalId)
                ->delete();
        }
    }
};
