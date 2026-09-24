<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const MENUS = [
        'AP' => [
            'code' => 'purchasing-account-payables',
            'name' => 'Account Payable',
            'path' => '/purchasing/account-payables',
            'sort' => 50,
            'permission' => 'purchasing.account_payable',
            'legacy' => ['purchasing-incoming-invoices'],
        ],
        'AR' => [
            'code' => 'purchasing-account-receivables',
            'name' => 'Account Receivable',
            'path' => '/purchasing/account-receivables',
            'sort' => 60,
            'permission' => 'purchasing.account_receivable',
            'legacy' => ['purchasing-outgoing-invoices'],
        ],
    ];

    public function up(): void
    {
        $this->permissions();
        $this->menus();
    }

    private function permissions(): void
    {
        if (! Schema::hasTable('permissions')) return;

        $guard = (string) config('auth.defaults.guard', 'web');
        foreach (['account_payable', 'account_receivable'] as $domain) {
            foreach (['view', 'create', 'update', 'delete', 'issue', 'payment', 'print'] as $action) {
                Permission::findOrCreate("purchasing.{$domain}.{$action}", $guard);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function menus(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;

        $portal = DB::table('access_portals')->where('code', 'purchasing')->first();
        if (! $portal) return;

        foreach (self::MENUS as $definition) {
            $this->upsertMenu($portal->id, $definition);
        }

        DB::table('access_menus')
            ->whereIn('code', ['purchasing-incoming-invoices', 'purchasing-outgoing-invoices'])
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    private function upsertMenu(string $portalId, array $definition): void
    {
        $now = now();
        $existing = DB::table('access_menus')->where('code', $definition['code'])->first();
        $menuId = (string) ($existing->id ?? Str::ulid());
        $permission = $definition['permission'];

        DB::table('access_menus')->updateOrInsert(['code' => $definition['code']], [
            'id' => $menuId,
            'portal_id' => $portalId,
            'name' => $definition['name'],
            'path' => $definition['path'],
            'sort_order' => $definition['sort'],
            'permission_view' => "{$permission}.view",
            'permission_create' => "{$permission}.create",
            'permission_update' => "{$permission}.update",
            'permission_delete' => "{$permission}.delete",
            'is_active' => true,
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ]);

        if (! Schema::hasTable('access_role_menu_permissions')) return;

        // Union target grants with the legacy standalone Invoice menu grants.
        // This preserves existing role/level access while making AP/AR canonical.
        $sourceCodes = array_merge([$definition['code']], $definition['legacy']);
        $sourceIds = DB::table('access_menus')->whereIn('code', $sourceCodes)->pluck('id');
        if ($sourceIds->isEmpty()) return;

        $groups = DB::table('access_role_menu_permissions')
            ->whereIn('menu_id', $sourceIds)
            ->get()
            ->groupBy(fn ($row) => (string) $row->access_role_id . '|' . (string) ($row->access_level_id ?? ''));

        foreach ($groups as $rows) {
            $first = $rows->first();
            $payload = [
                'can_view' => $rows->contains(fn ($row) => (bool) $row->can_view),
                'can_create' => $rows->contains(fn ($row) => (bool) $row->can_create),
                'can_edit' => $rows->contains(fn ($row) => (bool) $row->can_edit),
                'can_delete' => $rows->contains(fn ($row) => (bool) $row->can_delete),
                'updated_at' => $now,
            ];

            $query = DB::table('access_role_menu_permissions')
                ->where('access_role_id', $first->access_role_id)
                ->where('menu_id', $menuId);

            is_null($first->access_level_id)
                ? $query->whereNull('access_level_id')
                : $query->where('access_level_id', $first->access_level_id);

            if ($query->exists()) {
                $query->update($payload);
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

    public function down(): void
    {
        if (! Schema::hasTable('access_menus')) return;

        DB::table('access_menus')
            ->whereIn('code', ['purchasing-incoming-invoices', 'purchasing-outgoing-invoices'])
            ->update(['is_active' => true, 'updated_at' => now()]);
    }
};
