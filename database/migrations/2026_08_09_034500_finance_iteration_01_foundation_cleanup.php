<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureFinanceDashboardAccessMenu();
        $this->disablePurchasingAccountReceivable();
    }

    private function ensureFinanceDashboardAccessMenu(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $now = now();
        $portal = DB::table('access_portals')->where('code', 'finance')->first();
        $portalId = (string) ($portal->id ?? Str::ulid());

        DB::table('access_portals')->updateOrInsert(
            ['code' => 'finance'],
            [
                'id' => $portalId,
                'name' => 'Finance',
                'description' => 'Portal Finance, accounting, reconciliation, settlement, posting, dan financial report.',
                'sort_order' => (int) ($portal->sort_order ?? 20),
                'is_active' => true,
                'created_at' => $portal->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        $menu = DB::table('access_menus')->where('code', 'finance-dashboard')->first();
        $menuId = (string) ($menu->id ?? Str::ulid());

        DB::table('access_menus')->updateOrInsert(
            ['code' => 'finance-dashboard'],
            [
                'id' => $menuId,
                'portal_id' => $portalId,
                'name' => 'Dashboard',
                'path' => '/portal/finance/dashboard',
                'sort_order' => 10,
                'is_active' => true,
                'created_at' => $menu->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        // Seed only missing matrix rows. Existing administrator customization is
        // never overwritten by this foundation migration.
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) {
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
                $query = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $role->id)
                    ->where('menu_id', $menuId);
                $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);

                if ($query->exists()) {
                    continue;
                }

                DB::table('access_role_menu_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $role->id,
                    'access_level_id' => $levelId,
                    'menu_id' => $menuId,
                    'can_view' => $isAdmin,
                    'can_create' => false,
                    'can_edit' => false,
                    'can_delete' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function disablePurchasingAccountReceivable(): void
    {
        if (! Schema::hasTable('access_menus')) {
            return;
        }

        DB::table('access_menus')
            ->where('code', 'purchasing-account-receivables')
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')
                ->where('code', 'purchasing-account-receivables')
                ->update([
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
        }
    }
};
