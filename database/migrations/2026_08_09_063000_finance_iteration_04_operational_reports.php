<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    private array $menus = [
        [
            'code' => 'report-overhandle',
            'name' => 'Overhandle Report',
            'path' => '/report/overhandle',
            'sort_order' => 10,
            'permission_view' => 'report.overhandle.view',
            'permission_create' => 'report.overhandle.create',
            'permission_update' => 'report.overhandle.update',
            'permission_delete' => 'report.overhandle.delete',
        ],
        [
            'code' => 'report-expense-request',
            'name' => 'Expense Report',
            'path' => '/report/expense-request',
            'sort_order' => 40,
            'permission_view' => 'report.expense_request.view',
            'permission_create' => 'report.expense_request.create',
            'permission_update' => 'report.expense_request.update',
            'permission_delete' => 'report.expense_request.delete',
        ],
    ];

    public function up(): void
    {
        $this->createOrUpgradeOverhandle();
        $this->createOrUpgradeExpenseReport();
        $this->registerPermissionsAndAccessMatrix();
    }

    private function createOrUpgradeOverhandle(): void
    {
        if (! Schema::hasTable('finance_overhandle_reports')) {
            Schema::create('finance_overhandle_reports', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('company_code', 16)->nullable()->index();
                $table->char('outlet_id', 26)->index();
                $table->date('business_date')->index();
                $table->string('shift_type', 20)->index();
                $table->time('snapshot_time');
                $table->dateTime('snapshot_at_utc')->nullable()->index();
                $table->string('timezone', 80)->default('Asia/Jakarta');
                $table->decimal('modal_amount', 18, 2)->default(0);
                $table->decimal('petty_cash_amount', 18, 2)->default(0);
                $table->decimal('magic_amount', 18, 2)->default(0);
                $table->decimal('tkj_pos_total', 18, 2)->default(0);
                $table->decimal('actual_total', 18, 2)->default(0);
                $table->decimal('difference_total', 18, 2)->default(0);
                $table->decimal('sales_total_at_snapshot', 18, 2)->default(0);
                $table->unsignedInteger('transaction_count_at_snapshot')->default(0);
                $table->decimal('discount_total_at_snapshot', 18, 2)->default(0);
                $table->decimal('tax_total_at_snapshot', 18, 2)->default(0);
                $table->decimal('rounding_total_at_snapshot', 18, 2)->default(0);
                $table->text('note')->nullable();
                $table->char('created_by', 26)->nullable()->index();
                $table->char('updated_by', 26)->nullable()->index();
                $table->timestamps();

                $table->unique(['outlet_id', 'business_date', 'shift_type'], 'fin_overhandle_outlet_day_shift_uq');
                $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            });
        } else {
            if (! Schema::hasColumn('finance_overhandle_reports', 'business_date') && Schema::hasColumn('finance_overhandle_reports', 'report_date')) {
                Schema::table('finance_overhandle_reports', fn (Blueprint $table) => $table->renameColumn('report_date', 'business_date'));
            }
            Schema::table('finance_overhandle_reports', function (Blueprint $table): void {
                if (! Schema::hasColumn('finance_overhandle_reports', 'company_code')) $table->string('company_code', 16)->nullable()->index();
                if (! Schema::hasColumn('finance_overhandle_reports', 'discount_total_at_snapshot')) $table->decimal('discount_total_at_snapshot', 18, 2)->default(0);
                if (! Schema::hasColumn('finance_overhandle_reports', 'tax_total_at_snapshot')) $table->decimal('tax_total_at_snapshot', 18, 2)->default(0);
                if (! Schema::hasColumn('finance_overhandle_reports', 'rounding_total_at_snapshot')) $table->decimal('rounding_total_at_snapshot', 18, 2)->default(0);
            });
        }

        if (! Schema::hasTable('finance_overhandle_report_payments')) {
            Schema::create('finance_overhandle_report_payments', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('report_id', 26)->index();
                $table->char('payment_method_id', 26)->nullable()->index();
                $table->string('payment_method_name', 180);
                $table->decimal('tkj_pos_amount', 18, 2)->default(0);
                $table->decimal('actual_amount', 18, 2)->default(0);
                $table->decimal('difference_amount', 18, 2)->default(0);
                $table->string('note', 500)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['report_id', 'payment_method_name'], 'fin_overhandle_payment_name_uq');
                $table->foreign('report_id')->references('id')->on('finance_overhandle_reports')->cascadeOnDelete();
            });
        } elseif (! Schema::hasColumn('finance_overhandle_report_payments', 'payment_method_id')) {
            Schema::table('finance_overhandle_report_payments', fn (Blueprint $table) => $table->char('payment_method_id', 26)->nullable()->index()->after('report_id'));
        }

        // Backfill PT snapshot from the canonical Iterasi 03 mapping.
        if (Schema::hasTable('finance_outlet_company_mappings')) {
            DB::statement("UPDATE finance_overhandle_reports r INNER JOIN finance_outlet_company_mappings m ON m.outlet_id = r.outlet_id AND m.is_active = 1 SET r.company_code = m.company_code WHERE r.company_code IS NULL");
        }
    }

    private function createOrUpgradeExpenseReport(): void
    {
        if (! Schema::hasTable('finance_expense_reports')) {
            Schema::create('finance_expense_reports', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('company_code', 16)->nullable()->index();
                $table->char('outlet_id', 26)->index();
                $table->date('business_date')->index();
                $table->string('timezone', 80)->default('Asia/Jakarta');
                $table->decimal('opening_balance', 18, 2)->default(0);
                $table->string('opening_source', 40)->nullable();
                $table->text('note')->nullable();
                $table->char('created_by', 26)->nullable()->index();
                $table->char('updated_by', 26)->nullable()->index();
                $table->timestamps();
                $table->unique(['outlet_id', 'business_date'], 'fin_expense_outlet_day_uq');
                $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            });
        } else {
            if (! Schema::hasColumn('finance_expense_reports', 'business_date') && Schema::hasColumn('finance_expense_reports', 'report_date')) {
                Schema::table('finance_expense_reports', fn (Blueprint $table) => $table->renameColumn('report_date', 'business_date'));
            }
            if (! Schema::hasColumn('finance_expense_reports', 'company_code')) {
                Schema::table('finance_expense_reports', fn (Blueprint $table) => $table->string('company_code', 16)->nullable()->index()->after('id'));
            }
        }

        if (! Schema::hasTable('finance_expense_report_items')) {
            Schema::create('finance_expense_report_items', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('report_id', 26)->index();
                $table->time('entry_time');
                $table->dateTime('entry_at_utc')->nullable()->index();
                $table->string('description', 500);
                $table->char('account_id', 26)->nullable()->index();
                $table->string('coa_code', 40)->nullable()->index();
                $table->string('coa_name', 255)->nullable();
                $table->decimal('amount', 18, 2)->default(0);
                $table->string('marking', 16)->default('MARKING')->index();
                $table->text('note')->nullable();
                $table->char('created_by', 26)->nullable()->index();
                $table->char('updated_by', 26)->nullable()->index();
                $table->timestamps();
                $table->foreign('report_id', 'fin_expense_items_report_fk')->references('id')->on('finance_expense_reports')->cascadeOnDelete();
                $table->foreign('account_id', 'fin_expense_items_account_fk')->references('id')->on('finance_chart_of_accounts')->nullOnDelete();
            });
        } else {
            Schema::table('finance_expense_report_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('finance_expense_report_items', 'account_id')) $table->char('account_id', 26)->nullable()->index()->after('description');
                if (! Schema::hasColumn('finance_expense_report_items', 'marking')) $table->string('marking', 16)->default('MARKING')->index()->after('amount');
            });
        }

        if (Schema::hasTable('finance_outlet_company_mappings')) {
            DB::statement("UPDATE finance_expense_reports r INNER JOIN finance_outlet_company_mappings m ON m.outlet_id = r.outlet_id AND m.is_active = 1 SET r.company_code = m.company_code WHERE r.company_code IS NULL");
        }
        if (Schema::hasTable('finance_chart_of_accounts') && Schema::hasColumn('finance_expense_report_items', 'account_id')) {
            DB::statement("UPDATE finance_expense_report_items i INNER JOIN finance_chart_of_accounts c ON c.code = i.coa_code SET i.account_id = c.id WHERE i.account_id IS NULL AND i.coa_code IS NOT NULL");
        }
    }

    private function registerPermissionsAndAccessMatrix(): void
    {
        $permissions = [
            'report.overhandle.view', 'report.overhandle.create', 'report.overhandle.update', 'report.overhandle.delete',
            'report.expense_request.view', 'report.expense_request.create', 'report.expense_request.update', 'report.expense_request.delete',
        ];
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');

        Role::query()->where('guard_name', 'web')
            ->whereIn(DB::raw('LOWER(name)'), ['admin', 'administrator', 'superadmin', 'super-admin'])
            ->get()->each(fn (Role $role) => $role->givePermissionTo($permissions));

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code', 'report')->first();
        if (! $portal) return;

        $now = now();
        $menuIds = [];
        foreach ($this->menus as $menu) {
            $existing = DB::table('access_menus')->where('code', $menu['code'])->first();
            $menuId = (string) ($existing->id ?? Str::ulid());
            DB::table('access_menus')->updateOrInsert(['code' => $menu['code']], [
                'id' => $menuId,
                'portal_id' => $portal->id,
                'name' => $menu['name'],
                'path' => $menu['path'],
                'sort_order' => $menu['sort_order'],
                'permission_view' => $menu['permission_view'],
                'permission_create' => $menu['permission_create'],
                'permission_update' => $menu['permission_update'],
                'permission_delete' => $menu['permission_delete'],
                'is_active' => true,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ]);
            $menuIds[] = $menuId;
        }

        // User menetapkan Expense Report final di /report/expense-request. Nonaktifkan placeholder duplicate lama.
        DB::table('access_menus')->where('code', 'report-expense-report')->update(['is_active' => false, 'updated_at' => $now]);

        if (! Schema::hasTable('access_role_menu_permissions')) return;
        $sourceRows = DB::table('access_role_menu_permissions')->whereIn('menu_id', $menuIds)->get();
        // Menu Report ini sudah ada pada baseline; jika matrix-nya belum punya row, clone dari portal Report menu lain yang visible.
        if ($sourceRows->isEmpty()) {
            $seedMenuId = DB::table('access_menus')->where('portal_id', $portal->id)->where('is_active', true)->value('id');
            $sourceRows = $seedMenuId ? DB::table('access_role_menu_permissions')->where('menu_id', $seedMenuId)->get() : collect();
        }
        foreach ($menuIds as $menuId) {
            foreach ($sourceRows as $source) {
                $exists = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $source->access_role_id)
                    ->where('menu_id', $menuId)
                    ->when($source->access_level_id === null, fn ($q) => $q->whereNull('access_level_id'), fn ($q) => $q->where('access_level_id', $source->access_level_id))
                    ->exists();
                if ($exists) continue;

                $role = Schema::hasTable('access_roles') ? DB::table('access_roles')->where('id', $source->access_role_id)->first(['code', 'spatie_role_name']) : null;
                $isAdmin = str_contains(strtoupper((string) ($role->code ?? '')), 'ADMIN') || str_contains(strtolower((string) ($role->spatie_role_name ?? '')), 'admin');
                DB::table('access_role_menu_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $source->access_role_id,
                    'access_level_id' => $source->access_level_id,
                    'menu_id' => $menuId,
                    'can_view' => (bool) $source->can_view,
                    'can_create' => $isAdmin,
                    'can_edit' => $isAdmin,
                    'can_delete' => $isAdmin,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Operational reports may become source documents for posted journals.
        // Preserve data; only deactivate menu entries.
        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')->whereIn('code', array_column($this->menus, 'code'))->update(['is_active' => false, 'updated_at' => now()]);
        }
    }
};
