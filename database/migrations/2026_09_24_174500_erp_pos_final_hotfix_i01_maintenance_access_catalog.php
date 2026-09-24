<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const MAINTENANCE_PERMISSIONS = [
        'console.maintenance.view',
        'console.maintenance.manage',
    ];

    public function up(): void
    {
        $this->createMaintenanceSettings();
        $this->ensureRequiredAccessRoles();
        $this->ensureRequiredAccessLevels();
        $this->registerMaintenanceAccess();
    }

    public function down(): void
    {
        // Non-destructive hotfix rollback. Do not remove master roles/levels or
        // maintenance audit/settings that may already be referenced by users.
    }

    private function createMaintenanceSettings(): void
    {
        if (! Schema::hasTable('system_maintenance_settings')) {
            Schema::create('system_maintenance_settings', function (Blueprint $table): void {
                $table->string('id', 40)->primary();
                $table->boolean('enabled')->default(false)->index();
                $table->text('message')->nullable();
                $table->ulid('updated_by_user_id')->nullable()->index();
                $table->timestamps();
            });
        }

        $existing = DB::table('system_maintenance_settings')->where('id', 'default')->first();
        if (! $existing) {
            DB::table('system_maintenance_settings')->insert([
                'id' => 'default',
                'enabled' => false,
                'message' => 'Sistem sedang dalam proses maintenance.',
                'updated_by_user_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function ensureRequiredAccessRoles(): void
    {
        if (! Schema::hasTable('access_roles')) {
            return;
        }

        $backofficeTypeId = Schema::hasTable('access_user_types')
            ? DB::table('access_user_types')->whereRaw("UPPER(COALESCE(code,'')) = 'BACKOFFICE'")->value('id')
            : null;

        $required = [
            ['code' => 'ADMIN', 'name' => 'Administrator', 'aliases' => ['ADMIN', 'ADMINISTRATOR', 'SUPERADMIN', 'SUPER-ADMIN'], 'spatie' => 'admin'],
            ['code' => 'EXECUTIVE', 'name' => 'Executive', 'aliases' => ['EXECUTIVE'], 'spatie' => null],
            ['code' => 'FINANCE', 'name' => 'Finance', 'aliases' => ['FINANCE'], 'spatie' => null],
            ['code' => 'OPERATION', 'name' => 'Operation', 'aliases' => ['OPERATION', 'OPERATIONS'], 'spatie' => null],
            ['code' => 'GA', 'name' => 'GA', 'aliases' => ['GA', 'GENERAL AFFAIR', 'GENERAL AFFAIRS'], 'spatie' => null],
            ['code' => 'HUMAN RESOURCES', 'name' => 'HR', 'aliases' => ['HR', 'HUMAN RESOURCES', 'HUMAN RESOURCE'], 'spatie' => null],
            ['code' => 'BRAND', 'name' => 'Brand', 'aliases' => ['BRAND'], 'spatie' => null],
            ['code' => 'SQUAD_DEFAULT', 'name' => 'Squad Default', 'aliases' => ['SQUAD DEFAULT', 'SQUAD_DEFAULT'], 'spatie' => 'cashier'],
            ['code' => 'SQUAD_MANAGEMENT', 'name' => 'Squad Management', 'aliases' => ['SQUAD MANAGEMENT', 'SQUAD_MANAGEMENT'], 'spatie' => null],
            ['code' => 'SQUAD_WAREHOUSE', 'name' => 'Squad Warehouse', 'aliases' => ['SQUAD WAREHOUSE', 'SQUAD_WAREHOUSE'], 'spatie' => null],
        ];

        foreach ($required as $row) {
            $aliases = array_values(array_unique(array_map(fn ($value) => strtoupper(trim((string) $value)), $row['aliases'])));
            $exists = DB::table('access_roles')
                ->where(function ($query) use ($aliases): void {
                    $query->whereIn(DB::raw("UPPER(TRIM(COALESCE(code,'')))"), $aliases)
                        ->orWhereIn(DB::raw("UPPER(TRIM(COALESCE(name,'')))"), $aliases);
                })
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('access_roles')->insert([
                'id' => (string) Str::ulid(),
                'user_type_id' => $backofficeTypeId ?: null,
                'code' => $row['code'],
                'name' => $row['name'],
                'description' => $row['name'],
                'spatie_role_name' => $row['spatie'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function ensureRequiredAccessLevels(): void
    {
        if (! Schema::hasTable('access_levels')) {
            return;
        }

        $required = [
            ['code' => 'DEFAULT', 'name' => 'Default', 'aliases' => ['DEFAULT']],
            ['code' => 'ADMIN', 'name' => 'Admin', 'aliases' => ['ADMIN']],
            ['code' => 'GA', 'name' => 'GA', 'aliases' => ['GA', 'GENERAL AFFAIR', 'GENERAL AFFAIRS']],
            ['code' => 'FINANCE', 'name' => 'Finance', 'aliases' => ['FINANCE']],
            ['code' => 'OPERATION', 'name' => 'Operation', 'aliases' => ['OPERATION', 'OPERATIONS']],
            ['code' => 'BRAND', 'name' => 'Brand', 'aliases' => ['BRAND']],
            ['code' => 'HR', 'name' => 'HR', 'aliases' => ['HR', 'HUMAN RESOURCES', 'HUMAN RESOURCE']],
            ['code' => 'WAREHOUSE', 'name' => 'Warehouse', 'aliases' => ['WAREHOUSE']],
            ['code' => 'SPV', 'name' => 'SPV', 'aliases' => ['SPV', 'SUPERVISOR']],
        ];

        foreach ($required as $row) {
            $aliases = array_values(array_unique(array_map(fn ($value) => strtoupper(trim((string) $value)), $row['aliases'])));
            $exists = DB::table('access_levels')
                ->where(function ($query) use ($aliases): void {
                    $query->whereIn(DB::raw("UPPER(TRIM(COALESCE(code,'')))"), $aliases)
                        ->orWhereIn(DB::raw("UPPER(TRIM(COALESCE(name,'')))"), $aliases);
                })
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('access_levels')->insert([
                'id' => (string) Str::ulid(),
                'code' => $row['code'],
                'name' => $row['name'],
                'description' => $row['name'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function registerMaintenanceAccess(): void
    {
        foreach (self::MAINTENANCE_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            return;
        }

        $now = now();
        $portal = DB::table('access_portals')->where('code', 'console')->first();
        $portalId = (string) ($portal->id ?? Str::ulid());
        DB::table('access_portals')->updateOrInsert(
            ['code' => 'console'],
            [
                'id' => $portalId,
                'name' => 'Console',
                'description' => 'System console untuk observability, reporting engine, dan maintenance.',
                'sort_order' => 95,
                'is_active' => true,
                'created_at' => $portal->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        $menu = DB::table('access_menus')->where('code', 'console-maintenance')->first();
        $menuId = (string) ($menu->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(
            ['code' => 'console-maintenance'],
            [
                'id' => $menuId,
                'portal_id' => $portalId,
                'name' => 'Maintenance',
                'path' => '/console/maintenance',
                'sort_order' => 30,
                'permission_view' => 'console.maintenance.view',
                'permission_create' => null,
                'permission_update' => 'console.maintenance.manage',
                'permission_delete' => null,
                'is_active' => true,
                'created_at' => $menu->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        if (Schema::hasTable('access_roles') && Schema::hasTable('access_role_portal_permissions') && Schema::hasTable('access_role_menu_permissions')) {
            $adminRoleIds = DB::table('access_roles')
                ->where(function ($query): void {
                    $query->whereRaw("UPPER(COALESCE(code,'')) IN ('ADMIN','ADMINISTRATOR','SUPERADMIN','SUPER-ADMIN')")
                        ->orWhereRaw("UPPER(COALESCE(name,'')) IN ('ADMIN','ADMINISTRATOR','SUPERADMIN','SUPER-ADMIN')");
                })
                ->pluck('id');

            foreach ($adminRoleIds as $roleId) {
                if (! DB::table('access_role_portal_permissions')->where('access_role_id', $roleId)->whereNull('access_level_id')->where('portal_id', $portalId)->exists()) {
                    DB::table('access_role_portal_permissions')->insert([
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $roleId,
                        'access_level_id' => null,
                        'portal_id' => $portalId,
                        'can_view' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                if (! DB::table('access_role_menu_permissions')->where('access_role_id', $roleId)->whereNull('access_level_id')->where('menu_id', $menuId)->exists()) {
                    DB::table('access_role_menu_permissions')->insert([
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $roleId,
                        'access_level_id' => null,
                        'menu_id' => $menuId,
                        'can_view' => true,
                        'can_create' => false,
                        'can_edit' => true,
                        'can_delete' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        Role::query()->where('guard_name', 'web')->get()->each(function (Role $role): void {
            if (in_array(strtolower(trim($role->name)), ['admin', 'administrator', 'superadmin', 'super-admin'], true)) {
                $role->givePermissionTo(self::MAINTENANCE_PERMISSIONS);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
