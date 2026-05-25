<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('access_menus') || ! Schema::hasTable('access_portals')) {
            return;
        }

        $financeCodes = [
            'finance-closing-period',
            'closing-period',
            'finance-balance-sheet-logic',
            'balance-sheet-logic',
            'finance-sales-history',
            'sales-history',
            'sales-list',
            'finance-marking-report',
            'marking-report',
            'sales-report',
            'finance-overhandle-report',
            'overhandle-report',
            'finance-expense-report',
            'finance-expense-reports',
            'expense-report',
            'expense-reports',
            'finance-spv-cashier-report',
            'spv-cashier-report',
            'finance-cashier-report',
            'finance-kpi-squad',
            'kpi-squad',
            'finance-spv-cancel-bill',
            'spv-cancel-bill',
            'sales-cancel',
            'cancel-bill',
        ];

        $financePaths = [
            '/finance/closing-period',
            '/closing-period',
            '/finance/balance-sheet-logic',
            '/balance-sheet-logic',
            '/finance/sales-history',
            '/sales-history',
            '/sales',
            '/finance/marking-report',
            '/marking-report',
            '/reports/marking-settings',
            '/reports',
            '/finance/overhandle-report',
            '/finance/overhandle',
            '/overhandle-report',
            '/finance/expense-report',
            '/finance/expense-reports',
            '/expense-report',
            '/finance/spv/cashier-report',
            '/finance/cashier-report',
            '/finance/kpi-squad',
            '/kpi-squad',
            '/finance/spv/cancel-bill',
            '/finance/cancel-bill',
            '/cancel-requests',
            '/cancel-bill',
        ];

        $financeNames = [
            'closing period',
            'balance sheet logic',
            'sales history',
            'marking report',
            'overhandle report',
            'expense report',
            'cashier report',
            'kpi squad',
            'cancel bill',
        ];

        $hrCodes = [
            'hr-data-master',
            'human-resource-data-master',
            'data-master',
            'master-data',
            'hr-master-data',
            'human-resource-master-data',
            'hr-data-squad',
            'human-resource-data-squad',
            'data-squad',
            'squads',
            'hr-squads',
            'human-resource-squads',
        ];

        $hrPaths = [
            '/portal/human-resource/data-master',
            '/portal/human-resource/master-data',
            '/human-resource/data-master',
            '/human-resource/master-data',
            '/data-master',
            '/master-data',
            '/portal/human-resource/data-squad',
            '/portal/human-resource/squads',
            '/human-resource/data-squad',
            '/human-resource/squads',
            '/data-squad',
            '/squads',
        ];

        $hrNames = [
            'data master',
            'master data',
            'data squad',
        ];

        $menuIds = collect();

        $menuIds = $menuIds->merge($this->matchingMenuIds('finance', $financeCodes, $financePaths, $financeNames));
        $menuIds = $menuIds->merge($this->matchingMenuIds('human-resource', $hrCodes, $hrPaths, $hrNames));

        $menuIds = $menuIds->filter()->unique()->values();
        if ($menuIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('access_role_menu_permissions')) {
            DB::table('access_role_menu_permissions')->whereIn('menu_id', $menuIds->all())->delete();
        }

        DB::table('access_menus')->whereIn('id', $menuIds->all())->delete();
    }

    public function down(): void
    {
        // Menus are intentionally not recreated here because this cleanup is a
        // product decision and the previous rows may have outlet/role-specific
        // permission customizations. Restore from backup if rollback is needed.
    }

    private function matchingMenuIds(string $portalCode, array $codes, array $paths, array $names): \Illuminate\Support\Collection
    {
        $normalizedPaths = array_values(array_unique(array_map(fn ($path) => strtolower(trim((string) $path)), $paths)));
        $normalizedNames = array_values(array_unique(array_map(fn ($name) => strtolower(trim((string) $name)), $names)));

        return DB::table('access_menus')
            ->join('access_portals', 'access_portals.id', '=', 'access_menus.portal_id')
            ->whereRaw('lower(access_portals.code) = ?', [strtolower($portalCode)])
            ->where(function ($query) use ($codes, $normalizedPaths, $normalizedNames) {
                $query->whereIn(DB::raw('lower(access_menus.code)'), array_map('strtolower', $codes))
                    ->orWhereIn(DB::raw('lower(access_menus.path)'), $normalizedPaths)
                    ->orWhereIn(DB::raw('lower(access_menus.name)'), $normalizedNames);
            })
            ->pluck('access_menus.id');
    }
};
