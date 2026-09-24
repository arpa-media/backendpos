<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const CASH_BANK_MENUS = [
        ['code' => 'finance-i08-book-transfer', 'name' => 'Book Transfer', 'path' => '/finance/book-transfer', 'sort' => 210],
        ['code' => 'finance-i08-cash-in', 'name' => 'Cash In', 'path' => '/finance/cash-in', 'sort' => 220],
        ['code' => 'finance-i08-cash-out', 'name' => 'Cash Out', 'path' => '/finance/cash-out', 'sort' => 230],
        ['code' => 'finance-i08-bank-in', 'name' => 'Bank In', 'path' => '/finance/bank-in', 'sort' => 240],
        ['code' => 'finance-i08-bank-out', 'name' => 'Bank Out', 'path' => '/finance/bank-out', 'sort' => 250],
        ['code' => 'finance-i08-expense-report', 'name' => 'Expense Report', 'path' => '/finance/expense-report', 'sort' => 260],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;

        $financeId = (string) DB::table('access_portals')->where('code', 'finance')->value('id');
        if ($financeId === '') return;

        $now = now();

        // Navigation ownership only. Canonical Purchasing endpoints, IDs and
        // permissions remain unchanged, including all historical documents.
        DB::table('access_menus')
            ->whereIn('code', ['purchasing-account-payables', 'purchasing-account-receivables'])
            ->update(['portal_id' => $financeId, 'updated_at' => $now]);

        $dashboardId = DB::table('access_menus')->where('code', 'finance-dashboard')->value('id');
        foreach (self::CASH_BANK_MENUS as $definition) {
            $existing = DB::table('access_menus')->where('code', $definition['code'])->first();
            $menuId = (string) ($existing->id ?? Str::ulid());
            DB::table('access_menus')->updateOrInsert(
                ['code' => $definition['code']],
                [
                    'id' => $menuId,
                    'portal_id' => $financeId,
                    'name' => $definition['name'],
                    'path' => $definition['path'],
                    'sort_order' => $definition['sort'],
                    'permission_view' => null,
                    'permission_create' => null,
                    'permission_update' => null,
                    'permission_delete' => null,
                    'is_active' => true,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ]
            );
            $this->cloneDashboardMatrix((string) $dashboardId, $menuId, $now);
        }
    }

    private function cloneDashboardMatrix(string $sourceMenuId, string $targetMenuId, $now): void
    {
        if ($sourceMenuId === '' || ! Schema::hasTable('access_role_menu_permissions')) return;

        $rows = DB::table('access_role_menu_permissions')->where('menu_id', $sourceMenuId)->get();
        foreach ($rows as $row) {
            $query = DB::table('access_role_menu_permissions')
                ->where('access_role_id', $row->access_role_id)
                ->where('menu_id', $targetMenuId);
            $row->access_level_id === null
                ? $query->whereNull('access_level_id')
                : $query->where('access_level_id', $row->access_level_id);
            if ($query->exists()) continue;

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
        if (! Schema::hasTable('access_menus')) return;

        if (Schema::hasTable('access_portals')) {
            $purchasingId = DB::table('access_portals')->where('code', 'purchasing')->value('id');
            if ($purchasingId) {
                DB::table('access_menus')
                    ->whereIn('code', ['purchasing-account-payables', 'purchasing-account-receivables'])
                    ->update(['portal_id' => $purchasingId, 'updated_at' => now()]);
            }
        }

        $codes = array_column(self::CASH_BANK_MENUS, 'code');
        $ids = DB::table('access_menus')->whereIn('code', $codes)->pluck('id');
        if (Schema::hasTable('access_role_menu_permissions') && $ids->isNotEmpty()) {
            DB::table('access_role_menu_permissions')->whereIn('menu_id', $ids)->delete();
        }
        DB::table('access_menus')->whereIn('code', $codes)->delete();
    }
};
