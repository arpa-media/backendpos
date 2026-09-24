<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('access_menus')) return;

        $guard = config('auth.defaults.guard', 'web');
        $contracts = [
            '/warehouse/production/opname' => [
                'permission_update' => 'warehouse.production.opname.reopen.request',
                'permission_delete' => 'warehouse.production.opname.reopen.approve',
            ],
            '/warehouse/production/orders' => [
                'permission_delete' => 'warehouse.production.result.delete',
            ],
            '/warehouse/production/cogs' => [
                'permission_view' => 'warehouse.production.cost.view',
                'permission_create' => 'warehouse.production.cogs.reconcile',
                'permission_update' => 'warehouse.production.cogs.post',
            ],
            '/warehouse/purchasing/petty-cash' => [
                'permission_view' => 'warehouse.purchasing.petty_cash.view',
                'permission_create' => 'warehouse.purchasing.petty_cash.create',
                'permission_update' => 'warehouse.purchasing.petty_cash.update',
                'permission_delete' => 'warehouse.purchasing.petty_cash.approve',
            ],
            '/warehouse/finance/expense-report' => [
                'permission_view' => 'warehouse.finance.expense_report.view',
                'permission_delete' => 'warehouse.finance.expense_report.export',
            ],
        ];

        $permissions = [];
        foreach ($contracts as $path => $mapping) {
            $exists = DB::table('access_menus')->where('path', $path)->exists();
            if (!$exists) continue; // I10 remains safe when an optional earlier iteration is absent.

            $payload = ['is_active' => true, 'updated_at' => now()];
            foreach ($mapping as $column => $permission) {
                if (!Schema::hasColumn('access_menus', $column)) continue;
                $payload[$column] = $permission;
                if ($permission) $permissions[$permission] = true;
            }
            DB::table('access_menus')->where('path', $path)->update($payload);
        }

        foreach (array_keys($permissions) as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        if ($permissions) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Audit/hardening migration is intentionally non-destructive.
    }
};
