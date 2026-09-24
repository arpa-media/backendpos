<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PORTAL_CODE = 'purchasing';

    private const MENUS = [
        ['code' => 'purchasing-dashboard', 'name' => 'Dashboard', 'path' => '/portal/purchasing/dashboard', 'sort_order' => 10, 'permission' => 'purchasing.dashboard'],
        ['code' => 'purchasing-fund-requests', 'name' => 'Pengajuan Dana', 'path' => '/purchasing/fund-requests', 'sort_order' => 20, 'permission' => 'purchasing.fund_request'],
        ['code' => 'purchasing-purchase-orders', 'name' => 'Purchase Order', 'path' => '/purchasing/purchase-orders', 'sort_order' => 30, 'permission' => 'purchasing.purchase_order'],
        ['code' => 'purchasing-service-orders', 'name' => 'Service Order', 'path' => '/purchasing/service-orders', 'sort_order' => 40, 'permission' => 'purchasing.service_order'],
        ['code' => 'purchasing-reimburse-orders', 'name' => 'Reimburse Order', 'path' => '/purchasing/reimburse-orders', 'sort_order' => 50, 'permission' => 'purchasing.reimburse_order'],
        ['code' => 'purchasing-goods-receipts', 'name' => 'Goods Receipt', 'path' => '/purchasing/goods-receipts', 'sort_order' => 60, 'permission' => 'purchasing.goods_receipt'],
        ['code' => 'purchasing-service-acceptances', 'name' => 'Service Acceptance', 'path' => '/purchasing/service-acceptances', 'sort_order' => 70, 'permission' => 'purchasing.service_acceptance'],
        ['code' => 'purchasing-reimburse-payments', 'name' => 'Reimburse Payment', 'path' => '/purchasing/reimburse-payments', 'sort_order' => 80, 'permission' => 'purchasing.reimburse_payment'],
        ['code' => 'purchasing-incoming-invoices', 'name' => 'Invoice Masuk', 'path' => '/purchasing/incoming-invoices', 'sort_order' => 90, 'permission' => 'purchasing.incoming_invoice'],
        ['code' => 'purchasing-outgoing-invoices', 'name' => 'Invoice Keluar', 'path' => '/purchasing/outgoing-invoices', 'sort_order' => 100, 'permission' => 'purchasing.outgoing_invoice'],
        ['code' => 'purchasing-account-receivables', 'name' => 'Account Receivable', 'path' => '/purchasing/account-receivables', 'sort_order' => 110, 'permission' => 'purchasing.account_receivable'],
        ['code' => 'purchasing-account-payables', 'name' => 'Account Payable', 'path' => '/purchasing/account-payables', 'sort_order' => 120, 'permission' => 'purchasing.account_payable'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $now = now();
        $existingPortal = DB::table('access_portals')->where('code', self::PORTAL_CODE)->first();
        $portalId = (string) ($existingPortal->id ?? Str::ulid());

        DB::table('access_portals')->updateOrInsert(
            ['code' => self::PORTAL_CODE],
            [
                'id' => $portalId,
                'name' => 'Purchasing',
                'description' => 'Portal pengajuan, order, penerimaan, invoice, piutang, dan hutang.',
                'sort_order' => 60,
                'is_active' => true,
                'created_at' => $existingPortal->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        $guard = config('auth.defaults.guard', 'web');
        $menuIds = [];

        foreach (self::MENUS as $menu) {
            $existing = DB::table('access_menus')->where('code', $menu['code'])->first();
            $menuId = (string) ($existing->id ?? Str::ulid());
            $menuIds[] = $menuId;

            DB::table('access_menus')->updateOrInsert(
                ['code' => $menu['code']],
                [
                    'id' => $menuId,
                    'portal_id' => $portalId,
                    'name' => $menu['name'],
                    'path' => $menu['path'],
                    'sort_order' => $menu['sort_order'],
                    'permission_view' => $menu['permission'] . '.view',
                    'permission_create' => $menu['permission'] . '.create',
                    'permission_update' => $menu['permission'] . '.update',
                    'permission_delete' => $menu['permission'] . '.delete',
                    'is_active' => true,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ]
            );

            if (Schema::hasTable('permissions')) {
                foreach (['view', 'create', 'update', 'delete'] as $action) {
                    Permission::findOrCreate($menu['permission'] . '.' . $action, $guard);
                }
            }
        }

        $this->seedAccessMatrix($portalId, $menuIds, $now);

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /**
     * @param array<int, string> $menuIds
     */
    private function seedAccessMatrix(string $portalId, array $menuIds, $now): void
    {
        if (! Schema::hasTable('access_roles')) {
            return;
        }

        $roleColumns = ['id', 'code'];
        if (Schema::hasColumn('access_roles', 'spatie_role_name')) {
            $roleColumns[] = 'spatie_role_name';
        }

        $roles = DB::table('access_roles')->select($roleColumns)->get();
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];
        $scopes = array_merge([null], $levels);

        foreach ($roles as $role) {
            $roleCode = strtoupper(trim((string) ($role->code ?? '')));
            $spatieRole = strtolower(trim((string) ($role->spatie_role_name ?? '')));
            $isAdmin = in_array($roleCode, ['ADMIN', 'ADMINISTRATOR', 'SUPERADMIN', 'SUPER-ADMIN', 'SUPER_ADMIN'], true)
                || str_contains($spatieRole, 'admin');

            foreach ($scopes as $levelId) {
                if (Schema::hasTable('access_role_portal_permissions')) {
                    $portalQuery = DB::table('access_role_portal_permissions')
                        ->where('access_role_id', $role->id)
                        ->where('portal_id', $portalId);
                    $levelId === null
                        ? $portalQuery->whereNull('access_level_id')
                        : $portalQuery->where('access_level_id', $levelId);

                    if (! $portalQuery->exists()) {
                        DB::table('access_role_portal_permissions')->insert([
                            'id' => (string) Str::ulid(),
                            'access_role_id' => $role->id,
                            'access_level_id' => $levelId,
                            'portal_id' => $portalId,
                            'can_view' => $isAdmin,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }

                if (! Schema::hasTable('access_role_menu_permissions')) {
                    continue;
                }

                foreach ($menuIds as $menuId) {
                    $menuQuery = DB::table('access_role_menu_permissions')
                        ->where('access_role_id', $role->id)
                        ->where('menu_id', $menuId);
                    $levelId === null
                        ? $menuQuery->whereNull('access_level_id')
                        : $menuQuery->where('access_level_id', $levelId);

                    if ($menuQuery->exists()) {
                        continue;
                    }

                    DB::table('access_role_menu_permissions')->insert([
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $role->id,
                        'access_level_id' => $levelId,
                        'menu_id' => $menuId,
                        'can_view' => $isAdmin,
                        'can_create' => $isAdmin,
                        'can_edit' => $isAdmin,
                        'can_delete' => $isAdmin,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: Access Matrix dapat sudah dikustomisasi administrator.
    }
};
