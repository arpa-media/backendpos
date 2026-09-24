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

    private const MENUS = [
        ['warehouse-finance-sales-invoices', 'Sales Invoice & Piutang', '/warehouse/finance/sales-invoices', 410, 'warehouse.finance.invoice'],
        ['warehouse-finance-customer-receipts', 'Penerimaan Customer', '/warehouse/finance/customer-receipts', 420, 'warehouse.finance.receipt'],
        ['warehouse-finance-margin', 'COGS & Gross Margin', '/warehouse/finance/margin', 430, 'warehouse.finance.margin'],
    ];

    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        foreach (self::MENUS as [, , , , $base]) {
            foreach (['view', 'create', 'update', 'approve', 'void'] as $action) {
                if (Schema::hasTable('permissions')) {
                    Permission::findOrCreate("{$base}.{$action}", $guard);
                }
            }
        }

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $portal = DB::table('access_portals')->where('code', self::PORTAL_CODE)->first();
        if (! $portal) {
            return;
        }

        $now = now();
        foreach (self::MENUS as [$code, $name, $path, $sortOrder, $base]) {
            $existing = DB::table('access_menus')->where('code', $code)->first();
            $menuId = (string) ($existing->id ?? Str::ulid());

            DB::table('access_menus')->updateOrInsert(
                ['code' => $code],
                [
                    'id' => $menuId,
                    'portal_id' => $portal->id,
                    'name' => $name,
                    'path' => $path,
                    'sort_order' => $sortOrder,
                    'permission_view' => "{$base}.view",
                    'permission_create' => "{$base}.create",
                    'permission_update' => "{$base}.update",
                    'permission_delete' => "{$base}.void",
                    'is_active' => true,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ]
            );

            $this->seedMenuMatrix($menuId, $now);
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function seedMenuMatrix(string $menuId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $roles = DB::table('access_roles')->select('id', 'code')->get();
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];

        foreach ($roles as $role) {
            $enabled = in_array(strtoupper(trim((string) $role->code)), ['ADMIN', 'WAREHOUSE'], true);
            foreach (array_merge([null], $levels) as $levelId) {
                $query = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $role->id)
                    ->where('menu_id', $menuId);
                $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);

                if (! $query->exists()) {
                    DB::table('access_role_menu_permissions')->insert([
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $role->id,
                        'access_level_id' => $levelId,
                        'menu_id' => $menuId,
                        'can_view' => $enabled,
                        'can_create' => $enabled,
                        'can_edit' => $enabled,
                        'can_delete' => $enabled,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
