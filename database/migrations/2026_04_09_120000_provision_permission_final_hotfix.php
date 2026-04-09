<?php

use App\Models\AccessMenu;
use App\Models\AccessPortal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = config('auth.defaults.guard', 'web');
        $permissionName = 'pos.provision.view';

        Permission::findOrCreate($permissionName, $guard);

        foreach (['admin', 'manager', 'cashier', 'squad'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', $guard)->first();
            if ($role) {
                $role->givePermissionTo($permissionName);
            }
        }

        $portalId = AccessPortal::query()->where('code', 'pos')->value('id');
        if ($portalId) {
            AccessMenu::query()->updateOrCreate(
                ['code' => 'pos-provision'],
                [
                    'id' => AccessMenu::query()->where('code', 'pos-provision')->value('id') ?: (string) Str::ulid(),
                    'portal_id' => (string) $portalId,
                    'name' => 'Provision',
                    'path' => '/c/provision',
                    'sort_order' => 36,
                    'permission_view' => $permissionName,
                    'permission_create' => null,
                    'permission_update' => null,
                    'permission_delete' => null,
                    'is_active' => true,
                ]
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
