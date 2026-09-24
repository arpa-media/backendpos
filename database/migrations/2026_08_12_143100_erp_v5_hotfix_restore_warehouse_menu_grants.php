<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PORTAL_CODE = 'warehouse-operations';

    private const MENUS = [
        [
            'code' => 'warehouse-v3-purchase-requests',
            'name' => 'Purchase Request',
            'path' => '/warehouse/purchasing/purchase-requests',
            'sort_order' => 200,
            'permission' => 'warehouse.procurement.request',
        ],
        [
            'code' => 'warehouse-v3-sales-stock-request',
            'name' => 'Stock Request Outlet',
            'path' => '/warehouse/stock-requests/inbox',
            'sort_order' => 400,
            'permission' => 'warehouse.stock_request.inbox',
        ],
    ];

    private const DEFAULT_ENABLED_ROLES = ['ADMIN', 'WAREHOUSE'];

    public function up(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $portal = DB::table('access_portals')->where('code', self::PORTAL_CODE)->first();
        if (! $portal) {
            return;
        }

        DB::transaction(function () use ($portal): void {
            $now = now();
            $guard = (string) config('auth.defaults.guard', 'web');

            foreach (self::MENUS as $definition) {
                if (Schema::hasTable('permissions')) {
                    foreach (['view', 'create', 'update', 'delete'] as $action) {
                        Permission::findOrCreate($definition['permission'].'.'.$action, $guard);
                    }
                }

                $existingCanonical = DB::table('access_menus')
                    ->where('code', $definition['code'])
                    ->first();

                $targetId = (string) ($existingCanonical->id ?? Str::ulid());

                DB::table('access_menus')->updateOrInsert(
                    ['code' => $definition['code']],
                    [
                        'id' => $targetId,
                        'portal_id' => $portal->id,
                        'name' => $definition['name'],
                        'path' => $definition['path'],
                        'sort_order' => $definition['sort_order'],
                        'permission_view' => $definition['permission'].'.view',
                        'permission_create' => $definition['permission'].'.create',
                        'permission_update' => $definition['permission'].'.update',
                        'permission_delete' => $definition['permission'].'.delete',
                        'is_active' => true,
                        'created_at' => $existingCanonical->created_at ?? $now,
                        'updated_at' => $now,
                    ]
                );

                $pathMenuIds = DB::table('access_menus')
                    ->where('portal_id', $portal->id)
                    ->where('path', $definition['path'])
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->values();

                if (Schema::hasTable('access_role_menu_permissions')) {
                    $sourceRows = DB::table('access_role_menu_permissions')
                        ->whereIn('menu_id', $pathMenuIds->all())
                        ->orderBy('id')
                        ->get();

                    if ($sourceRows->isNotEmpty()) {
                        $this->mergeGrantRows($targetId, $sourceRows, $now);
                    } else {
                        $this->seedDefaultMatrix($targetId, $now);
                    }
                }

                // Canonical path must have exactly one active Access Matrix row.
                DB::table('access_menus')
                    ->where('portal_id', $portal->id)
                    ->where('path', $definition['path'])
                    ->where('id', '<>', $targetId)
                    ->update([
                        'is_active' => false,
                        'updated_at' => $now,
                    ]);

                DB::table('access_menus')->where('id', $targetId)->update([
                    'is_active' => true,
                    'updated_at' => $now,
                ]);
            }
        });

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function mergeGrantRows(string $targetId, $sourceRows, $now): void
    {
        $groups = $sourceRows->groupBy(
            fn ($row) => (string) $row->access_role_id.'|'.(string) ($row->access_level_id ?? '')
        );

        foreach ($groups as $rows) {
            $first = $rows->first();
            $payload = [
                'can_view' => $rows->contains(fn ($row) => (bool) $row->can_view),
                'can_create' => $rows->contains(fn ($row) => (bool) $row->can_create),
                'can_edit' => $rows->contains(fn ($row) => (bool) $row->can_edit),
                'can_delete' => $rows->contains(fn ($row) => (bool) $row->can_delete),
                'updated_at' => $now,
            ];

            $targetQuery = DB::table('access_role_menu_permissions')
                ->where('access_role_id', $first->access_role_id)
                ->where('menu_id', $targetId);

            is_null($first->access_level_id)
                ? $targetQuery->whereNull('access_level_id')
                : $targetQuery->where('access_level_id', $first->access_level_id);

            $targetRows = $targetQuery->orderBy('id')->get();
            $target = $targetRows->first();

            if ($target) {
                DB::table('access_role_menu_permissions')
                    ->where('id', $target->id)
                    ->update($payload);

                if ($targetRows->count() > 1) {
                    DB::table('access_role_menu_permissions')
                        ->whereIn('id', $targetRows->slice(1)->pluck('id')->all())
                        ->delete();
                }
            } else {
                DB::table('access_role_menu_permissions')->insert($payload + [
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $first->access_role_id,
                    'access_level_id' => $first->access_level_id,
                    'menu_id' => $targetId,
                    'created_at' => $now,
                ]);
            }
        }
    }

    private function seedDefaultMatrix(string $menuId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $roles = DB::table('access_roles')->select('id', 'code')->get();
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];

        foreach ($roles as $role) {
            $roleCode = strtoupper(trim((string) $role->code));
            $enabled = in_array($roleCode, self::DEFAULT_ENABLED_ROLES, true);

            foreach (array_merge([null], $levels) as $levelId) {
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
                    'can_view' => $enabled,
                    'can_create' => $enabled,
                    'can_edit' => $enabled,
                    'can_delete' => $enabled && $roleCode === 'ADMIN',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: do not remove restored role/level grants.
    }
};
