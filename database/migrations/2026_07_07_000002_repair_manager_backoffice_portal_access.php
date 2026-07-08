<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('access_roles')
            || ! Schema::hasTable('access_levels')
            || ! Schema::hasTable('access_portals')
            || ! Schema::hasTable('access_menus')
            || ! Schema::hasTable('access_role_portal_permissions')
            || ! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $now = now();
        $managerRoleId = DB::table('access_roles')->where('code', 'MANAGER')->value('id');
        if (! $managerRoleId) {
            return;
        }

        $defaultLevelId = DB::table('access_levels')->where('code', 'DEFAULT')->value('id');
        $levelIds = array_values(array_filter([null, $defaultLevelId]));
        array_unshift($levelIds, null);
        $levelIds = array_values(array_unique($levelIds));

        // Manager harus bisa membuka launcher portal backoffice dan report utama.
        // Absensi squad dan POS runtime tetap tidak diaktifkan sebagai portal backoffice.
        $managerPortalCodes = [
            'sales',
            'human-resource',
            'finance',
            'customer',
            'operational',
            'owner-overview',
            'omzet-report',
            'sales-report',
        ];

        $portalRows = DB::table('access_portals')
            ->whereIn('code', $managerPortalCodes)
            ->get(['id', 'code']);

        foreach ($portalRows as $portal) {
            foreach ($levelIds as $levelId) {
                $this->upsertPortalPermission((string) $managerRoleId, $levelId ? (string) $levelId : null, (string) $portal->id, true, $now);
            }
        }

        $viewPermissions = [
            null,
            '',
            'dashboard.view',
            'outlet.view',
            'category.view',
            'product.view',
            'payment_method.view',
            'discount.view',
            'taxes.view',
            'addon.view',
            'modifier.view',
            'customer.view',
            'sale.view',
            'report.view',
            'user_management.view',
        ];

        $writePermissions = [
            'category.create', 'category.update', 'category.delete',
            'product.create', 'product.update', 'product.delete',
            'payment_method.create', 'payment_method.update', 'payment_method.delete',
            'discount.create', 'discount.update', 'discount.delete',
            'taxes.create', 'taxes.update', 'taxes.delete',
            'addon.create', 'addon.update', 'addon.delete',
            'modifier.create', 'modifier.update', 'modifier.delete',
            'customer.create', 'customer.update',
            'outlet.update',
        ];

        $portalIds = $portalRows->pluck('id')->all();
        if (empty($portalIds)) {
            return;
        }

        $menus = DB::table('access_menus')
            ->whereIn('portal_id', $portalIds)
            ->where('is_active', true)
            ->get(['id', 'permission_view', 'permission_create', 'permission_update', 'permission_delete']);

        foreach ($menus as $menu) {
            $permissionView = $menu->permission_view;
            $canView = in_array($permissionView, $viewPermissions, true);
            if (! $canView) {
                continue;
            }

            $canCreate = $menu->permission_create && in_array($menu->permission_create, $writePermissions, true);
            $canEdit = $menu->permission_update && in_array($menu->permission_update, $writePermissions, true);
            $canDelete = $menu->permission_delete && in_array($menu->permission_delete, $writePermissions, true);

            foreach ($levelIds as $levelId) {
                $this->upsertMenuPermission(
                    (string) $managerRoleId,
                    $levelId ? (string) $levelId : null,
                    (string) $menu->id,
                    true,
                    (bool) $canCreate,
                    (bool) $canEdit,
                    (bool) $canDelete,
                    $now
                );
            }
        }
    }

    private function upsertPortalPermission(string $roleId, ?string $levelId, string $portalId, bool $canView, $now): void
    {
        $query = DB::table('access_role_portal_permissions')
            ->where('access_role_id', $roleId)
            ->where('portal_id', $portalId);

        $levelId ? $query->where('access_level_id', $levelId) : $query->whereNull('access_level_id');

        $existing = $query->first();
        if ($existing) {
            DB::table('access_role_portal_permissions')->where('id', $existing->id)->update([
                'can_view' => $canView || (bool) ($existing->can_view ?? false),
                'updated_at' => $now,
            ]);
            return;
        }

        DB::table('access_role_portal_permissions')->insert([
            'id' => (string) Str::ulid(),
            'access_role_id' => $roleId,
            'access_level_id' => $levelId,
            'portal_id' => $portalId,
            'can_view' => $canView,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function upsertMenuPermission(string $roleId, ?string $levelId, string $menuId, bool $canView, bool $canCreate, bool $canEdit, bool $canDelete, $now): void
    {
        $query = DB::table('access_role_menu_permissions')
            ->where('access_role_id', $roleId)
            ->where('menu_id', $menuId);

        $levelId ? $query->where('access_level_id', $levelId) : $query->whereNull('access_level_id');

        $existing = $query->first();
        $payload = [
            'can_view' => $canView || (bool) ($existing->can_view ?? false),
            'can_create' => $canCreate || (bool) ($existing->can_create ?? false),
            'can_edit' => $canEdit || (bool) ($existing->can_edit ?? false),
            'can_delete' => $canDelete || (bool) ($existing->can_delete ?? false),
            'updated_at' => $now,
        ];

        if ($existing) {
            DB::table('access_role_menu_permissions')->where('id', $existing->id)->update($payload);
            return;
        }

        DB::table('access_role_menu_permissions')->insert($payload + [
            'id' => (string) Str::ulid(),
            'access_role_id' => $roleId,
            'access_level_id' => $levelId,
            'menu_id' => $menuId,
            'created_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Additive repair only. No destructive rollback to avoid removing permissions
        // that may have been customized from User Management after this patch.
    }
};
