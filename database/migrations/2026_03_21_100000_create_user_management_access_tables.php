<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('access_user_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('access_roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_type_id')->nullable()->constrained('access_user_types')->nullOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('spatie_role_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('access_levels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('access_portals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('access_menus', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('portal_id')->constrained('access_portals')->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('path');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('permission_view')->nullable();
            $table->string('permission_create')->nullable();
            $table->string('permission_update')->nullable();
            $table->string('permission_delete')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_access_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('access_role_id')->constrained('access_roles')->cascadeOnDelete();
            $table->foreignUlid('access_level_id')->nullable()->constrained('access_levels')->nullOnDelete();
            $table->foreignUlid('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('access_role_portal_permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('access_role_id')->constrained('access_roles')->cascadeOnDelete();
            $table->foreignUlid('access_level_id')->nullable()->constrained('access_levels')->nullOnDelete();
            $table->foreignUlid('portal_id')->constrained('access_portals')->cascadeOnDelete();
            $table->boolean('can_view')->default(false);
            $table->timestamps();
            $table->unique(['access_role_id', 'access_level_id', 'portal_id'], 'access_role_portal_unique');
        });

        Schema::create('access_role_menu_permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('access_role_id')->constrained('access_roles')->cascadeOnDelete();
            $table->foreignUlid('access_level_id')->nullable()->constrained('access_levels')->nullOnDelete();
            $table->foreignUlid('menu_id')->constrained('access_menus')->cascadeOnDelete();
            $table->boolean('can_view')->default(false);
            $table->boolean('can_create')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->timestamps();
            $table->unique(['access_role_id', 'access_level_id', 'menu_id'], 'access_role_menu_unique');
        });

        $now = now();
        $userTypes = [
            ['code' => 'BACKOFFICE', 'name' => 'Backoffice', 'description' => 'Portal backoffice'],
            ['code' => 'POS', 'name' => 'POS', 'description' => 'Session POS'],
        ];
        DB::table('access_user_types')->insert(array_map(fn ($row) => [
            'id' => (string) Str::ulid(), 'code' => $row['code'], 'name' => $row['name'], 'description' => $row['description'], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ], $userTypes));

        $typeIds = DB::table('access_user_types')->pluck('id', 'code');
        $roles = [
            ['code' => 'ADMIN', 'name' => 'Administrator', 'description' => 'Administrator', 'user_type_code' => 'BACKOFFICE', 'spatie_role_name' => 'admin'],
            ['code' => 'MANAGER', 'name' => 'Manager', 'description' => 'Manager', 'user_type_code' => 'BACKOFFICE', 'spatie_role_name' => 'manager'],
            ['code' => 'WAREHOUSE', 'name' => 'Warehouse', 'description' => 'Warehouse', 'user_type_code' => 'BACKOFFICE', 'spatie_role_name' => 'warehouse'],
            ['code' => 'CASHIER', 'name' => 'Cashier', 'description' => 'Cashier', 'user_type_code' => 'POS', 'spatie_role_name' => 'cashier'],
        ];
        DB::table('access_roles')->insert(array_map(fn ($row) => [
            'id' => (string) Str::ulid(), 'user_type_id' => $typeIds[$row['user_type_code']] ?? null, 'code' => $row['code'], 'name' => $row['name'], 'description' => $row['description'], 'spatie_role_name' => $row['spatie_role_name'], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ], $roles));

        DB::table('access_levels')->insert([
            ['id' => (string) Str::ulid(), 'code' => 'HQ', 'name' => 'Head Office', 'description' => 'Head Office', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => (string) Str::ulid(), 'code' => 'OUTLET', 'name' => 'Outlet', 'description' => 'Outlet', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $portals = [
            ['code' => 'sales', 'name' => 'POS Outlet', 'sort_order' => 10],
            ['code' => 'human-resource', 'name' => 'Human Resource', 'sort_order' => 20],
            ['code' => 'finance', 'name' => 'Finance', 'sort_order' => 30],
            ['code' => 'bank', 'name' => 'Bank Settlement', 'sort_order' => 40],
            ['code' => 'inventory', 'name' => 'Stock Inventory', 'sort_order' => 50],
            ['code' => 'purchasing', 'name' => 'Purchasing', 'sort_order' => 60],
            ['code' => 'customer', 'name' => 'Customer', 'sort_order' => 70],
            ['code' => 'operational', 'name' => 'Chambers Operational', 'sort_order' => 80],
            ['code' => 'warehouse', 'name' => 'HPP/COGS', 'sort_order' => 90],
        ];
        DB::table('access_portals')->insert(array_map(fn ($row) => [
            'id' => (string) Str::ulid(), 'code' => $row['code'], 'name' => $row['name'], 'description' => $row['name'], 'sort_order' => $row['sort_order'], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ], $portals));

        $portalIds = DB::table('access_portals')->pluck('id', 'code');
        $menus = [
            ['portal' => 'sales', 'code' => 'sales-dashboard', 'name' => 'Dashboard', 'path' => '/dashboard', 'sort' => 10, 'view' => 'dashboard.view'],
            ['portal' => 'sales', 'code' => 'sales-list', 'name' => 'Sales', 'path' => '/sales', 'sort' => 20, 'view' => 'sale.view'],
            ['portal' => 'sales', 'code' => 'sales-report', 'name' => 'Report', 'path' => '/reports', 'sort' => 30, 'view' => 'report.view'],
            ['portal' => 'sales', 'code' => 'sales-cancel', 'name' => 'Cancel Bill', 'path' => '/cancel-requests', 'sort' => 40, 'view' => 'sale.cancel.approve'],
            ['portal' => 'sales', 'code' => 'sales-category', 'name' => 'Categories', 'path' => '/categories', 'sort' => 50, 'view' => 'category.view', 'create' => 'category.create', 'update' => 'category.update', 'delete' => 'category.delete'],
            ['portal' => 'sales', 'code' => 'sales-product', 'name' => 'Product', 'path' => '/products', 'sort' => 60, 'view' => 'product.view', 'create' => 'product.create', 'update' => 'product.update', 'delete' => 'product.delete'],
            ['portal' => 'sales', 'code' => 'sales-payment-method', 'name' => 'Payment Method', 'path' => '/payment-methods', 'sort' => 70, 'view' => 'payment_method.view', 'create' => 'payment_method.create', 'update' => 'payment_method.update', 'delete' => 'payment_method.delete'],
            ['portal' => 'sales', 'code' => 'sales-discount', 'name' => 'Discount', 'path' => '/discounts', 'sort' => 80, 'view' => 'discount.view', 'create' => 'discount.create', 'update' => 'discount.update', 'delete' => 'discount.delete'],
            ['portal' => 'sales', 'code' => 'sales-taxes', 'name' => 'Taxes', 'path' => '/taxes', 'sort' => 90, 'view' => 'taxes.view', 'create' => 'taxes.create', 'update' => 'taxes.update', 'delete' => 'taxes.delete'],
            ['portal' => 'sales', 'code' => 'sales-outlet', 'name' => 'Outlet', 'path' => '/settings/outlet', 'sort' => 100, 'view' => 'outlet.view', 'update' => 'outlet.update'],
            ['portal' => 'human-resource', 'code' => 'hr-dashboard', 'name' => 'Dashboard', 'path' => '/portal/human-resource/dashboard', 'sort' => 10],
            ['portal' => 'human-resource', 'code' => 'hr-user-management', 'name' => 'Data Users', 'path' => '/user-management', 'sort' => 20, 'view' => 'user_management.view', 'update' => 'user_management.edit'],
            ['portal' => 'customer', 'code' => 'customer-list', 'name' => 'Customer', 'path' => '/customers', 'sort' => 10, 'view' => 'customer.view', 'create' => 'customer.create'],
            ['portal' => 'finance', 'code' => 'finance-dashboard', 'name' => 'Dashboard', 'path' => '/portal/finance/dashboard', 'sort' => 10],
            ['portal' => 'inventory', 'code' => 'inventory-dashboard', 'name' => 'Dashboard', 'path' => '/portal/inventory/dashboard', 'sort' => 10],
            ['portal' => 'purchasing', 'code' => 'purchasing-dashboard', 'name' => 'Dashboard', 'path' => '/portal/purchasing/dashboard', 'sort' => 10],
            ['portal' => 'bank', 'code' => 'bank-dashboard', 'name' => 'Dashboard', 'path' => '/portal/bank/dashboard', 'sort' => 10],
            ['portal' => 'operational', 'code' => 'operational-dashboard', 'name' => 'Dashboard', 'path' => '/portal/operational/dashboard', 'sort' => 10],
            ['portal' => 'warehouse', 'code' => 'warehouse-dashboard', 'name' => 'Dashboard', 'path' => '/portal/warehouse/dashboard', 'sort' => 10],
        ];
        DB::table('access_menus')->insert(array_map(fn ($row) => [
            'id' => (string) Str::ulid(),
            'portal_id' => $portalIds[$row['portal']] ?? null,
            'code' => $row['code'],
            'name' => $row['name'],
            'path' => $row['path'],
            'sort_order' => $row['sort'],
            'permission_view' => $row['view'] ?? null,
            'permission_create' => $row['create'] ?? null,
            'permission_update' => $row['update'] ?? null,
            'permission_delete' => $row['delete'] ?? null,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $menus));

        $guard = config('auth.defaults.guard', 'web');
        foreach (['user_management.view', 'user_management.edit'] as $permissionName) {
            Permission::findOrCreate($permissionName, $guard);
        }

        $adminRole = Role::query()->where('name', 'admin')->where('guard_name', $guard)->first();
        $managerRole = Role::query()->where('name', 'manager')->where('guard_name', $guard)->first();
        if ($adminRole) {
            $adminRole->givePermissionTo(['user_management.view', 'user_management.edit']);
        }
        if ($managerRole) {
            $managerRole->givePermissionTo(['user_management.view', 'user_management.edit']);
        }

        $roleIds = DB::table('access_roles')->pluck('id', 'code');
        $menuRows = DB::table('access_menus')->get();
        $portalRows = DB::table('access_portals')->get();

        $rolePortalAccess = [
            'ADMIN' => ['sales', 'human-resource', 'finance', 'bank', 'inventory', 'purchasing', 'customer', 'operational', 'warehouse'],
            'MANAGER' => ['sales', 'human-resource', 'customer'],
            'WAREHOUSE' => ['inventory', 'warehouse'],
            'CASHIER' => ['sales'],
        ];
        foreach ($rolePortalAccess as $roleCode => $portalCodes) {
            foreach ($portalRows as $portal) {
                DB::table('access_role_portal_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $roleIds[$roleCode] ?? null,
                    'access_level_id' => null,
                    'portal_id' => $portal->id,
                    'can_view' => in_array($portal->code, $portalCodes, true),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $permissionSets = [
            'ADMIN' => ['*'],
            'MANAGER' => ['dashboard.view', 'outlet.view', 'outlet.update', 'category.view', 'category.create', 'category.update', 'category.delete', 'product.view', 'product.create', 'product.update', 'product.delete', 'payment_method.view', 'payment_method.create', 'payment_method.update', 'payment_method.delete', 'discount.view', 'discount.create', 'discount.update', 'discount.delete', 'taxes.view', 'taxes.create', 'taxes.update', 'taxes.delete', 'sale.view', 'sale.cancel.approve', 'customer.view', 'customer.create', 'report.view', 'user_management.view'],
            'WAREHOUSE' => ['dashboard.view', 'outlet.view', 'category.view', 'product.view', 'sale.view', 'report.view'],
            'CASHIER' => ['dashboard.view', 'outlet.view', 'category.view', 'product.view', 'payment_method.view', 'discount.view', 'sale.view', 'customer.view', 'customer.create', 'report.view'],
        ];

        foreach ($permissionSets as $roleCode => $permissions) {
            foreach ($menuRows as $menu) {
                $hasWildcard = $permissions === ['*'];
                $canView = $hasWildcard || !$menu->permission_view || in_array($menu->permission_view, $permissions, true);
                $canCreate = $hasWildcard || ($menu->permission_create && in_array($menu->permission_create, $permissions, true));
                $canEdit = $hasWildcard || ($menu->permission_update && in_array($menu->permission_update, $permissions, true));
                $canDelete = $hasWildcard || ($menu->permission_delete && in_array($menu->permission_delete, $permissions, true));

                DB::table('access_role_menu_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $roleIds[$roleCode] ?? null,
                    'access_level_id' => null,
                    'menu_id' => $menu->id,
                    'can_view' => $canView,
                    'can_create' => $canCreate,
                    'can_edit' => $canEdit,
                    'can_delete' => $canDelete,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('access_role_menu_permissions');
        Schema::dropIfExists('access_role_portal_permissions');
        Schema::dropIfExists('user_access_assignments');
        Schema::dropIfExists('access_menus');
        Schema::dropIfExists('access_portals');
        Schema::dropIfExists('access_levels');
        Schema::dropIfExists('access_roles');
        Schema::dropIfExists('access_user_types');
    }
};
