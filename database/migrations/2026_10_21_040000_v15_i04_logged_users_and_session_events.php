<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    private const CODE = 'operational-logged-users-v15';

    public function up(): void
    {
        if (! Schema::hasTable('operational_session_events')) {
            Schema::create('operational_session_events', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->unsignedBigInteger('sanctum_token_id')->nullable()->index();
                $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event', 24)->index();
                $table->string('session_mode', 24)->nullable();
                $table->string('outlet_code', 64)->nullable();
                $table->string('app_variant', 64)->nullable();
                $table->string('device_hash', 64)->nullable();
                $table->string('client_label', 150)->nullable();
                $table->timestamp('created_at')->index();
            });
        }
        if (Schema::hasTable('permissions')) {
            foreach (['operational.logged_users.view', 'operational.logged_users.delete'] as $permission) {
                Permission::findOrCreate($permission, 'web');
            }
        }
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code', 'operational')->first();
        if (! $portal) return;
        $old = DB::table('access_menus')->where('code', self::CODE)->first();
        $menuId = $old?->id ?? (string) Str::ulid();
        $now = now();
        DB::table('access_menus')->updateOrInsert(['code' => self::CODE], [
            'id' => $menuId,
            'portal_id' => $portal->id,
            'name' => 'Logged Users',
            'path' => '/operational/logged-users',
            'sort_order' => 930,
            'permission_view' => 'operational.logged_users.view',
            'permission_create' => null,
            'permission_update' => null,
            // Delete column in the existing access matrix is the kick/force-logout grant.
            'permission_delete' => 'operational.logged_users.delete',
            'is_active' => true,
            'created_at' => $old?->created_at ?? $now,
            'updated_at' => $now,
        ]);
        // Secure by default. Other roles can be enabled explicitly in the Access Matrix.
        if (! $old && Schema::hasTable('access_roles') && Schema::hasTable('access_role_menu_permissions')) {
            $levels = Schema::hasTable('access_levels')
                ? DB::table('access_levels')->pluck('id')->all() : [];
            foreach (DB::table('access_roles')->get(['id', 'code']) as $role) {
                $admin = strtoupper(trim((string) $role->code)) === 'ADMIN';
                foreach (array_merge([null], $levels) as $levelId) {
                    $q = DB::table('access_role_menu_permissions')->where('access_role_id', $role->id)->where('menu_id', $menuId);
                    $levelId === null ? $q->whereNull('access_level_id') : $q->where('access_level_id', $levelId);
                    if ($q->exists()) continue;
                    DB::table('access_role_menu_permissions')->insert([
                        'id' => (string) Str::ulid(), 'access_role_id' => $role->id,
                        'access_level_id' => $levelId, 'menu_id' => $menuId,
                        'can_view' => $admin, 'can_create' => false, 'can_edit' => false,
                        'can_delete' => $admin, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intentionally preserve audit history and the customized Access Matrix.
        // Rollback of source code does not delete historical session events.
    }
};
