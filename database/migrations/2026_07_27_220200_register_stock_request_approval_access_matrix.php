<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $now = now();
        $portal = DB::table('access_portals')->where('code', 'purchasing')->first();
        $portalId = (string) ($portal->id ?? Str::ulid());

        DB::table('access_portals')->updateOrInsert(
            ['code' => 'purchasing'],
            [
                'id' => $portalId,
                'name' => 'Purchasing',
                'description' => 'Portal Purchasing dan approval kebutuhan stock outlet.',
                'sort_order' => 60,
                'is_active' => true,
                'created_at' => $portal->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        $existing = DB::table('access_menus')->where('code', 'purchasing-stock-request-approval')->first();
        $menuId = (string) ($existing->id ?? Str::ulid());
        $permissions = [
            'view' => 'stock_inventory.request_approval.view',
            'create' => 'stock_inventory.request_approval.create',
            'update' => 'stock_inventory.request_approval.update',
            'delete' => 'stock_inventory.request_approval.delete',
        ];

        DB::table('access_menus')->updateOrInsert(
            ['code' => 'purchasing-stock-request-approval'],
            [
                'id' => $menuId,
                'portal_id' => $portalId,
                'name' => 'Stock Request Approval',
                'path' => '/purchasing/stock-request-approval',
                'sort_order' => 20,
                'permission_view' => $permissions['view'],
                'permission_create' => $permissions['create'],
                'permission_update' => $permissions['update'],
                'permission_delete' => $permissions['delete'],
                'is_active' => true,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            foreach ($permissions as $permission) {
                Permission::findOrCreate($permission, $guard);
            }
        }

        $this->seedMatrixRows($portalId, $menuId, $now);

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function seedMatrixRows(string $portalId, string $menuId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $roles = DB::table('access_roles')->select('id', 'code')->get();
        $levelIds = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];

        foreach ($roles as $role) {
            $isAdmin = strtoupper(trim((string) $role->code)) === 'ADMIN';
            foreach (array_merge([null], $levelIds) as $levelId) {
                $portalQuery = Schema::hasTable('access_role_portal_permissions')
                    ? DB::table('access_role_portal_permissions')
                        ->where('access_role_id', $role->id)
                        ->where('portal_id', $portalId)
                    : null;

                if ($portalQuery) {
                    $levelId === null
                        ? $portalQuery->whereNull('access_level_id')
                        : $portalQuery->where('access_level_id', $levelId);
                }

                $portalPermission = $portalQuery?->first();
                $portalCanView = $isAdmin || (bool) ($portalPermission->can_view ?? false);
                if (! $portalPermission && Schema::hasTable('access_role_portal_permissions')) {
                    DB::table('access_role_portal_permissions')->insert([
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $role->id,
                        'access_level_id' => $levelId,
                        'portal_id' => $portalId,
                        'can_view' => $portalCanView,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $query = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $role->id)
                    ->where('menu_id', $menuId);
                $levelId === null
                    ? $query->whereNull('access_level_id')
                    : $query->where('access_level_id', $levelId);

                if ($query->exists()) {
                    continue;
                }

                DB::table('access_role_menu_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $role->id,
                    'access_level_id' => $levelId,
                    'menu_id' => $menuId,
                    'can_view' => $portalCanView,
                    'can_create' => $isAdmin,
                    'can_edit' => $isAdmin,
                    'can_delete' => $isAdmin,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: Access Matrix may have been customized after deployment.
    }
};
