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
        $this->permissions();
        $this->menu();
    }

    private function permissions(): void
    {
        if (! Schema::hasTable('permissions')) return;
        $guard = (string) config('auth.defaults.guard', 'web');
        foreach (['view','create','update','delete','submit','approve_finance_1','approve_finance_2','print'] as $action) {
            Permission::findOrCreate('purchasing.order_management.'.$action, $guard);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function menu(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code', 'purchasing')->first();
        if (! $portal) return;

        $now = now();
        $existing = DB::table('access_menus')->where('code', 'purchasing-order-management')->first();
        $menuId = (string) ($existing->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(['code' => 'purchasing-order-management'], [
            'id' => $menuId,
            'portal_id' => $portal->id,
            'name' => 'Order Management',
            'path' => '/purchasing/order-management',
            'sort_order' => 30,
            'permission_view' => 'purchasing.order_management.view',
            'permission_create' => 'purchasing.order_management.create',
            'permission_update' => 'purchasing.order_management.update',
            'permission_delete' => 'purchasing.order_management.delete',
            'is_active' => true,
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ]);

        $legacyCodes = ['purchasing-purchase-orders','purchasing-service-orders','purchasing-reimburse-orders'];
        $legacyIds = DB::table('access_menus')->whereIn('code', $legacyCodes)->pluck('id');
        if ($legacyIds->isNotEmpty() && Schema::hasTable('access_role_menu_permissions')) {
            $groups = DB::table('access_role_menu_permissions')
                ->whereIn('menu_id', $legacyIds)
                ->get()
                ->groupBy(fn ($row) => (string) $row->access_role_id.'|'.(string) ($row->access_level_id ?? ''));

            foreach ($groups as $rows) {
                $first = $rows->first();
                $payload = [
                    'can_view' => $rows->contains(fn ($row) => (bool) $row->can_view),
                    'can_create' => $rows->contains(fn ($row) => (bool) $row->can_create),
                    'can_edit' => $rows->contains(fn ($row) => (bool) $row->can_edit),
                    'can_delete' => $rows->contains(fn ($row) => (bool) $row->can_delete),
                    'updated_at' => $now,
                ];
                $q = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $first->access_role_id)
                    ->where('menu_id', $menuId);
                is_null($first->access_level_id) ? $q->whereNull('access_level_id') : $q->where('access_level_id', $first->access_level_id);
                if ($q->exists()) {
                    $q->update($payload);
                } else {
                    DB::table('access_role_menu_permissions')->insert($payload + [
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $first->access_role_id,
                        'access_level_id' => $first->access_level_id,
                        'menu_id' => $menuId,
                        'created_at' => $now,
                    ]);
                }
            }
        }

        DB::table('access_menus')->whereIn('code', $legacyCodes)->update(['is_active' => false, 'updated_at' => $now]);
    }

    public function down(): void
    {
        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')->where('code', 'purchasing-order-management')->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('access_menus')->whereIn('code', ['purchasing-purchase-orders','purchasing-service-orders','purchasing-reimburse-orders'])->update(['is_active' => true, 'updated_at' => now()]);
        }
    }
};
