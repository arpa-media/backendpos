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
    private const PORTAL = 'console';
    private const MENU = 'console-file-management';
    private const PERMISSIONS = [
        'console.file_management.view',
        'console.file_management.create_folder',
        'console.file_management.move',
        'console.file_management.delete',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('console_file_management_audit_logs')) {
            Schema::create('console_file_management_audit_logs', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->uuid('user_id')->nullable()->index();
                $table->string('action', 40)->index();
                $table->string('disk', 40)->index();
                $table->longText('source_paths');
                $table->string('destination_path', 1500)->nullable();
                $table->string('status', 20)->index();
                $table->text('message')->nullable();
                $table->timestamps();
                $table->index(['disk', 'created_at'], 'console_file_mgmt_disk_created_idx');
            });
        }

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            return;
        }

        $now = now();
        $portal = DB::table('access_portals')->where('code', self::PORTAL)->first();
        $portalId = (string) ($portal->id ?? Str::ulid());
        // I11 requirement: Console must be the final portal in launcher and Access Matrix.
        $lastSort = max(9999, ((int) DB::table('access_portals')->where('code', '!=', self::PORTAL)->max('sort_order')) + 100);
        DB::table('access_portals')->updateOrInsert(
            ['code' => self::PORTAL],
            [
                'id' => $portalId,
                'name' => 'Console',
                'description' => 'System console untuk observability, reporting control, dan storage file management.',
                'sort_order' => $lastSort,
                'is_active' => true,
                'created_at' => $portal->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        $menu = DB::table('access_menus')->where('code', self::MENU)->first();
        $menuId = (string) ($menu->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(
            ['code' => self::MENU],
            [
                'id' => $menuId,
                'portal_id' => $portalId,
                'name' => 'File Management',
                'path' => '/console/file-management',
                'sort_order' => 30,
                'permission_view' => self::PERMISSIONS[0],
                'permission_create' => self::PERMISSIONS[1],
                'permission_update' => self::PERMISSIONS[2],
                'permission_delete' => self::PERMISSIONS[3],
                'is_active' => true,
                'created_at' => $menu->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        if (Schema::hasTable('access_roles') && Schema::hasTable('access_role_portal_permissions') && Schema::hasTable('access_role_menu_permissions')) {
            $adminRoleIds = DB::table('access_roles')->where(function ($query): void {
                $query->whereRaw("UPPER(COALESCE(code,'')) IN ('ADMIN','ADMINISTRATOR','SUPERADMIN','SUPER-ADMIN')")
                    ->orWhereRaw("UPPER(COALESCE(name,'')) IN ('ADMIN','ADMINISTRATOR','SUPERADMIN','SUPER-ADMIN')")
                    ->orWhereRaw("LOWER(COALESCE(spatie_role_name,'')) IN ('admin','administrator','superadmin','super-admin')");
            })->pluck('id');
            foreach ($adminRoleIds as $roleId) {
                if (! DB::table('access_role_portal_permissions')->where('access_role_id', $roleId)->whereNull('access_level_id')->where('portal_id', $portalId)->exists()) {
                    DB::table('access_role_portal_permissions')->insert([
                        'id' => (string) Str::ulid(), 'access_role_id' => $roleId, 'access_level_id' => null,
                        'portal_id' => $portalId, 'can_view' => true, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                if (! DB::table('access_role_menu_permissions')->where('access_role_id', $roleId)->whereNull('access_level_id')->where('menu_id', $menuId)->exists()) {
                    DB::table('access_role_menu_permissions')->insert([
                        'id' => (string) Str::ulid(), 'access_role_id' => $roleId, 'access_level_id' => null,
                        'menu_id' => $menuId, 'can_view' => true, 'can_create' => true, 'can_edit' => true, 'can_delete' => true,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }

        Role::query()->where('guard_name', 'web')->get()->each(function (Role $role): void {
            if (in_array(strtolower(trim((string) $role->name)), ['admin', 'administrator', 'superadmin', 'super-admin'], true)) {
                $role->givePermissionTo(self::PERMISSIONS);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')->where('code', self::MENU)->delete();
        }
        if (Schema::hasTable('console_file_management_audit_logs')) {
            Schema::drop('console_file_management_audit_logs');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
