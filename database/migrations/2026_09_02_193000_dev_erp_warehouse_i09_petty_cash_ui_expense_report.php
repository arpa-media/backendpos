<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('wh_i08_petty_cash') || !Schema::hasTable('wh_i08_petty_cash_items')) {
            throw new RuntimeException('Warehouse I09 membutuhkan migration Petty Cash I08 terlebih dahulu.');
        }

        $guard = config('auth.defaults.guard', 'web');
        foreach ([
            'warehouse.purchasing.petty_cash.view',
            'warehouse.purchasing.petty_cash.create',
            'warehouse.purchasing.petty_cash.update',
            'warehouse.purchasing.petty_cash.delete',
            'warehouse.purchasing.petty_cash.approve',
            'warehouse.purchasing.petty_cash.receive',
            'warehouse.finance.expense_report.view',
            'warehouse.finance.expense_report.export',
        ] as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        $this->registerMenus();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Additive/access-only migration. Keep rows for audit and existing role assignments.
    }

    private function registerMenus(): void
    {
        if (!Schema::hasTable('access_menus') || !Schema::hasTable('access_portals')) return;
        $portal = DB::table('access_portals')->where('code', 'warehouse-operations')->first();
        if (!$portal) return;

        $now = now();
        $this->upsertMenu($portal->id, [
            'code' => 'warehouse-petty-cash',
            'name' => 'Petty Cash',
            'path' => '/warehouse/purchasing/petty-cash',
            'sort_order' => 458,
            'permission_view' => 'warehouse.purchasing.petty_cash.view',
            'permission_create' => 'warehouse.purchasing.petty_cash.create',
            'permission_update' => 'warehouse.purchasing.petty_cash.update',
            // Access Matrix only has 4 actions in this codebase. Delete is the approval authority for Petty Cash.
            'permission_delete' => 'warehouse.purchasing.petty_cash.approve',
        ], $now);

        $this->upsertMenu($portal->id, [
            'code' => 'warehouse-expense-report',
            'name' => 'Expense Report',
            'path' => '/warehouse/finance/expense-report',
            'sort_order' => 785,
            'permission_view' => 'warehouse.finance.expense_report.view',
            'permission_create' => null,
            'permission_update' => null,
            // Delete column is repurposed as Export authority because the legacy matrix has no export column.
            'permission_delete' => 'warehouse.finance.expense_report.export',
        ], $now);
    }

    private function upsertMenu(string $portalId, array $row, $now): void
    {
        $old = DB::table('access_menus')->where('code', $row['code'])->first();
        DB::table('access_menus')->updateOrInsert(
            ['code' => $row['code']],
            [
                'id' => $old->id ?? (string)Str::ulid(),
                'portal_id' => $portalId,
                'name' => $row['name'],
                'path' => $row['path'],
                'sort_order' => $row['sort_order'],
                'permission_view' => $row['permission_view'],
                'permission_create' => $row['permission_create'],
                'permission_update' => $row['permission_update'],
                'permission_delete' => $row['permission_delete'],
                'is_active' => true,
                'created_at' => $old->created_at ?? $now,
                'updated_at' => $now,
            ]
        );
    }
};
