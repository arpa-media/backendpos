<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PORTAL = 'warehouse-operations';
    private const PATH = '/warehouse/stock/par-stock';
    private const CODE = 'warehouse-v3-stock-par-stock';
    private const BASE = 'warehouse.inventory.par_stock';

    public function up(): void
    {
        if (! Schema::hasTable('wh_par_stocks')) {
            Schema::create('wh_par_stocks', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('warehouse_id')->constrained('outlets')->cascadeOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->cascadeOnDelete();
                $table->decimal('par_qty', 18, 4)->default(0);
                $table->decimal('minimum_qty', 18, 4)->default(0);
                $table->boolean('is_active')->default(true)->index();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['warehouse_id', 'sku_id'], 'wh_par_stock_wh_sku_uq');
                $table->index(['warehouse_id', 'is_active'], 'wh_par_stock_wh_active_idx');
            });
        }

        $this->registerAccessMatrix();
    }

    private function registerAccessMatrix(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code', self::PORTAL)->first();
        if (! $portal) return;

        $now = now();
        $menu = DB::table('access_menus')->where('portal_id', $portal->id)->where('path', self::PATH)->first();
        $menuId = (string) ($menu->id ?? Str::ulid());
        $payload = [
            'portal_id' => $portal->id,
            'code' => self::CODE,
            'name' => 'Par Stock Warehouse',
            'path' => self::PATH,
            'sort_order' => 305,
            'permission_view' => self::BASE.'.view',
            'permission_create' => self::BASE.'.create',
            'permission_update' => self::BASE.'.update',
            'permission_delete' => self::BASE.'.delete',
            'is_active' => true,
            'updated_at' => $now,
        ];
        if ($menu) DB::table('access_menus')->where('id', $menuId)->update($payload);
        else DB::table('access_menus')->insert(['id' => $menuId, ...$payload, 'created_at' => $now]);

        $guard = config('auth.defaults.guard', 'web');
        if (Schema::hasTable('permissions')) {
            foreach (['view', 'create', 'update', 'delete'] as $action) Permission::findOrCreate(self::BASE.'.'.$action, $guard);
        }

        if (Schema::hasTable('access_roles') && Schema::hasTable('access_role_menu_permissions')) {
            $levels = Schema::hasTable('access_levels')
                ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
                : [];
            foreach (DB::table('access_roles')->get(['id', 'code']) as $role) {
                $roleCode = strtoupper(trim((string) $role->code));
                $enabled = in_array($roleCode, ['ADMIN', 'WAREHOUSE'], true);
                foreach (array_merge([null], $levels) as $levelId) {
                    $query = DB::table('access_role_menu_permissions')
                        ->where('access_role_id', $role->id)
                        ->where('menu_id', $menuId);
                    $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
                    if ($query->exists()) continue;
                    DB::table('access_role_menu_permissions')->insert([
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $role->id,
                        'access_level_id' => $levelId,
                        'menu_id' => $menuId,
                        'can_view' => $enabled,
                        'can_create' => $enabled,
                        'can_edit' => $enabled,
                        'can_delete' => $roleCode === 'ADMIN',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Non-destructive by design: preserve Warehouse opening balances and customized Access Matrix.
    }
};
