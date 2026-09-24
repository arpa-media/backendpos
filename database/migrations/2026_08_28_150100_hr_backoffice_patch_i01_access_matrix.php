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
        if (Schema::hasTable('permissions')) {
            Permission::findOrCreate('hr.uniform.outbound.cancel', config('auth.defaults.guard', 'web'));
        }

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            return;
        }

        $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
        if (! $portal) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            return;
        }

        $now = now();
        $menu = DB::table('access_menus')
            ->where('code', 'hr-uniform-outbound')
            ->orWhere(function ($q): void {
                $q->where('path', '/human-resource/manage-uniform/outbound');
            })
            ->first();

        $menuId = (string) ($menu->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(
            ['id' => $menuId],
            [
                'portal_id' => (string) $portal->id,
                'code' => 'hr-uniform-outbound',
                'name' => 'Uniform Keluar',
                'path' => '/human-resource/manage-uniform/outbound',
                'sort_order' => (int) ($menu->sort_order ?? 62),
                'permission_view' => $menu->permission_view ?? 'hr.uniform.outbound.view',
                'permission_create' => $menu->permission_create ?? 'hr.uniform.outbound.create',
                'permission_update' => 'hr.uniform.outbound.cancel',
                'permission_delete' => $menu->permission_delete ?? null,
                'is_active' => true,
                'created_at' => $menu->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        if (Schema::hasTable('access_role_menu_permissions')) {
            $existing = DB::table('access_role_menu_permissions')->where('menu_id', $menuId)->get();
            if ($existing->isEmpty()) {
                $source = DB::table('access_menus')->where('code', 'hr-uniform-inbound')->first();
                if ($source) {
                    foreach (DB::table('access_role_menu_permissions')->where('menu_id', $source->id)->get() as $row) {
                        DB::table('access_role_menu_permissions')->insert([
                            'id' => (string) Str::ulid(),
                            'access_role_id' => $row->access_role_id,
                            'access_level_id' => $row->access_level_id,
                            'menu_id' => $menuId,
                            'can_view' => (bool) $row->can_view,
                            'can_create' => (bool) $row->can_create,
                            'can_edit' => (bool) $row->can_create,
                            'can_delete' => false,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            } else {
                // Default aman untuk instalasi existing: role/level yang memang sudah
                // boleh posting Uniform Keluar mendapat hak reversal. Tetap dapat
                // dimatikan kembali lewat Access Matrix (Edit = OFF).
                DB::table('access_role_menu_permissions')
                    ->where('menu_id', $menuId)
                    ->where('can_create', true)
                    ->update(['can_edit' => true, 'updated_at' => $now]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Non-destructive: permission/menu mapping dipertahankan untuk audit dan
        // supaya rollback patch lain tidak menonaktifkan akses transaksi yang valid.
    }
};
