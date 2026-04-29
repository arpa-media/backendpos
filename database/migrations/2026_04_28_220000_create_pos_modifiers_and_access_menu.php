<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_modifiers')) {
            Schema::create('pos_modifiers', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('outlet_id')->constrained('outlets')->cascadeOnDelete();
                $table->string('name', 120);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->index(['outlet_id', 'is_active']);
            });
        }

        if (!Schema::hasTable('pos_modifier_notes')) {
            Schema::create('pos_modifier_notes', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('modifier_id')->constrained('pos_modifiers')->cascadeOnDelete();
                $table->string('note', 120);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['modifier_id', 'sort_order']);
            });
        }

        if (!Schema::hasTable('pos_modifier_assignments')) {
            Schema::create('pos_modifier_assignments', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('modifier_id')->constrained('pos_modifiers')->cascadeOnDelete();
                $table->string('assignable_type', 30);
                $table->ulid('assignable_id');
                $table->timestamps();
                $table->unique(['modifier_id', 'assignable_type', 'assignable_id'], 'pos_modifier_assignment_unique');
                $table->index(['assignable_type', 'assignable_id'], 'pos_modifier_assignment_target_idx');
            });
        }

        $guard = config('auth.defaults.guard', 'web');
        $permissions = ['modifier.view', 'modifier.create', 'modifier.update', 'modifier.delete'];
        foreach ($permissions as $permissionName) {
            Permission::findOrCreate($permissionName, $guard);
        }

        foreach (['admin', 'manager'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', $guard)->first();
            if ($role) $role->givePermissionTo($permissions);
        }

        if (Schema::hasTable('access_menus') && Schema::hasTable('access_portals')) {
            $now = now();
            $portalId = DB::table('access_portals')->where('code', 'sales')->value('id');
            if ($portalId) {
                DB::table('access_menus')->updateOrInsert(
                    ['code' => 'sales-modifier'],
                    [
                        'id' => DB::table('access_menus')->where('code', 'sales-modifier')->value('id') ?: (string) Str::ulid(),
                        'portal_id' => $portalId,
                        'name' => 'Modifier',
                        'path' => '/modifiers',
                        'sort_order' => 65,
                        'permission_view' => 'modifier.view',
                        'permission_create' => 'modifier.create',
                        'permission_update' => 'modifier.update',
                        'permission_delete' => 'modifier.delete',
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );

                if (Schema::hasTable('access_role_menu_permissions') && Schema::hasTable('access_roles')) {
                    $menuId = DB::table('access_menus')->where('code', 'sales-modifier')->value('id');
                    $roles = DB::table('access_roles')->whereIn('code', ['ADMIN', 'MANAGER'])->get();
                    foreach ($roles as $role) {
                        DB::table('access_role_menu_permissions')->updateOrInsert(
                            ['access_role_id' => $role->id, 'access_level_id' => null, 'menu_id' => $menuId],
                            ['id' => DB::table('access_role_menu_permissions')->where('access_role_id', $role->id)->whereNull('access_level_id')->where('menu_id', $menuId)->value('id') ?: (string) Str::ulid(), 'can_view' => true, 'can_create' => true, 'can_edit' => true, 'can_delete' => true, 'created_at' => $now, 'updated_at' => $now]
                        );
                    }
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')->where('code', 'sales-modifier')->delete();
        }
        Schema::dropIfExists('pos_modifier_assignments');
        Schema::dropIfExists('pos_modifier_notes');
        Schema::dropIfExists('pos_modifiers');
    }
};
