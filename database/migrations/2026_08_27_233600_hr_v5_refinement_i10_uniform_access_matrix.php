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
        $this->seedPermissions();
        $this->seedMenus();
    }

    public function down(): void
    {
        // Non-destructive: Access Matrix history is intentionally retained.
    }

    private function seedPermissions(): void
    {
        if (! Schema::hasTable('permissions')) return;
        $guard = config('auth.defaults.guard', 'web');
        foreach ([
            'hr.uniform.master.view',
            'hr.uniform.master.create',
            'hr.uniform.master.update',
            'hr.uniform.master.delete',
            'hr.uniform.stock.view',
            'hr.uniform.inbound.view',
            'hr.uniform.inbound.create',
        ] as $permission) {
            Permission::findOrCreate($permission, $guard);
        }
    }

    private function seedMenus(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
        if (! $portal) return;

        $now = now();
        $masterId = $this->upsertMenu((string) $portal->id, 'hr-uniform-master', 'Data Uniform', '/human-resource/data-master/uniform', 13,
            'hr.uniform.master.view', 'hr.uniform.master.create', 'hr.uniform.master.update', 'hr.uniform.master.delete', $now);
        $stockId = $this->upsertMenu((string) $portal->id, 'hr-uniform-stock', 'Stok Uniform', '/human-resource/manage-uniform/stock', 60,
            'hr.uniform.stock.view', null, null, null, $now);
        $inboundId = $this->upsertMenu((string) $portal->id, 'hr-uniform-inbound', 'Barang Masuk', '/human-resource/manage-uniform/inbound', 61,
            'hr.uniform.inbound.view', 'hr.uniform.inbound.create', null, null, $now);

        if (! Schema::hasTable('access_role_menu_permissions')) return;
        $source = DB::table('access_menus')->where('code', 'hr-data-master')->first()
            ?? DB::table('access_menus')->where('code', 'hr-data-squad')->first();
        $sourceRows = $source
            ? DB::table('access_role_menu_permissions')->where('menu_id', $source->id)->get()
            : collect();

        // If Data Master was cleaned up by an older menu migration, inherit from
        // portal-level HR access instead of leaving the new menus invisible.
        if ($sourceRows->isEmpty() && Schema::hasTable('access_role_portal_permissions')) {
            $sourceRows = DB::table('access_role_portal_permissions')
                ->where('portal_id', $portal->id)
                ->where('can_view', true)
                ->get()
                ->map(fn ($row) => (object) [
                    'access_role_id' => $row->access_role_id,
                    'access_level_id' => $row->access_level_id,
                    'can_view' => true,
                    'can_create' => true,
                    'can_edit' => true,
                    'can_delete' => true,
                ]);
        }

        foreach ($sourceRows as $grant) {
            $this->copyGrant($grant, $masterId, true, true, true, $now);
            $this->copyGrant($grant, $stockId, false, false, false, $now);
            $this->copyGrant($grant, $inboundId, true, false, false, $now);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function upsertMenu(string $portalId, string $code, string $name, string $path, int $sortOrder, ?string $view, ?string $create, ?string $update, ?string $delete, $now): string
    {
        $existing = DB::table('access_menus')->where('code', $code)->first();
        $id = (string) ($existing->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(['code' => $code], [
            'id' => $id,
            'portal_id' => $portalId,
            'name' => $name,
            'path' => $path,
            'sort_order' => $sortOrder,
            'permission_view' => $view,
            'permission_create' => $create,
            'permission_update' => $update,
            'permission_delete' => $delete,
            'is_active' => true,
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    private function copyGrant(object $source, string $targetMenuId, bool $create, bool $edit, bool $delete, $now): void
    {
        $query = DB::table('access_role_menu_permissions')
            ->where('access_role_id', $source->access_role_id)
            ->where('menu_id', $targetMenuId);
        $source->access_level_id === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $source->access_level_id);
        if ($query->exists()) return;

        DB::table('access_role_menu_permissions')->insert([
            'id' => (string) Str::ulid(),
            'access_role_id' => $source->access_role_id,
            'access_level_id' => $source->access_level_id,
            'menu_id' => $targetMenuId,
            'can_view' => (bool) $source->can_view,
            'can_create' => $create ? (bool) $source->can_create : false,
            'can_edit' => $edit ? (bool) $source->can_edit : false,
            'can_delete' => $delete ? (bool) $source->can_delete : false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
