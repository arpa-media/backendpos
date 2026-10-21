<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * I02: Non-destructive catalog reconciliation for the two already-implemented Warehouse pages.
 * Access grants are intentionally NOT modified; role/user authorization stays in Access Matrix.
 * No production, stock, purchasing, or accounting records are changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('access_portals') || !Schema::hasTable('access_menus')) {
            return;
        }

        $portal = DB::table('access_portals')->where('code', 'warehouse-operations')->first();
        if (!$portal) {
            return;
        }

        $catalog = [
            [
                'code' => 'warehouse-logistics-stock-in-v13',
                'name' => 'Stock In Supplier',
                'path' => '/warehouse/logistics/stock-in',
                'sort_order' => 595,
                'permission_view' => 'warehouse.procurement.order.view',
                'permission_create' => 'warehouse.procurement.order.update',
                'permission_update' => 'warehouse.stock_in.approve',
                'permission_delete' => 'warehouse.stock_in.approve',
            ],
            [
                'code' => 'warehouse-production-stock-v13',
                'name' => 'Production Stock',
                'path' => '/warehouse/production/stock',
                'sort_order' => 548,
                'permission_view' => 'warehouse.production.view',
                'permission_create' => 'warehouse.production.create',
                'permission_update' => 'warehouse.production.update',
                'permission_delete' => 'warehouse.production.update',
            ],
        ];

        foreach ($catalog as $definition) {
            // Existing I17/I21 catalog/grants take priority; never create duplicate rows.
            $existing = DB::table('access_menus')->where('code', $definition['code'])->first();
            if (!$existing) {
                $existing = DB::table('access_menus')
                    ->where('portal_id', $portal->id)
                    ->where('path', $definition['path'])->first();
            }

            if ($existing) {
                // Only reconcile the display name. Existing grants, active status, permission
                // mappings, and user-specific configuration remain untouched.
                if ($existing->name !== $definition['name']) {
                    DB::table('access_menus')->where('id', $existing->id)->update([
                        'name' => $definition['name'], 'updated_at' => now(),
                    ]);
                }
                continue;
            }

            DB::table('access_menus')->insert(array_merge($definition, [
                'id' => (string) Str::ulid(),
                'portal_id' => $portal->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            // Fail-closed: new menu rows do not automatically grant any role access.
            // Administrator can authorize users in User Management > Access Matrix.
        }
    }

    public function down(): void
    {
        // Non-destructive: do not delete a menu and cascade existing access grants.
        // Restoring a DB backup is the safe rollback when catalog changes are unwanted.
    }
};
