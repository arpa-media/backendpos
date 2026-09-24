<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            foreach ([
                'warehouse.transfer.import',
                'warehouse.transfer.submit',
                'warehouse.transfer.assign',
                'warehouse.sales.customer.submit',
                'warehouse.sales.customer.approve',
                'warehouse.sales.customer.ready',
            ] as $permission) {
                Permission::findOrCreate($permission, $guard);
            }
        }

        if (Schema::hasTable('access_menus')) {
            $this->guardMenu('/warehouse/transfers', 'Transfer Stock', [
                'permission_view' => 'warehouse.transfer.view',
                'permission_create' => 'warehouse.transfer.create',
                'permission_update' => 'warehouse.transfer.update',
                'permission_delete' => 'warehouse.transfer.delete',
            ]);
            $this->guardMenu('/warehouse/sales/customer', 'Sales Order Customer', [
                'permission_view' => 'warehouse.sales.customer.view',
                'permission_create' => 'warehouse.sales.customer.create',
                'permission_update' => 'warehouse.sales.customer.update',
                'permission_delete' => 'warehouse.sales.customer.cancel',
            ]);
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function guardMenu(string $path, string $name, array $permissions): void
    {
        $query = DB::table('access_menus')->where('path', $path);
        if (! $query->exists()) return;
        $payload = [];
        if (Schema::hasColumn('access_menus', 'name')) $payload['name'] = $name;
        foreach ($permissions as $column => $value) {
            if (Schema::hasColumn('access_menus', $column)) $payload[$column] = $value;
        }
        if (Schema::hasColumn('access_menus', 'is_active')) $payload['is_active'] = true;
        if (Schema::hasColumn('access_menus', 'updated_at')) $payload['updated_at'] = now();
        if ($payload !== []) $query->update($payload);
    }

    public function down(): void
    {
        // Non-destructive: Access Matrix grants and operational documents are retained.
    }
};
