<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    private const PORTAL = 'console';
    private const MENU = 'console-system-health';
    private const PERMISSION = 'console.system_health.view';

    public function up(): void
    {
        if (! Schema::hasTable('report_request_metrics')) {
            Schema::create('report_request_metrics', function (Blueprint $t): void {
                $t->bigIncrements('id');
                $t->timestamp('occurred_at')->useCurrent();
                $t->string('route_key', 191);
                $t->string('method', 12)->default('GET');
                $t->unsignedSmallInteger('status_code')->default(200);
                $t->decimal('duration_ms', 12, 2)->default(0);
                $t->decimal('db_time_ms', 12, 2)->default(0);
                $t->unsignedInteger('query_count')->default(0);
                $t->decimal('slowest_query_ms', 12, 2)->default(0);
                $t->boolean('is_slow')->default(false);
                $t->string('trace_id', 64)->nullable();
                $t->string('user_id', 64)->nullable();
                $t->index(['occurred_at', 'is_slow'], 'rrm_occurred_slow_idx');
                $t->index(['route_key', 'occurred_at'], 'rrm_route_occurred_idx');
            });
        }

        if (! Schema::hasTable('system_health_gate_runs')) {
            Schema::create('system_health_gate_runs', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->string('gate_name', 80);
                $t->string('status', 20);
                $t->json('summary')->nullable();
                $t->string('commit_sha', 64)->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();
                $t->index(['gate_name', 'created_at'], 'shgr_gate_created_idx');
                $t->index(['status', 'created_at'], 'shgr_status_created_idx');
            });
        }

        $this->registerAccess();
    }

    public function down(): void
    {
        // Observability history is intentionally preserved on rollback.
        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')->where('code', self::MENU)->delete();
        }
        Permission::query()->where('name', self::PERMISSION)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function registerAccess(): void
    {
        Permission::findOrCreate(self::PERMISSION, 'web');
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $now = now();
        $portal = DB::table('access_portals')->where('code', self::PORTAL)->first();
        $portalId = (string) ($portal->id ?? Str::ulid());
        DB::table('access_portals')->updateOrInsert(
            ['code' => self::PORTAL],
            [
                'id' => $portalId,
                'name' => 'Console',
                'description' => 'System console untuk observability, health, dan Reporting Engine Control Center.',
                'sort_order' => 95,
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
                'name' => 'System Health',
                'path' => '/console/system-health',
                'sort_order' => 20,
                'permission_view' => self::PERMISSION,
                'permission_create' => null,
                'permission_update' => null,
                'permission_delete' => null,
                'is_active' => true,
                'created_at' => $menu->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        // Mirror existing Control Center viewers into the new read-only health menu.
        if (Schema::hasTable('access_role_menu_permissions')) {
            $controlMenuId = DB::table('access_menus')->where('code', 'console-control-center')->value('id');
            if ($controlMenuId) {
                $rows = DB::table('access_role_menu_permissions')
                    ->where('menu_id', $controlMenuId)
                    ->where('can_view', true)
                    ->get();
                foreach ($rows as $row) {
                    $exists = DB::table('access_role_menu_permissions')
                        ->where('access_role_id', $row->access_role_id)
                        ->where('access_level_id', $row->access_level_id)
                        ->where('menu_id', $menuId)
                        ->exists();
                    if (! $exists) {
                        DB::table('access_role_menu_permissions')->insert([
                            'id' => (string) Str::ulid(),
                            'access_role_id' => $row->access_role_id,
                            'access_level_id' => $row->access_level_id,
                            'menu_id' => $menuId,
                            'can_view' => true,
                            'can_create' => false,
                            'can_edit' => false,
                            'can_delete' => false,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }
        }

        Role::query()->where('guard_name', 'web')->with('permissions')->get()->each(function (Role $role): void {
            $hasControlCenterView = $role->permissions->contains(function ($permission): bool {
                return (string) $permission->guard_name === 'web'
                    && (string) $permission->name === 'console.control_center.view';
            });

            $isAdminRole = in_array(
                strtolower(trim((string) $role->name)),
                ['admin', 'administrator', 'superadmin', 'super-admin'],
                true
            );

            if ($hasControlCenterView || $isAdminRole) {
                $role->givePermissionTo(self::PERMISSION);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
