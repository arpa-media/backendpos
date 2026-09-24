<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const FINANCE_MENU_CODE = 'finance-i08-expense-report';
    private const FINANCE_MENU_PATH = '/finance/expense-report';
    private const REPORT_MENU_CODE = 'report-expense-request';
    private const REPORT_MENU_PATH = '/report/expense-request';

    public function up(): void
    {
        $this->ensurePermissions();
        $this->normalizeAutomaticMarking();
        $this->registerCanonicalMenus();
        $this->seedFinanceExpenseMatrix();
        $this->grantDefaultFinancePostingPermission();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function ensurePermissions(): void
    {
        foreach ([
            'finance.expense_report.view',
            'finance.expense_report.post',
            'report.expense_request.view',
            'report.expense_request.create',
            'report.expense_request.update',
            'report.expense_request.delete',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function normalizeAutomaticMarking(): void
    {
        if (! Schema::hasTable('finance_expense_report_items') || ! Schema::hasColumn('finance_expense_report_items', 'marking')) {
            return;
        }

        $items = DB::table('finance_expense_report_items');
        if (Schema::hasColumn('finance_expense_report_items', 'general_posting_id')) {
            $items->whereNull('general_posting_id')->update(['marking' => 'MARKING', 'updated_at' => now()]);
        } else {
            $items->update(['marking' => 'MARKING', 'updated_at' => now()]);
        }

        if (! Schema::hasTable('finance_general_postings')) return;

        $draftIds = DB::table('finance_general_postings')
            ->where('source_code', 'PETTY_CASH_EXPENSE')
            ->where('status', 'DRAFT')
            ->pluck('id');

        if ($draftIds->isNotEmpty()) {
            DB::table('finance_general_postings')->whereIn('id', $draftIds)->update([
                'marking' => 'MARKING',
                'updated_at' => now(),
            ]);

            if (Schema::hasColumn('finance_expense_report_items', 'general_posting_id')) {
                DB::table('finance_expense_report_items')->whereIn('general_posting_id', $draftIds)->update([
                    'marking' => 'MARKING',
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function registerCanonicalMenus(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;

        $now = now();
        $financePortalId = (string) DB::table('access_portals')->where('code', 'finance')->value('id');
        if ($financePortalId !== '') {
            $existing = DB::table('access_menus')->where('code', self::FINANCE_MENU_CODE)->first();
            DB::table('access_menus')->updateOrInsert(
                ['code' => self::FINANCE_MENU_CODE],
                [
                    'id' => (string) ($existing->id ?? Str::ulid()),
                    'portal_id' => $financePortalId,
                    'name' => 'Finance Expense Report',
                    'path' => self::FINANCE_MENU_PATH,
                    'sort_order' => 260,
                    'permission_view' => 'finance.expense_report.view',
                    'permission_create' => null,
                    'permission_update' => 'finance.expense_report.post',
                    'permission_delete' => null,
                    'is_active' => true,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ]
            );
        }

        $reportPortalId = (string) DB::table('access_portals')->where('code', 'report')->value('id');
        if ($reportPortalId !== '') {
            $existing = DB::table('access_menus')->where('code', self::REPORT_MENU_CODE)->first();
            DB::table('access_menus')->updateOrInsert(
                ['code' => self::REPORT_MENU_CODE],
                [
                    'id' => (string) ($existing->id ?? Str::ulid()),
                    'portal_id' => $reportPortalId,
                    'name' => 'Petty Cash',
                    'path' => self::REPORT_MENU_PATH,
                    'sort_order' => 40,
                    'permission_view' => 'report.expense_request.view',
                    'permission_create' => 'report.expense_request.create',
                    'permission_update' => 'report.expense_request.update',
                    'permission_delete' => 'report.expense_request.delete',
                    'is_active' => true,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ]
            );
        }

        // Legacy Report-card duplicate must not reappear as another expense source.
        DB::table('access_menus')->where('code', 'report-expense-report')->update([
            'is_active' => false,
            'updated_at' => $now,
        ]);
    }

    private function seedFinanceExpenseMatrix(): void
    {
        if (! Schema::hasTable('access_menus') || ! Schema::hasTable('access_role_menu_permissions') || ! Schema::hasTable('access_roles')) {
            return;
        }

        $menuId = (string) DB::table('access_menus')->where('code', self::FINANCE_MENU_CODE)->value('id');
        $dashboardId = (string) DB::table('access_menus')->where('code', 'finance-dashboard')->value('id');
        if ($menuId === '') return;

        $now = now();
        $sourceRows = $dashboardId !== ''
            ? DB::table('access_role_menu_permissions')->where('menu_id', $dashboardId)->get()
            : collect();

        foreach ($sourceRows as $source) {
            $role = DB::table('access_roles')->where('id', $source->access_role_id)->first(['code', 'name']);
            $roleCode = strtoupper(trim((string) ($role->code ?? $role->name ?? '')));
            $defaultPoster = in_array($roleCode, ['ADMIN', 'ADMINISTRATOR', 'SUPERADMIN', 'SUPER-ADMIN', 'FINANCE'], true);

            $query = DB::table('access_role_menu_permissions')
                ->where('access_role_id', $source->access_role_id)
                ->where('menu_id', $menuId);
            $source->access_level_id === null
                ? $query->whereNull('access_level_id')
                : $query->where('access_level_id', $source->access_level_id);

            if ($query->exists()) {
                if ($defaultPoster) {
                    $query->update(['can_view' => true, 'can_edit' => true, 'updated_at' => $now]);
                }
                continue;
            }

            DB::table('access_role_menu_permissions')->insert([
                'id' => (string) Str::ulid(),
                'access_role_id' => $source->access_role_id,
                'access_level_id' => $source->access_level_id,
                'menu_id' => $menuId,
                'can_view' => $defaultPoster ? true : (bool) $source->can_view,
                'can_create' => false,
                'can_edit' => $defaultPoster,
                'can_delete' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Some databases have not materialized a finance-dashboard matrix row for
        // the FINANCE/Admin base roles yet. Seed a base row so the new menu is still
        // visible/configurable immediately after migration.
        $defaultRoleIds = DB::table('access_roles')
            ->where(function ($query): void {
                $query->whereRaw("UPPER(COALESCE(code, '')) IN ('ADMIN','ADMINISTRATOR','SUPERADMIN','SUPER-ADMIN','FINANCE')")
                    ->orWhereRaw("UPPER(COALESCE(name, '')) IN ('ADMIN','ADMINISTRATOR','SUPERADMIN','SUPER-ADMIN','FINANCE')");
            })
            ->pluck('id');

        foreach ($defaultRoleIds as $roleId) {
            $exists = DB::table('access_role_menu_permissions')
                ->where('access_role_id', $roleId)
                ->whereNull('access_level_id')
                ->where('menu_id', $menuId)
                ->exists();
            if ($exists) continue;

            DB::table('access_role_menu_permissions')->insert([
                'id' => (string) Str::ulid(),
                'access_role_id' => $roleId,
                'access_level_id' => null,
                'menu_id' => $menuId,
                'can_view' => true,
                'can_create' => false,
                'can_edit' => true,
                'can_delete' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function grantDefaultFinancePostingPermission(): void
    {
        Role::query()->where('guard_name', 'web')->get()->each(function (Role $role): void {
            $name = strtolower(trim((string) $role->name));
            if (in_array($name, ['admin', 'administrator', 'superadmin', 'super-admin', 'finance'], true)) {
                $role->givePermissionTo(['finance.expense_report.view', 'finance.expense_report.post']);
            }
        });
    }

    public function down(): void
    {
        // Audit-safe rollback: Petty Cash/General Posting financial history is retained.
        // Do not drop posting references or revert historical marking on posted journals.
    }
};
