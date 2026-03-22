<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AuthSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = 'web';

        $roleDefinitions = [
            'admin' => ['*'],
            'manager' => [
                'auth.me',
                'dashboard.view',
                'outlet.view', 'outlet.update',
                'category.view', 'category.create', 'category.update', 'category.delete',
                'product.view', 'product.create', 'product.update', 'product.delete',
                'payment_method.view', 'payment_method.create', 'payment_method.update', 'payment_method.delete',
                'discount.view', 'discount.create', 'discount.update', 'discount.delete',
                'taxes.view', 'taxes.create', 'taxes.update', 'taxes.delete',
                'pos.checkout', 'pos.offline_sync.view', 'sale.view',
                'sale.cancel.request', 'sale.cancel.approve',
                'customer.view', 'customer.create',
                'report.view',
                'user_management.view', 'user_management.edit',
            ],
            'cashier' => [
                'auth.me',
                'dashboard.view',
                'outlet.view',
                'category.view',
                'product.view',
                'payment_method.view',
                'discount.view',
                'pos.checkout',
                'pos.offline_sync.view',
                'sale.view',
                'sale.cancel.request',
                'customer.view', 'customer.create',
                'report.view',
            ],
            'warehouse' => [
                'auth.me',
                'dashboard.view',
                'outlet.view',
                'category.view',
                'product.view',
                'sale.view',
                'report.view',
            ],
            'squad' => [
                'auth.me',
                'dashboard.view',
                'outlet.view',
                'category.view',
                'product.view',
                'payment_method.view',
                'discount.view',
                'pos.checkout',
                'pos.offline_sync.view',
                'sale.view',
                'sale.cancel.request',
                'customer.view', 'customer.create',
                'report.view',
            ],
        ];

        $permissions = [
            'auth.me',
            'admin.access',
            'dashboard.view',
            'outlet.view',
            'outlet.update',
            'category.view',
            'category.create',
            'category.update',
            'category.delete',
            'product.view',
            'product.create',
            'product.update',
            'product.delete',
            'pos.checkout',
            'pos.offline_sync.view',
            'sale.view',
            'sale.cancel.request',
            'sale.cancel.approve',
            'report.view',
            'user_management.view',
            'user_management.edit',
            'customer.view',
            'customer.create',
            'payment_method.view',
            'payment_method.create',
            'payment_method.update',
            'payment_method.delete',
            'discount.view',
            'discount.create',
            'discount.update',
            'discount.delete',
            'taxes.view',
            'taxes.create',
            'taxes.update',
            'taxes.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => $guard,
            ]);
        }

        foreach ($roleDefinitions as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => $guard]);

            if ($rolePermissions === ['*']) {
                $role->syncPermissions(Permission::query()->where('guard_name', $guard)->pluck('name')->all());
                continue;
            }

            $role->syncPermissions($rolePermissions);
        }
    }
}
