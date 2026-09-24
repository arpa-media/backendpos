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
        $portal = DB::table('access_portals')->where('code', 'inventory')->first();
        if (! $portal) {
            return;
        }

        $canonical = DB::table('access_menus')->where('code', 'inventory-stock-opname')->first();
        $legacyMenus = DB::table('access_menus')
            ->where(function ($query): void {
                $query->whereIn('code', ['inventory-check-stock', 'check-stock'])
                    ->orWhere('path', '/check-stock');
            })
            ->get();

        if (! $canonical && $legacyMenus->isNotEmpty()) {
            $legacy = $legacyMenus->first();
            DB::table('access_menus')->where('id', $legacy->id)->update($this->canonicalMenuPayload((string) $portal->id, $now));
            $canonical = DB::table('access_menus')->where('id', $legacy->id)->first();
            $legacyMenus = $legacyMenus->reject(fn ($row) => (string) $row->id === (string) $canonical->id)->values();
        }

        if (! $canonical) {
            $id = (string) Str::ulid();
            DB::table('access_menus')->insert(array_merge([
                'id' => $id,
                'created_at' => $now,
            ], $this->canonicalMenuPayload((string) $portal->id, $now)));
            $canonical = DB::table('access_menus')->where('id', $id)->first();
        } else {
            DB::table('access_menus')->where('id', $canonical->id)->update($this->canonicalMenuPayload((string) $portal->id, $now));
        }

        $menuIds = collect([(string) $canonical->id])
            ->merge($legacyMenus->pluck('id')->map(fn ($id) => (string) $id))
            ->unique()
            ->values();

        if (Schema::hasTable('access_role_menu_permissions')) {
            $rows = DB::table('access_role_menu_permissions')->whereIn('menu_id', $menuIds->all())->get();
            $grouped = $rows->groupBy(fn ($row) => (string) $row->access_role_id.'|'.($row->access_level_id === null ? '__NULL__' : (string) $row->access_level_id));

            foreach ($grouped as $scopeRows) {
                $first = $scopeRows->first();
                $payload = [
                    'can_view' => $scopeRows->max(fn ($row) => (int) $row->can_view) > 0,
                    'can_create' => $scopeRows->max(fn ($row) => (int) $row->can_create) > 0,
                    'can_edit' => $scopeRows->max(fn ($row) => (int) $row->can_edit) > 0,
                    'can_delete' => $scopeRows->max(fn ($row) => (int) $row->can_delete) > 0,
                    'updated_at' => $now,
                ];

                $query = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $first->access_role_id)
                    ->where('menu_id', $canonical->id);
                $first->access_level_id === null
                    ? $query->whereNull('access_level_id')
                    : $query->where('access_level_id', $first->access_level_id);

                $existing = $query->first();
                if ($existing) {
                    DB::table('access_role_menu_permissions')->where('id', $existing->id)->update($payload);
                } else {
                    DB::table('access_role_menu_permissions')->insert(array_merge([
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $first->access_role_id,
                        'access_level_id' => $first->access_level_id,
                        'menu_id' => $canonical->id,
                        'created_at' => $now,
                    ], $payload));
                }
            }

            $legacyIds = $legacyMenus->pluck('id')->map(fn ($id) => (string) $id)->all();
            if ($legacyIds !== []) {
                DB::table('access_role_menu_permissions')->whereIn('menu_id', $legacyIds)->delete();
            }
        }

        if ($legacyMenus->isNotEmpty()) {
            DB::table('access_menus')->whereIn('id', $legacyMenus->pluck('id')->all())->update([
                'is_active' => false,
                'updated_at' => $now,
            ]);
        }

        $this->ensurePortalVisibilityForGrantedMenu((string) $portal->id, (string) $canonical->id, $now);
        $this->ensurePermissions();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function canonicalMenuPayload(string $portalId, $now): array
    {
        return [
            'portal_id' => $portalId,
            'code' => 'inventory-stock-opname',
            'name' => 'Stock Opname',
            'path' => '/stock-inventory/stock-opname',
            'sort_order' => 60,
            'permission_view' => 'stock_inventory.opname.view',
            'permission_create' => 'stock_inventory.opname.create',
            'permission_update' => 'stock_inventory.opname.update',
            'permission_delete' => 'stock_inventory.opname.delete',
            'is_active' => true,
            'updated_at' => $now,
        ];
    }

    private function ensurePortalVisibilityForGrantedMenu(string $portalId, string $menuId, $now): void
    {
        if (! Schema::hasTable('access_role_menu_permissions') || ! Schema::hasTable('access_role_portal_permissions')) {
            return;
        }

        $granted = DB::table('access_role_menu_permissions')
            ->where('menu_id', $menuId)
            ->where('can_view', true)
            ->get();

        foreach ($granted as $menuPermission) {
            $query = DB::table('access_role_portal_permissions')
                ->where('access_role_id', $menuPermission->access_role_id)
                ->where('portal_id', $portalId);
            $menuPermission->access_level_id === null
                ? $query->whereNull('access_level_id')
                : $query->where('access_level_id', $menuPermission->access_level_id);

            $existing = $query->first();
            if ($existing) {
                if (! $existing->can_view) {
                    DB::table('access_role_portal_permissions')->where('id', $existing->id)->update(['can_view' => true, 'updated_at' => $now]);
                }
                continue;
            }

            DB::table('access_role_portal_permissions')->insert([
                'id' => (string) Str::ulid(),
                'access_role_id' => $menuPermission->access_role_id,
                'access_level_id' => $menuPermission->access_level_id,
                'portal_id' => $portalId,
                'can_view' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function ensurePermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $guard = config('auth.defaults.guard', 'web');
        foreach (['view', 'create', 'update', 'delete'] as $ability) {
            Permission::findOrCreate('stock_inventory.opname.'.$ability, $guard);
        }
    }

    public function down(): void
    {
        // Non-destructive: merged Access Matrix choices must not be rolled back.
    }
};
