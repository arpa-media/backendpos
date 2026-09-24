<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finance_expense_report_items')) {
            throw new RuntimeException('I09 membutuhkan finance_expense_report_items.');
        }

        Schema::table('finance_expense_report_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('finance_expense_report_items', 'general_posting_id')) {
                $table->char('general_posting_id', 26)->nullable()->index('feri_general_posting_idx')->after('note');
            }
            if (! Schema::hasColumn('finance_expense_report_items', 'posted_at')) {
                $table->timestamp('posted_at')->nullable()->index('feri_posted_at_idx')->after('general_posting_id');
            }
            if (! Schema::hasColumn('finance_expense_report_items', 'posted_by_user_id')) {
                $table->char('posted_by_user_id', 26)->nullable()->index('feri_posted_user_idx')->after('posted_at');
            }
        });

        foreach ([
            'finance.expense_report.view',
            'finance.expense_report.post',
            'operational.outlet_pin.view',
            'operational.outlet_pin.update',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->registerMenus();
        $this->grantAdminPermissions();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function registerMenus(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;

        $now = now();

        // Report card label: the canonical daily source remains /report/expense-request.
        DB::table('access_menus')->where('code', 'report-expense-request')->update([
            'name' => 'Petty Cash',
            'updated_at' => $now,
        ]);

        $financePortalId = (string) DB::table('access_portals')->where('code', 'finance')->value('id');
        if ($financePortalId !== '') {
            $existing = DB::table('access_menus')->where('code', 'finance-i08-expense-report')->first();
            if ($existing) {
                DB::table('access_menus')->where('id', $existing->id)->update([
                    'name' => 'Expense Report',
                    'path' => '/finance/expense-report',
                    'permission_view' => 'finance.expense_report.view',
                    'permission_update' => 'finance.expense_report.post',
                    'is_active' => true,
                    'updated_at' => $now,
                ]);
                $this->upgradeFinanceExpenseMatrix((string) $existing->id, $now);
            }
        }

        $operationalPortalId = (string) DB::table('access_portals')->where('code', 'operational')->value('id');
        if ($operationalPortalId !== '') {
            $existing = DB::table('access_menus')->where('code', 'operational-outlet-pin')->first();
            $menuId = (string) ($existing->id ?? Str::ulid());

            DB::table('access_menus')->updateOrInsert(
                ['code' => 'operational-outlet-pin'],
                [
                    'id' => $menuId,
                    'portal_id' => $operationalPortalId,
                    'name' => 'Outlet PIN',
                    'path' => '/operational/outlet-pin',
                    'sort_order' => 30,
                    'permission_view' => 'operational.outlet_pin.view',
                    'permission_create' => null,
                    'permission_update' => 'operational.outlet_pin.update',
                    'permission_delete' => null,
                    'is_active' => true,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ]
            );

            $sourceId = (string) DB::table('access_menus')->where('code', 'operational-outlet')->value('id');
            $this->cloneOperationalMatrix($sourceId, $menuId, $now);
        }
    }

    private function upgradeFinanceExpenseMatrix(string $menuId, $now): void
    {
        if (! Schema::hasTable('access_role_menu_permissions')) return;

        $adminRoleIds = DB::table('access_roles')
            ->where(function ($q): void {
                $q->whereIn(DB::raw('LOWER(code)'), ['admin','administrator','superadmin','super-admin'])
                    ->orWhereIn(DB::raw('LOWER(name)'), ['admin','administrator','superadmin','super-admin']);
            })
            ->pluck('id')->map(fn ($id) => (string) $id)->all();

        if ($adminRoleIds !== []) {
            DB::table('access_role_menu_permissions')
                ->where('menu_id', $menuId)
                ->whereIn('access_role_id', $adminRoleIds)
                ->update(['can_view' => true, 'can_edit' => true, 'updated_at' => $now]);
        }
    }

    private function cloneOperationalMatrix(string $sourceMenuId, string $targetMenuId, $now): void
    {
        if ($sourceMenuId === '' || ! Schema::hasTable('access_role_menu_permissions')) return;

        $rows = DB::table('access_role_menu_permissions')->where('menu_id', $sourceMenuId)->get();
        foreach ($rows as $row) {
            $query = DB::table('access_role_menu_permissions')
                ->where('access_role_id', $row->access_role_id)
                ->where('menu_id', $targetMenuId);
            $row->access_level_id === null
                ? $query->whereNull('access_level_id')
                : $query->where('access_level_id', $row->access_level_id);

            $payload = [
                'can_view' => (bool) $row->can_view,
                'can_create' => false,
                'can_edit' => (bool) $row->can_edit,
                'can_delete' => false,
                'updated_at' => $now,
            ];

            if ($query->exists()) {
                $query->update($payload);
            } else {
                DB::table('access_role_menu_permissions')->insert($payload + [
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $row->access_role_id,
                    'access_level_id' => $row->access_level_id,
                    'menu_id' => $targetMenuId,
                    'created_at' => $now,
                ]);
            }
        }
    }

    private function grantAdminPermissions(): void
    {
        $permissions = [
            'finance.expense_report.view',
            'finance.expense_report.post',
            'operational.outlet_pin.view',
            'operational.outlet_pin.update',
        ];

        Role::query()->where('guard_name', 'web')->get()->each(function (Role $role) use ($permissions): void {
            $name = strtolower((string) $role->name);
            if (in_array($name, ['admin','administrator','superadmin','super-admin'], true)) {
                $role->givePermissionTo($permissions);
            }
        });
    }

    public function down(): void
    {
        // Audit-safe rollback: posting references are retained. Navigation may be
        // reverted manually if required; financial history is never dropped.
    }
};
