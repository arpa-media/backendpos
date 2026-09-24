<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PORTAL_CODE = 'warehouse';

    /** @var array<int, array<string, mixed>> */
    private array $menus = [
        [
            'code' => 'warehouse-dashboard',
            'name' => 'Dashboard HPP/COGS',
            'path' => '/portal/warehouse/dashboard',
            'sort_order' => 10,
            'permission_prefix' => 'cogs.dashboard',
        ],
        [
            'code' => 'cogs-uom-conversion',
            'name' => 'UOM Conversion',
            'path' => '/cogs/uom-conversions',
            'sort_order' => 20,
            'permission_prefix' => 'cogs.uom_conversion',
        ],
        [
            'code' => 'cogs-ingredient-recipes',
            'name' => 'Ingredient / Recipe',
            'path' => '/cogs/ingredient-recipes',
            'sort_order' => 30,
            'permission_prefix' => 'cogs.ingredient',
        ],
        [
            'code' => 'cogs-history-stock',
            'name' => 'History Stock',
            'path' => '/cogs/history-stock',
            'sort_order' => 40,
            'permission_prefix' => 'cogs.history_stock',
        ],
        [
            'code' => 'cogs-item-sold',
            'name' => 'Item Sold & Recipe Consumption',
            'path' => '/cogs/item-sold',
            'sort_order' => 50,
            'permission_prefix' => 'cogs.item_sold',
        ],
        [
            'code' => 'cogs-stock-variance',
            'name' => 'Stock Variance',
            'path' => '/cogs/stock-variance',
            'sort_order' => 60,
            'permission_prefix' => 'cogs.stock_variance',
        ],
        [
            'code' => 'cogs-calculation',
            'name' => 'COGS Calculation',
            'path' => '/cogs/calculation',
            'sort_order' => 70,
            'permission_prefix' => 'cogs.calculation',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $now = now();
        $portal = DB::table('access_portals')->where('code', self::PORTAL_CODE)->first();
        $portalId = (string) ($portal->id ?? Str::ulid());

        DB::table('access_portals')->updateOrInsert(
            ['code' => self::PORTAL_CODE],
            [
                'id' => $portalId,
                'name' => 'HPP/COGS',
                'description' => 'Portal recipe, ingredient consumption, stock variance, dan kalkulasi HPP/COGS.',
                'sort_order' => (int) ($portal->sort_order ?? 90),
                'is_active' => true,
                'created_at' => $portal->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        foreach ($this->menus as $menu) {
            $existing = DB::table('access_menus')->where('code', $menu['code'])->first();
            $menuId = (string) ($existing->id ?? Str::ulid());
            $prefix = (string) $menu['permission_prefix'];

            DB::table('access_menus')->updateOrInsert(
                ['code' => $menu['code']],
                [
                    'id' => $menuId,
                    'portal_id' => $portalId,
                    'name' => $menu['name'],
                    'path' => $menu['path'],
                    'sort_order' => $menu['sort_order'],
                    'permission_view' => $prefix.'.view',
                    'permission_create' => $prefix.'.create',
                    'permission_update' => $prefix.'.update',
                    'permission_delete' => $prefix.'.delete',
                    'is_active' => true,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ]
            );

            $this->ensurePermissions($prefix);
            $this->ensureMatrixRows($portalId, $menuId, $now);
            $this->ensurePortalVisibilityForGrantedMenu($portalId, $menuId, $now);
        }

        $this->deactivateRemovedMenus($now);
        $this->forgetPermissionCache();
    }

    private function ensurePermissions(string $prefix): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $guard = config('auth.defaults.guard', 'web');
        foreach (['view', 'create', 'update', 'delete'] as $action) {
            Permission::findOrCreate($prefix.'.'.$action, $guard);
        }
    }

    private function ensureMatrixRows(string $portalId, string $menuId, $now): void
    {
        if (! Schema::hasTable('access_roles')) {
            return;
        }

        $roles = DB::table('access_roles')->select('id', 'code')->get();
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];

        foreach ($roles as $role) {
            $isAdmin = strtoupper(trim((string) $role->code)) === 'ADMIN';
            foreach (array_merge([null], $levels) as $levelId) {
                if (Schema::hasTable('access_role_portal_permissions')) {
                    $portalQuery = DB::table('access_role_portal_permissions')
                        ->where('access_role_id', $role->id)
                        ->where('portal_id', $portalId);
                    $levelId === null
                        ? $portalQuery->whereNull('access_level_id')
                        : $portalQuery->where('access_level_id', $levelId);

                    if (! $portalQuery->exists()) {
                        DB::table('access_role_portal_permissions')->insert([
                            'id' => (string) Str::ulid(),
                            'access_role_id' => $role->id,
                            'access_level_id' => $levelId,
                            'portal_id' => $portalId,
                            'can_view' => $isAdmin,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }

                if (! Schema::hasTable('access_role_menu_permissions')) {
                    continue;
                }

                $menuQuery = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $role->id)
                    ->where('menu_id', $menuId);
                $levelId === null
                    ? $menuQuery->whereNull('access_level_id')
                    : $menuQuery->where('access_level_id', $levelId);

                if (! $menuQuery->exists()) {
                    DB::table('access_role_menu_permissions')->insert([
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $role->id,
                        'access_level_id' => $levelId,
                        'menu_id' => $menuId,
                        'can_view' => $isAdmin,
                        'can_create' => $isAdmin,
                        'can_edit' => $isAdmin,
                        'can_delete' => $isAdmin,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    private function ensurePortalVisibilityForGrantedMenu(string $portalId, string $menuId, $now): void
    {
        if (! Schema::hasTable('access_role_menu_permissions') || ! Schema::hasTable('access_role_portal_permissions')) {
            return;
        }

        $grantedRows = DB::table('access_role_menu_permissions')
            ->where('menu_id', $menuId)
            ->where('can_view', true)
            ->get();

        foreach ($grantedRows as $row) {
            $query = DB::table('access_role_portal_permissions')
                ->where('access_role_id', $row->access_role_id)
                ->where('portal_id', $portalId);
            $row->access_level_id === null
                ? $query->whereNull('access_level_id')
                : $query->where('access_level_id', $row->access_level_id);

            $existing = $query->first();
            if ($existing) {
                if (! (bool) $existing->can_view) {
                    DB::table('access_role_portal_permissions')->where('id', $existing->id)->update([
                        'can_view' => true,
                        'updated_at' => $now,
                    ]);
                }
                continue;
            }

            DB::table('access_role_portal_permissions')->insert([
                'id' => (string) Str::ulid(),
                'access_role_id' => $row->access_role_id,
                'access_level_id' => $row->access_level_id,
                'portal_id' => $portalId,
                'can_view' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function deactivateRemovedMenus($now): void
    {
        $removed = DB::table('access_menus')
            ->where(function ($query): void {
                $query->where('code', 'inventory-receive-stock')
                    ->orWhere('path', '/stock-inventory/receive-stock');
            })
            ->pluck('id');

        if ($removed->isEmpty()) {
            return;
        }

        DB::table('access_menus')->whereIn('id', $removed)->update([
            'is_active' => false,
            'updated_at' => $now,
        ]);

        if (Schema::hasTable('access_role_menu_permissions')) {
            DB::table('access_role_menu_permissions')->whereIn('menu_id', $removed)->update([
                'can_view' => false,
                'can_create' => false,
                'can_edit' => false,
                'can_delete' => false,
                'updated_at' => $now,
            ]);
        }
    }

    private function forgetPermissionCache(): void
    {
        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Non-destructive: matrix yang sudah disesuaikan admin tidak dikembalikan
        // otomatis. Gunakan backup sebelum patch untuk rollback penuh.
    }
};
