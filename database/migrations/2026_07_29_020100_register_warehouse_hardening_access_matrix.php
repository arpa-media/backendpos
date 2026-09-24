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
    private const ROLE_CODES = ['ADMIN', 'WAREHOUSE'];
    private const MENUS = [
        [
            'code' => 'warehouse-mobile-scanner',
            'name' => 'Mobile Scanner',
            'path' => '/warehouse/mobile-scanner',
            'sort_order' => 920,
            'permission' => 'warehouse.mobile_scanner',
        ],
        [
            'code' => 'warehouse-go-live-readiness',
            'name' => 'Go-Live Readiness',
            'path' => '/warehouse/go-live-readiness',
            'sort_order' => 930,
            'permission' => 'warehouse.go_live',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }
        $portal = DB::table('access_portals')->where('code', self::PORTAL_CODE)->first();
        if (! $portal) {
            return;
        }

        $now = now();
        $guard = config('auth.defaults.guard', 'web');
        foreach (self::MENUS as $menu) {
            $existing = DB::table('access_menus')->where('code', $menu['code'])->first();
            $menuId = (string) ($existing->id ?? Str::ulid());
            DB::table('access_menus')->updateOrInsert(['code' => $menu['code']], [
                'id' => $menuId,
                'portal_id' => $portal->id,
                'name' => $menu['name'],
                'path' => $menu['path'],
                'sort_order' => $menu['sort_order'],
                'permission_view' => $menu['permission'].'.view',
                'permission_create' => $menu['permission'].'.run',
                'permission_update' => $menu['permission'].'.manage',
                'permission_delete' => null,
                'is_active' => true,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ]);
            foreach (['view', 'run', 'manage', 'scan'] as $action) {
                Permission::findOrCreate($menu['permission'].'.'.$action, $guard);
            }
            $this->seedMatrix($menuId, $now);
        }

        foreach ([
            'warehouse.hardening.audit.view',
            'warehouse.hardening.health.run',
            'warehouse.hardening.uat.run',
            'warehouse.hardening.go_live.run',
            'warehouse.hardening.scan_token.issue',
        ] as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function seedMatrix(string $menuId, $now): void
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
            $enabled = in_array($roleCode, self::ROLE_CODES, true);
            foreach (array_merge([null], $levels) as $levelId) {
                $query = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $role->id)
                    ->where('menu_id', $menuId);
                $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
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
                    'can_delete' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Preserve Access Matrix customization.
    }
};
