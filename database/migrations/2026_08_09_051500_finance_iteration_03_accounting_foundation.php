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
            'code' => 'finance-chart-of-accounts',
            'name' => 'Chart of Account',
            'path' => '/finance/chart-of-accounts',
            'sort_order' => 20,
            'permission_view' => 'finance.coa.view',
            'permission_create' => 'finance.coa.create',
            'permission_update' => 'finance.coa.update',
            'permission_delete' => 'finance.coa.delete',
        ],
        [
            'code' => 'finance-manual-journal',
            'name' => 'Manual Journal',
            'path' => '/finance/manual-journal',
            'sort_order' => 25,
            'permission_view' => 'finance.journal.view',
            'permission_create' => 'finance.journal.create',
            'permission_update' => 'finance.journal.update',
            'permission_delete' => 'finance.journal.delete',
        ],
        [
            'code' => 'finance-journal-templates',
            'name' => 'Jurnal Template',
            'path' => '/finance/journal-templates',
            'sort_order' => 27,
            'permission_view' => 'finance.journal_template.view',
            'permission_create' => 'finance.journal_template.create',
            'permission_update' => 'finance.journal_template.update',
            'permission_delete' => 'finance.journal_template.delete',
        ],
    ];

    public function up(): void
    {
        $this->createAccountingTables();
        $this->seedCompanies();
        $this->seedLegacyChartOfAccounts();
        $this->seedInitialOutletCompanyMappings();
        $this->registerPermissionsAndAccessMatrix();
    }

    private function createAccountingTables(): void
    {
        if (! Schema::hasTable('finance_companies')) {
            Schema::create('finance_companies', function (Blueprint $table): void {
                $table->string('code', 16)->primary();
                $table->string('name', 160);
                $table->string('legal_name', 220)->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('finance_outlet_company_mappings')) {
            Schema::create('finance_outlet_company_mappings', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('outlet_id', 26)->unique();
                $table->string('company_code', 16)->index();
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->char('updated_by_user_id', 26)->nullable()->index();
                $table->timestamps();

                $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('finance_chart_of_accounts')) {
            Schema::create('finance_chart_of_accounts', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('code', 32)->unique();
                $table->string('name', 180);
                $table->string('account_type', 40)->index();
                $table->string('normal_balance', 12)->default('DEBIT');
                $table->char('parent_id', 26)->nullable()->index();
                $table->unsignedTinyInteger('level_no')->default(1);
                $table->boolean('is_header')->default(false);
                $table->boolean('is_postable')->default(true);
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();

                $table->foreign('parent_id')->references('id')->on('finance_chart_of_accounts')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('finance_journal_entries')) {
            Schema::create('finance_journal_entries', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('journal_no', 48)->unique();
                $table->date('journal_date')->index();
                $table->date('business_date')->index();
                $table->string('company_code', 16)->index();
                $table->char('outlet_id', 26)->nullable()->index();
                $table->string('marking', 16)->default('MARKING')->index();
                $table->string('source_type', 40)->default('MANUAL')->index();
                $table->char('source_id', 26)->nullable()->index();
                $table->string('source_key', 191)->nullable()->unique();
                $table->string('reference_no', 120)->nullable()->index();
                $table->text('description')->nullable();
                $table->string('status', 16)->default('DRAFT')->index();
                $table->decimal('total_debit', 18, 2)->default(0);
                $table->decimal('total_credit', 18, 2)->default(0);
                $table->char('reversal_of_journal_id', 26)->nullable()->index();
                $table->char('reversal_journal_id', 26)->nullable()->index();
                $table->timestamp('posted_at')->nullable();
                $table->char('posted_by_user_id', 26)->nullable()->index();
                $table->timestamp('reversed_at')->nullable();
                $table->char('reversed_by_user_id', 26)->nullable()->index();
                $table->char('created_by_user_id', 26)->nullable()->index();
                $table->char('updated_by_user_id', 26)->nullable()->index();
                $table->json('source_meta')->nullable();
                $table->timestamps();

                $table->index(['company_code', 'business_date', 'marking'], 'fin_journal_company_date_marking_idx');
                $table->index(['outlet_id', 'business_date', 'marking'], 'fin_journal_outlet_date_marking_idx');
                $table->index(['source_type', 'source_id'], 'fin_journal_source_idx');
                $table->foreign('outlet_id')->references('id')->on('outlets')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('finance_journal_entry_lines')) {
            Schema::create('finance_journal_entry_lines', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('journal_entry_id', 26)->index();
                $table->char('account_id', 26)->index();
                $table->unsignedInteger('line_no')->default(1);
                $table->string('account_code', 32)->index();
                $table->string('account_name', 180);
                $table->string('account_type', 40)->index();
                $table->string('normal_balance', 12)->default('DEBIT');
                $table->text('description')->nullable();
                $table->decimal('debit', 18, 2)->default(0);
                $table->decimal('credit', 18, 2)->default(0);
                $table->timestamps();

                $table->foreign('journal_entry_id')->references('id')->on('finance_journal_entries')->cascadeOnDelete();
                $table->foreign('account_id')->references('id')->on('finance_chart_of_accounts')->restrictOnDelete();
                $table->index(['journal_entry_id', 'line_no'], 'fin_journal_lines_entry_line_idx');
            });
        }

        if (! Schema::hasTable('finance_posting_templates')) {
            Schema::create('finance_posting_templates', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('code', 60)->unique();
                $table->string('name', 160);
                $table->string('source_type', 80)->index();
                $table->string('company_code', 16)->nullable()->index();
                $table->char('outlet_id', 26)->nullable()->index();
                $table->string('marking', 16)->nullable()->index();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->char('created_by_user_id', 26)->nullable()->index();
                $table->char('updated_by_user_id', 26)->nullable()->index();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('outlet_id')->references('id')->on('outlets')->nullOnDelete();
                $table->index(['source_type', 'company_code', 'outlet_id'], 'fin_tpl_source_scope_idx');
            });
        }

        if (! Schema::hasTable('finance_posting_template_lines')) {
            Schema::create('finance_posting_template_lines', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('template_id', 26)->index();
                $table->unsignedInteger('sort_order')->default(1);
                $table->char('account_id', 26)->index();
                $table->string('side', 8);
                $table->string('amount_formula', 180)->default('{{amount}}');
                $table->string('memo_template', 255)->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->foreign('template_id')->references('id')->on('finance_posting_templates')->cascadeOnDelete();
                $table->foreign('account_id')->references('id')->on('finance_chart_of_accounts')->restrictOnDelete();
                $table->index(['template_id', 'sort_order'], 'fin_tpl_lines_sort_idx');
            });
        }
    }

    private function seedCompanies(): void
    {
        $now = now();
        foreach ([
            ['code' => 'BKJB', 'name' => 'PT BKJB'],
            ['code' => 'MDMF', 'name' => 'PT MDMF'],
        ] as $company) {
            DB::table('finance_companies')->updateOrInsert(
                ['code' => $company['code']],
                [
                    'name' => $company['name'],
                    'is_active' => true,
                    'created_at' => DB::table('finance_companies')->where('code', $company['code'])->value('created_at') ?: $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function seedLegacyChartOfAccounts(): void
    {
        $seedFile = database_path('data/finance_iter03_legacy_coa.php');
        if (! is_file($seedFile)) {
            return;
        }

        $now = now();
        $rows = require $seedFile;
        foreach ($rows as $row) {
            $existing = DB::table('finance_chart_of_accounts')->where('code', $row['code'])->first();
            $payload = [
                'name' => $row['name'],
                'account_type' => $row['account_type'],
                'normal_balance' => $row['normal_balance'],
                'level_no' => (int) ($existing->level_no ?? 1),
                'is_header' => (bool) ($existing->is_header ?? false),
                'is_postable' => (bool) ($existing->is_postable ?? true),
                'is_active' => (bool) ($existing->is_active ?? true),
                'updated_at' => $now,
            ];

            if ($existing) {
                DB::table('finance_chart_of_accounts')->where('id', $existing->id)->update($payload);
                continue;
            }

            DB::table('finance_chart_of_accounts')->insert($payload + [
                'id' => (string) Str::ulid(),
                'code' => $row['code'],
                'parent_id' => null,
                'created_at' => $now,
            ]);
        }
    }

    private function seedInitialOutletCompanyMappings(): void
    {
        if (! Schema::hasTable('outlets')) {
            return;
        }

        // Legacy names are used ONLY for one-time bootstrap. Runtime reporting
        // resolves the explicit finance_outlet_company_mappings table instead.
        $legacyMap = [
            'MDMF' => ['TKJ, Bandung', 'TKJ, Banjarmasin', 'TKJ, FIA UB', 'TKJ, Kuta', 'TKJ, Tenes'],
            'BKJB' => ['Cafetaria Jaya', 'JBDM Klojen', 'JBDM Sawojajar', 'Medcafe', 'TKJ, Begawan', 'TKJ, Brawijaya', 'TKJ, Denpasar', 'TKJ, Ijen', 'TKJ, Kepundung', 'TKJ, MOG', 'TKJ, Smoore', 'TKJ, Soehat', 'TKJ, Sukun'],
        ];

        $now = now();
        foreach ($legacyMap as $companyCode => $names) {
            $outlets = DB::table('outlets')->whereIn('name', $names)->get(['id']);
            foreach ($outlets as $outlet) {
                $existing = DB::table('finance_outlet_company_mappings')->where('outlet_id', $outlet->id)->first();
                if ($existing) {
                    continue;
                }

                DB::table('finance_outlet_company_mappings')->insert([
                    'id' => (string) Str::ulid(),
                    'outlet_id' => (string) $outlet->id,
                    'company_code' => $companyCode,
                    'effective_from' => null,
                    'effective_to' => null,
                    'is_active' => true,
                    'updated_by_user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function registerPermissionsAndAccessMatrix(): void
    {
        $permissions = [
            'finance.coa.view', 'finance.coa.create', 'finance.coa.update', 'finance.coa.delete',
            'finance.journal.view', 'finance.journal.create', 'finance.journal.update', 'finance.journal.delete', 'finance.journal.post', 'finance.journal.reverse',
            'finance.journal_template.view', 'finance.journal_template.create', 'finance.journal_template.update', 'finance.journal_template.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::query()
            ->where('guard_name', 'web')
            ->whereIn(DB::raw('LOWER(name)'), ['admin', 'administrator', 'superadmin', 'super-admin'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $now = now();
        $portal = DB::table('access_portals')->where('code', 'finance')->first();
        if (! $portal) {
            return;
        }

        $menuIds = [];
        foreach ($this->menus as $menu) {
            $existing = DB::table('access_menus')->where('code', $menu['code'])->first();
            $menuId = (string) ($existing->id ?? Str::ulid());
            DB::table('access_menus')->updateOrInsert(
                ['code' => $menu['code']],
                [
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
                ]
            );
            $menuIds[$menu['code']] = $menuId;
        }

        if (! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $dashboardId = DB::table('access_menus')->where('code', 'finance-dashboard')->value('id');
        $dashboardRows = $dashboardId
            ? DB::table('access_role_menu_permissions')->where('menu_id', $dashboardId)->get()
            : collect();

        foreach ($menuIds as $menuId) {
            foreach ($dashboardRows as $source) {
                $query = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $source->access_role_id)
                    ->where('menu_id', $menuId);
                $source->access_level_id === null
                    ? $query->whereNull('access_level_id')
                    : $query->where('access_level_id', $source->access_level_id);

                if ($query->exists()) {
                    continue;
                }

                $role = Schema::hasTable('access_roles')
                    ? DB::table('access_roles')->where('id', $source->access_role_id)->first(['code', 'spatie_role_name'])
                    : null;
                $roleCode = strtoupper((string) ($role->code ?? ''));
                $spatieRole = strtolower((string) ($role->spatie_role_name ?? ''));
                $isAdmin = str_contains($roleCode, 'ADMIN') || str_contains($spatieRole, 'admin');

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
        // Accounting journals are audit data. Down() intentionally does not drop
        // canonical finance tables or posted data automatically.
        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')->whereIn('code', array_column($this->menus, 'code'))->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
        }
    }
};
