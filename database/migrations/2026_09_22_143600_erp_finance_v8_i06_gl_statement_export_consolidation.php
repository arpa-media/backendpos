<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration {
    public function up(): void
    {
        $this->consolidateFinancialStatementExportAccess();
    }

    public function down(): void
    {
        // Non-destructive: access-matrix and permission changes are retained so saved role profiles remain valid.
    }

    private function consolidateFinancialStatementExportAccess(): void
    {
        $viewPermission = 'finance.financial_statement_export.view';
        $downloadPermission = 'finance.financial_statement_export.download';
        $guard = 'web';

        if (Schema::hasTable('permissions')) {
            Permission::findOrCreate($viewPermission, $guard);
            Permission::findOrCreate($downloadPermission, $guard);

            // Anyone already trusted with the export center keeps download capability after
            // I06 separates View from the Access Matrix Create/Export action.
            Role::query()->where('guard_name', $guard)->get()->each(function (Role $role) use ($viewPermission, $downloadPermission): void {
                try {
                    if ($role->hasPermissionTo($viewPermission)) $role->givePermissionTo($downloadPermission);
                } catch (\Throwable) {
                    // Access Matrix synchronization below remains the source of truth for UI capability.
                }
            });
        }

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;

        $portal = DB::table('access_portals')->where('code', 'finance')->first();
        if (! $portal) return;

        $existing = DB::table('access_menus')->where(function ($query): void {
            $query->where('code', 'finance-financial-statement-export')
                ->orWhere('path', '/finance/financial-statement-export');
        })->first();
        $menuId = (string) ($existing->id ?? Str::ulid());
        $now = now();

        DB::table('access_menus')->updateOrInsert(
            ['code' => 'finance-financial-statement-export'],
            [
                'id' => $menuId,
                'portal_id' => $portal->id,
                'name' => 'Financial Statement Export',
                'path' => '/finance/financial-statement-export',
                'sort_order' => 85,
                'permission_view' => $viewPermission,
                // Finance/Warehouse report convention: Access Matrix Create = Export/Download.
                'permission_create' => $downloadPermission,
                'permission_update' => null,
                'permission_delete' => null,
                'is_active' => true,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ],
        );

        if (! Schema::hasTable('access_role_menu_permissions')) return;

        // Existing viewers did not have a separate download action before I06. Promote only
        // rows that already have can_view=true; explicit hidden/denied rows remain untouched.
        DB::table('access_role_menu_permissions')
            ->where('menu_id', $menuId)
            ->where('can_view', true)
            ->update(['can_create' => true, 'updated_at' => $now]);

        // Make the export center discoverable for Access Matrix profiles that can see at least
        // one Financial Statement but never received the historical export-center row.
        $sourceIds = DB::table('access_menus')
            ->whereIn('path', ['/finance/balance-sheet', '/finance/profit-loss', '/finance/cash-flow'])
            ->pluck('id')
            ->map(fn ($value) => (string) $value)
            ->all();
        if ($sourceIds === []) return;

        $sourceRows = DB::table('access_role_menu_permissions')
            ->whereIn('menu_id', $sourceIds)
            ->where('can_view', true)
            ->get();

        $seen = [];
        foreach ($sourceRows as $source) {
            $levelKey = $source->access_level_id === null ? 'NULL' : (string) $source->access_level_id;
            $key = (string) $source->access_role_id.'|'.$levelKey;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $query = DB::table('access_role_menu_permissions')
                ->where('access_role_id', $source->access_role_id)
                ->where('menu_id', $menuId);
            $source->access_level_id === null
                ? $query->whereNull('access_level_id')
                : $query->where('access_level_id', $source->access_level_id);

            if ($query->exists()) continue;

            DB::table('access_role_menu_permissions')->insert([
                'id' => (string) Str::ulid(),
                'access_role_id' => $source->access_role_id,
                'access_level_id' => $source->access_level_id,
                'menu_id' => $menuId,
                'can_view' => true,
                'can_create' => true,
                'can_edit' => false,
                'can_delete' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
