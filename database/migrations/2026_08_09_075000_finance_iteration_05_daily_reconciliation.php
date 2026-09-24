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
    private array $menu = [
        'code' => 'finance-reconciliation',
        'name' => 'Reconciliation',
        'path' => '/finance/reconciliation',
        'sort_order' => 30,
        'permission_view' => 'finance.reconciliation.view',
        'permission_create' => 'finance.reconciliation.create',
        'permission_update' => 'finance.reconciliation.update',
        'permission_delete' => 'finance.reconciliation.delete',
    ];

    public function up(): void
    {
        $this->guardDependencies();
        $this->createTables();
        $this->repairPartialTables();
        $this->seedDefaultTemplates();
        $this->registerPermissionsAndAccessMatrix();
    }

    private function guardDependencies(): void
    {
        foreach ([
            'finance_chart_of_accounts', 'finance_journal_entries', 'finance_posting_templates',
            'finance_posting_template_lines', 'finance_outlet_company_mappings',
            'finance_overhandle_reports', 'finance_overhandle_report_payments',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Finance Iterasi 05 membutuhkan {$table}. Apply Iterasi 03 dan 04 terlebih dahulu.");
            }
        }
    }

    private function createTables(): void
    {
        if (! Schema::hasTable('finance_reconciliations')) {
            Schema::create('finance_reconciliations', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('reconciliation_no', 48)->unique();
                $table->date('business_date')->index();
                $table->string('company_code', 16)->index();
                $table->char('outlet_id', 26)->index();
                $table->string('status', 16)->default('DRAFT')->index();
                $table->char('overhandle_report_id', 26)->nullable()->index();
                $table->boolean('has_overhandle')->default(false);
                $table->decimal('pos_total', 18, 2)->default(0);
                $table->decimal('overhandle_total', 18, 2)->default(0);
                $table->decimal('effective_actual_total', 18, 2)->default(0);
                $table->decimal('pos_discount_total', 18, 2)->default(0);
                $table->decimal('discount_override_amount', 18, 2)->nullable();
                $table->decimal('effective_discount_total', 18, 2)->default(0);
                $table->decimal('pos_tax_total', 18, 2)->default(0);
                $table->decimal('tax_override_amount', 18, 2)->nullable();
                $table->decimal('effective_tax_total', 18, 2)->default(0);
                $table->decimal('pos_rounding_total', 18, 2)->default(0);
                $table->decimal('rounding_override_amount', 18, 2)->nullable();
                $table->decimal('effective_rounding_total', 18, 2)->default(0);
                $table->unsignedInteger('unresolved_payment_count')->default(0);
                $table->string('source_fingerprint', 64);
                $table->json('source_snapshot')->nullable();
                $table->unsignedInteger('posting_version')->default(0);
                $table->text('note')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->char('posted_by_user_id', 26)->nullable()->index();
                $table->char('created_by_user_id', 26)->nullable()->index();
                $table->char('updated_by_user_id', 26)->nullable()->index();
                $table->timestamps();

                $table->unique(['outlet_id', 'business_date'], 'fin_rec_outlet_business_date_uq');
                $table->index(['company_code', 'business_date', 'status'], 'fin_rec_company_date_status_idx');
                $table->foreign('outlet_id', 'fin_rec_outlet_fk')->references('id')->on('outlets')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('finance_reconciliation_payments')) {
            Schema::create('finance_reconciliation_payments', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('reconciliation_id', 26)->index('fin_rec_pay_rec_idx');
                $table->char('payment_method_id', 26)->nullable()->index('fin_rec_pay_method_idx');
                $table->string('payment_method_name', 180);
                $table->decimal('pos_amount', 18, 2)->default(0);
                $table->decimal('overhandle_amount', 18, 2)->default(0);
                $table->decimal('actual_override_amount', 18, 2)->nullable();
                $table->decimal('effective_actual_amount', 18, 2)->default(0);
                $table->decimal('variance_amount', 18, 2)->default(0);
                $table->boolean('requires_actual')->default(false)->index('fin_rec_pay_req_actual_idx');
                $table->text('note')->nullable();
                $table->unsignedInteger('sort_order')->default(1);
                $table->timestamps();

                $table->foreign('reconciliation_id', 'fin_rec_pay_rec_fk')->references('id')->on('finance_reconciliations')->cascadeOnDelete();
                $table->unique(['reconciliation_id', 'payment_method_name'], 'fin_rec_pay_method_uq');
            });
        }

        if (! Schema::hasTable('finance_reconciliation_payment_allocations')) {
            Schema::create('finance_reconciliation_payment_allocations', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('reconciliation_id', 26)->index('fin_rec_alloc_rec_idx');
                $table->char('reconciliation_payment_id', 26)->index('fin_rec_alloc_pay_idx');
                $table->string('marking', 16)->index('fin_rec_alloc_mark_idx');
                $table->decimal('pos_amount', 18, 2)->default(0);
                $table->decimal('effective_actual_amount', 18, 2)->default(0);
                $table->decimal('variance_amount', 18, 2)->default(0);
                $table->timestamps();

                $table->foreign('reconciliation_id', 'fin_rec_alloc_rec_fk')->references('id')->on('finance_reconciliations')->cascadeOnDelete();
                $table->foreign('reconciliation_payment_id', 'fin_rec_alloc_pay_fk')->references('id')->on('finance_reconciliation_payments')->cascadeOnDelete();
                $table->unique(['reconciliation_payment_id', 'marking'], 'fin_rec_alloc_pay_mark_uq');
            });
        }

        if (! Schema::hasTable('finance_reconciliation_scope_summaries')) {
            Schema::create('finance_reconciliation_scope_summaries', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('reconciliation_id', 26)->index('fin_rec_scope_rec_idx');
                $table->string('marking', 16)->index('fin_rec_scope_mark_idx');
                $table->unsignedInteger('transaction_count')->default(0);
                $table->decimal('pos_total', 18, 2)->default(0);
                $table->decimal('effective_actual_total', 18, 2)->default(0);
                $table->decimal('discount_total', 18, 2)->default(0);
                $table->decimal('tax_total', 18, 2)->default(0);
                $table->decimal('rounding_total', 18, 2)->default(0);
                $table->decimal('revenue_total', 18, 2)->default(0);
                $table->decimal('variance_shortage', 18, 2)->default(0);
                $table->decimal('variance_overage', 18, 2)->default(0);
                $table->char('journal_entry_id', 26)->nullable()->index('fin_rec_scope_journal_idx');
                $table->string('journal_no', 48)->nullable()->index('fin_rec_scope_jno_idx');
                $table->timestamps();

                $table->foreign('reconciliation_id', 'fin_rec_scope_rec_fk')->references('id')->on('finance_reconciliations')->cascadeOnDelete();
                $table->unique(['reconciliation_id', 'marking'], 'fin_rec_scope_mark_uq');
            });
        }

        if (! Schema::hasTable('finance_reconciliation_postings')) {
            Schema::create('finance_reconciliation_postings', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('reconciliation_id', 26)->index('fin_rec_post_rec_idx');
                $table->unsignedInteger('posting_version')->default(1)->index('fin_rec_post_ver_idx');
                $table->string('marking', 16)->index('fin_rec_post_mark_idx');
                $table->char('journal_entry_id', 26)->index('fin_rec_post_journal_idx');
                $table->string('journal_no', 48)->index('fin_rec_post_jno_idx');
                $table->char('reversal_journal_id', 26)->nullable()->index('fin_rec_post_rev_journal_idx');
                $table->string('reversal_journal_no', 48)->nullable()->index('fin_rec_post_rev_jno_idx');
                $table->timestamp('posted_at');
                $table->timestamp('reversed_at')->nullable();
                $table->timestamps();

                $table->foreign('reconciliation_id', 'fin_rec_post_rec_fk')->references('id')->on('finance_reconciliations')->cascadeOnDelete();
                $table->foreign('journal_entry_id', 'fin_rec_post_journal_fk')->references('id')->on('finance_journal_entries')->restrictOnDelete();
                $table->unique(['reconciliation_id', 'posting_version', 'marking'], 'fin_rec_post_version_mark_uq');
            });
        }
    }

    /**
     * MySQL DDL is not fully transactional. A failed Schema::create may leave
     * the table itself behind while later ALTER INDEX / FOREIGN KEY commands
     * were never executed. Because this migration is then retried and guarded
     * by Schema::hasTable(), we must repair any partially-created Iterasi 05
     * tables instead of assuming "table exists" means "schema complete".
     */
    private function repairPartialTables(): void
    {
        $this->ensureIndex('finance_reconciliation_payments', 'fin_rec_pay_rec_idx', ['reconciliation_id']);
        $this->ensureIndex('finance_reconciliation_payments', 'fin_rec_pay_method_idx', ['payment_method_id']);
        $this->ensureIndex('finance_reconciliation_payments', 'fin_rec_pay_req_actual_idx', ['requires_actual']);
        $this->ensureIndex('finance_reconciliation_payments', 'fin_rec_pay_method_uq', ['reconciliation_id', 'payment_method_name'], true);
        $this->ensureForeign('finance_reconciliation_payments', 'reconciliation_id', 'finance_reconciliations', 'id', 'fin_rec_pay_rec_fk', 'CASCADE');

        $this->ensureIndex('finance_reconciliation_payment_allocations', 'fin_rec_alloc_rec_idx', ['reconciliation_id']);
        $this->ensureIndex('finance_reconciliation_payment_allocations', 'fin_rec_alloc_pay_idx', ['reconciliation_payment_id']);
        $this->ensureIndex('finance_reconciliation_payment_allocations', 'fin_rec_alloc_mark_idx', ['marking']);
        $this->ensureIndex('finance_reconciliation_payment_allocations', 'fin_rec_alloc_pay_mark_uq', ['reconciliation_payment_id', 'marking'], true);
        $this->ensureForeign('finance_reconciliation_payment_allocations', 'reconciliation_id', 'finance_reconciliations', 'id', 'fin_rec_alloc_rec_fk', 'CASCADE');
        $this->ensureForeign('finance_reconciliation_payment_allocations', 'reconciliation_payment_id', 'finance_reconciliation_payments', 'id', 'fin_rec_alloc_pay_fk', 'CASCADE');

        $this->ensureIndex('finance_reconciliation_scope_summaries', 'fin_rec_scope_rec_idx', ['reconciliation_id']);
        $this->ensureIndex('finance_reconciliation_scope_summaries', 'fin_rec_scope_mark_idx', ['marking']);
        $this->ensureIndex('finance_reconciliation_scope_summaries', 'fin_rec_scope_journal_idx', ['journal_entry_id']);
        $this->ensureIndex('finance_reconciliation_scope_summaries', 'fin_rec_scope_jno_idx', ['journal_no']);
        $this->ensureIndex('finance_reconciliation_scope_summaries', 'fin_rec_scope_mark_uq', ['reconciliation_id', 'marking'], true);
        $this->ensureForeign('finance_reconciliation_scope_summaries', 'reconciliation_id', 'finance_reconciliations', 'id', 'fin_rec_scope_rec_fk', 'CASCADE');

        $this->ensureIndex('finance_reconciliation_postings', 'fin_rec_post_rec_idx', ['reconciliation_id']);
        $this->ensureIndex('finance_reconciliation_postings', 'fin_rec_post_ver_idx', ['posting_version']);
        $this->ensureIndex('finance_reconciliation_postings', 'fin_rec_post_mark_idx', ['marking']);
        $this->ensureIndex('finance_reconciliation_postings', 'fin_rec_post_journal_idx', ['journal_entry_id']);
        $this->ensureIndex('finance_reconciliation_postings', 'fin_rec_post_jno_idx', ['journal_no']);
        $this->ensureIndex('finance_reconciliation_postings', 'fin_rec_post_rev_journal_idx', ['reversal_journal_id']);
        $this->ensureIndex('finance_reconciliation_postings', 'fin_rec_post_rev_jno_idx', ['reversal_journal_no']);
        $this->ensureIndex('finance_reconciliation_postings', 'fin_rec_post_version_mark_uq', ['reconciliation_id', 'posting_version', 'marking'], true);
        $this->ensureForeign('finance_reconciliation_postings', 'reconciliation_id', 'finance_reconciliations', 'id', 'fin_rec_post_rec_fk', 'CASCADE');
        $this->ensureForeign('finance_reconciliation_postings', 'journal_entry_id', 'finance_journal_entries', 'id', 'fin_rec_post_journal_fk', 'RESTRICT');
    }

    private function ensureIndex(string $table, string $name, array $columns, bool $unique = false): void
    {
        if (! Schema::hasTable($table)) return;

        $rows = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->orderBy('INDEX_NAME')
            ->orderBy('SEQ_IN_INDEX')
            ->get([
                'INDEX_NAME as index_name',
                'COLUMN_NAME as column_name',
                'NON_UNIQUE as non_unique',
            ]);

        $grouped = $rows->groupBy('index_name');
        foreach ($grouped as $indexRows) {
            $actualColumns = $indexRows->pluck('column_name')->map(fn ($v) => (string) $v)->values()->all();
            $isUnique = ((int) ($indexRows->first()->non_unique ?? 1)) === 0;
            if ($actualColumns === $columns && (! $unique || $isUnique)) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name, $columns, $unique): void {
            $unique ? $blueprint->unique($columns, $name) : $blueprint->index($columns, $name);
        });
    }

    private function ensureForeign(
        string $table,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        string $name,
        string $deleteRule
    ): void {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) return;

        $exists = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->where('REFERENCED_TABLE_NAME', $referencedTable)
            ->where('REFERENCED_COLUMN_NAME', $referencedColumn)
            ->exists();
        if ($exists) return;

        Schema::table($table, function (Blueprint $blueprint) use ($column, $referencedTable, $referencedColumn, $name, $deleteRule): void {
            $foreign = $blueprint->foreign($column, $name)->references($referencedColumn)->on($referencedTable);
            match ($deleteRule) {
                'CASCADE' => $foreign->cascadeOnDelete(),
                'SET NULL' => $foreign->nullOnDelete(),
                default => $foreign->restrictOnDelete(),
            };
        });
    }

    private function seedDefaultTemplates(): void
    {
        $accounts = DB::table('finance_chart_of_accounts')->whereIn('code', [
            '1-10400', '4-40000', '4-40100', '2-20603', '7-70099', '8-80999',
        ])->get()->keyBy('code');
        $missing = collect(['1-10400','4-40000','4-40100','2-20603','7-70099','8-80999'])->reject(fn ($code) => $accounts->has($code))->values()->all();
        if ($missing) {
            throw new RuntimeException('COA default Reconciliation tidak lengkap: '.implode(', ', $missing));
        }

        foreach (['MARKING', 'UNMARKING'] as $marking) {
            $code = 'RECON_DEFAULT_'.$marking;
            $existing = DB::table('finance_posting_templates')->where('code', $code)->first();
            if ($existing) continue;
            $templateId = (string) Str::ulid();
            DB::table('finance_posting_templates')->insert([
                'id' => $templateId,
                'code' => $code,
                'name' => 'Default Reconciliation '.$marking,
                'source_type' => 'RECONCILIATION',
                'company_code' => null,
                'outlet_id' => null,
                'marking' => $marking,
                'description' => 'Template default. Dapat diganti dengan template PT/outlet yang lebih spesifik.',
                'is_active' => true,
                'created_by_user_id' => null,
                'updated_by_user_id' => null,
                'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
            ]);
            $rows = [
                [1, '1-10400', 'DEBIT', '{{actual_total}}', 'Dana Belum Disetor - {{marking}}', 'clearing'],
                [2, '4-40100', 'DEBIT', '{{discount_total}}', 'Diskon Penjualan - {{marking}}', 'discount'],
                [3, '8-80999', 'DEBIT', '{{rounding_negative}}', 'Pembulatan Negatif - {{marking}}', 'rounding_negative'],
                [4, '8-80999', 'DEBIT', '{{variance_shortage}}', 'Selisih Kurang Reconciliation - {{marking}}', 'variance_shortage'],
                [5, '4-40000', 'CREDIT', '{{revenue_total}}', 'Pendapatan Outlet - {{marking}}', 'revenue'],
                [6, '2-20603', 'CREDIT', '{{tax_total}}', 'Pajak PB1 - {{marking}}', 'tax'],
                [7, '7-70099', 'CREDIT', '{{rounding_positive}}', 'Pembulatan Positif - {{marking}}', 'rounding_positive'],
                [8, '7-70099', 'CREDIT', '{{variance_overage}}', 'Selisih Lebih Reconciliation - {{marking}}', 'variance_overage'],
            ];
            foreach ($rows as [$sort, $accountCode, $side, $formula, $memo, $role]) {
                DB::table('finance_posting_template_lines')->insert([
                    'id' => (string) Str::ulid(),
                    'template_id' => $templateId,
                    'sort_order' => $sort,
                    'account_id' => (string) $accounts[$accountCode]->id,
                    'side' => $side,
                    'amount_formula' => $formula,
                    'memo_template' => $memo,
                    'meta' => json_encode(['iteration' => 5, 'default' => true, 'role' => $role]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function registerPermissionsAndAccessMatrix(): void
    {
        $permissions = [
            'finance.reconciliation.view', 'finance.reconciliation.create', 'finance.reconciliation.update',
            'finance.reconciliation.delete', 'finance.reconciliation.post', 'finance.reconciliation.reopen',
        ];
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');
        Role::query()->where('guard_name', 'web')
            ->whereIn(DB::raw('LOWER(name)'), ['admin', 'administrator', 'superadmin', 'super-admin'])
            ->get()->each(fn (Role $role) => $role->givePermissionTo($permissions));

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code', 'finance')->first();
        if (! $portal) return;
        $now = now();
        $existing = DB::table('access_menus')->where('code', $this->menu['code'])->first();
        $menuId = (string) ($existing->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(['code' => $this->menu['code']], [
            'id' => $menuId,
            'portal_id' => $portal->id,
            'name' => $this->menu['name'],
            'path' => $this->menu['path'],
            'sort_order' => $this->menu['sort_order'],
            'permission_view' => $this->menu['permission_view'],
            'permission_create' => $this->menu['permission_create'],
            'permission_update' => $this->menu['permission_update'],
            'permission_delete' => $this->menu['permission_delete'],
            'is_active' => true,
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ]);

        if (! Schema::hasTable('access_role_menu_permissions')) return;
        $sourceMenuId = DB::table('access_menus')->where('code', 'finance-dashboard')->value('id');
        $sourceRows = $sourceMenuId ? DB::table('access_role_menu_permissions')->where('menu_id', $sourceMenuId)->get() : collect();
        foreach ($sourceRows as $source) {
            $q = DB::table('access_role_menu_permissions')->where('access_role_id', $source->access_role_id)->where('menu_id', $menuId);
            $source->access_level_id === null ? $q->whereNull('access_level_id') : $q->where('access_level_id', $source->access_level_id);
            if ($q->exists()) continue;
            $role = Schema::hasTable('access_roles') ? DB::table('access_roles')->where('id', $source->access_role_id)->first(['code','spatie_role_name']) : null;
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
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')->where('code', $this->menu['code'])->update(['is_active' => false, 'updated_at' => now()]);
        }
    }
};
