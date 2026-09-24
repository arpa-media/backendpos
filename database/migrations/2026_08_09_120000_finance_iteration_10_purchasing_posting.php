<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            'pur_finance_posting_outbox',
            'pur_invoices',
            'pur_invoice_items',
            'pur_invoice_payments',
            'finance_chart_of_accounts',
            'finance_journal_entries',
            'finance_journal_entry_lines',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Finance Iterasi 10 membutuhkan {$table}.");
            }
        }

        $this->createTables();
        $this->ensureIndexes();
        $this->ensureForeignKeys();
        $this->seedMappings();
        $this->registerAccess();
    }

    public function down(): void
    {
        if (Schema::hasTable('access_menus')) {
            $id = DB::table('access_menus')->where('code', 'finance-purchasing-posting')->value('id');
            if ($id && Schema::hasTable('access_role_menu_permissions')) {
                DB::table('access_role_menu_permissions')->where('menu_id', $id)->delete();
            }
            DB::table('access_menus')->where('code', 'finance-purchasing-posting')->delete();
        }

        Schema::dropIfExists('finance_purchasing_posting_journals');
        Schema::dropIfExists('finance_purchasing_posting_allocations');
        Schema::dropIfExists('finance_purchasing_posting_lines');
        Schema::dropIfExists('finance_purchasing_postings');
        Schema::dropIfExists('finance_purchasing_payment_mappings');
        Schema::dropIfExists('finance_purchasing_posting_mappings');
    }

    /**
     * Create columns first. Indexes/FKs are intentionally installed separately
     * using short explicit names so MySQL's 64-character identifier limit is
     * never hit, and rerunning after a partial failed migration is safe.
     */
    private function createTables(): void
    {
        if (! Schema::hasTable('finance_purchasing_posting_mappings')) {
            Schema::create('finance_purchasing_posting_mappings', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->string('mapping_key', 120);
                $t->string('company_code', 16);
                $t->char('outlet_id', 26)->nullable();
                $t->string('source_document_kind', 50);
                $t->char('default_debit_account_id', 26);
                $t->char('ap_account_id', 26);
                $t->char('tax_account_id', 26);
                $t->string('default_marking', 16)->default('MARKING');
                $t->boolean('is_active')->default(true);
                $t->text('notes')->nullable();
                $t->char('created_by_user_id', 26)->nullable();
                $t->char('updated_by_user_id', 26)->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('finance_purchasing_payment_mappings')) {
            Schema::create('finance_purchasing_payment_mappings', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->string('mapping_key', 120);
                $t->string('company_code', 16);
                $t->char('outlet_id', 26)->nullable();
                $t->string('payment_method', 40);
                $t->char('cash_account_id', 26);
                $t->boolean('is_active')->default(true);
                $t->text('notes')->nullable();
                $t->char('created_by_user_id', 26)->nullable();
                $t->char('updated_by_user_id', 26)->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('finance_purchasing_postings')) {
            Schema::create('finance_purchasing_postings', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->string('posting_no', 80);
                $t->char('outbox_id', 26);
                $t->string('event_key', 191);
                $t->string('event_type', 80);
                $t->char('invoice_id', 26);
                $t->char('payment_id', 26)->nullable();
                $t->string('source_document_kind', 50);
                $t->string('source_document_id', 64);
                $t->string('source_document_number', 100)->nullable();
                $t->string('company_code', 16);
                $t->char('outlet_id', 26);
                $t->char('supplier_source_id', 26)->nullable();
                $t->string('supplier_name', 180);
                $t->string('supplier_type', 40)->nullable();
                $t->char('warehouse_id', 26)->nullable();
                $t->string('warehouse_name', 180)->nullable();
                $t->string('counterparty_name', 180);
                $t->char('issue_mapping_id', 26);
                $t->char('payment_mapping_id', 26)->nullable();
                $t->date('business_date');
                $t->date('journal_date');
                $t->string('source_fingerprint', 64);
                $t->string('status', 16)->default('DRAFT');
                $t->unsignedInteger('posting_version')->default(0);
                $t->timestamp('posted_at')->nullable();
                $t->char('posted_by_user_id', 26)->nullable();
                $t->timestamp('reopened_at')->nullable();
                $t->char('reopened_by_user_id', 26)->nullable();
                $t->text('reopen_reason')->nullable();
                $t->char('created_by_user_id', 26)->nullable();
                $t->char('updated_by_user_id', 26)->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('finance_purchasing_posting_lines')) {
            Schema::create('finance_purchasing_posting_lines', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->char('purchasing_posting_id', 26);
                $t->char('invoice_item_id', 26)->nullable();
                $t->unsignedSmallInteger('line_no');
                $t->string('item_name', 255);
                $t->char('sku_id', 26)->nullable();
                $t->decimal('subtotal_value', 20, 2)->default(0);
                $t->decimal('tax_value', 20, 2)->default(0);
                $t->char('target_account_id', 26);
                $t->string('marking', 16);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('finance_purchasing_posting_allocations')) {
            Schema::create('finance_purchasing_posting_allocations', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->char('purchasing_posting_id', 26);
                $t->string('marking', 16);
                $t->decimal('amount', 20, 2)->default(0);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('finance_purchasing_posting_journals')) {
            Schema::create('finance_purchasing_posting_journals', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->char('purchasing_posting_id', 26);
                $t->unsignedInteger('posting_version');
                $t->string('marking', 16);
                $t->char('journal_entry_id', 26);
                $t->string('journal_no', 48);
                $t->char('reversal_journal_id', 26)->nullable();
                $t->string('reversal_journal_no', 48)->nullable();
                $t->timestamp('posted_at');
                $t->timestamp('reversed_at')->nullable();
                $t->timestamps();
            });
        }
    }

    private function ensureIndexes(): void
    {
        $indexes = [
            // mapping
            ['finance_purchasing_posting_mappings', ['mapping_key'], 'fin_pur_map_key_uq', true],
            ['finance_purchasing_posting_mappings', ['company_code'], 'fin_pur_map_company_idx', false],
            ['finance_purchasing_posting_mappings', ['outlet_id'], 'fin_pur_map_outlet_idx', false],
            ['finance_purchasing_posting_mappings', ['source_document_kind'], 'fin_pur_map_source_idx', false],
            ['finance_purchasing_posting_mappings', ['default_debit_account_id'], 'fin_pur_map_debit_idx', false],
            ['finance_purchasing_posting_mappings', ['ap_account_id'], 'fin_pur_map_ap_idx', false],
            ['finance_purchasing_posting_mappings', ['tax_account_id'], 'fin_pur_map_tax_idx', false],
            ['finance_purchasing_posting_mappings', ['default_marking'], 'fin_pur_map_mark_idx', false],
            ['finance_purchasing_posting_mappings', ['is_active'], 'fin_pur_map_active_idx', false],
            ['finance_purchasing_posting_mappings', ['created_by_user_id'], 'fin_pur_map_created_idx', false],
            ['finance_purchasing_posting_mappings', ['updated_by_user_id'], 'fin_pur_map_updated_idx', false],

            // payment mapping
            ['finance_purchasing_payment_mappings', ['mapping_key'], 'fin_pur_paymap_key_uq', true],
            ['finance_purchasing_payment_mappings', ['company_code'], 'fin_pur_paymap_company_idx', false],
            ['finance_purchasing_payment_mappings', ['outlet_id'], 'fin_pur_paymap_outlet_idx', false],
            ['finance_purchasing_payment_mappings', ['payment_method'], 'fin_pur_paymap_method_idx', false],
            ['finance_purchasing_payment_mappings', ['cash_account_id'], 'fin_pur_paymap_cash_idx', false],
            ['finance_purchasing_payment_mappings', ['is_active'], 'fin_pur_paymap_active_idx', false],
            ['finance_purchasing_payment_mappings', ['created_by_user_id'], 'fin_pur_paymap_created_idx', false],
            ['finance_purchasing_payment_mappings', ['updated_by_user_id'], 'fin_pur_paymap_updated_idx', false],

            // posting header
            ['finance_purchasing_postings', ['posting_no'], 'fin_pur_post_no_uq', true],
            ['finance_purchasing_postings', ['outbox_id'], 'fin_pur_post_outbox_uq', true],
            ['finance_purchasing_postings', ['event_key'], 'fin_pur_post_event_key_uq', true],
            ['finance_purchasing_postings', ['event_type'], 'fin_pur_post_event_idx', false],
            ['finance_purchasing_postings', ['invoice_id'], 'fin_pur_post_invoice_idx', false],
            ['finance_purchasing_postings', ['payment_id'], 'fin_pur_post_payment_idx', false],
            ['finance_purchasing_postings', ['source_document_kind'], 'fin_pur_post_source_kind_idx', false],
            ['finance_purchasing_postings', ['source_document_id'], 'fin_pur_post_source_id_idx', false],
            ['finance_purchasing_postings', ['company_code'], 'fin_pur_post_company_idx', false],
            ['finance_purchasing_postings', ['outlet_id'], 'fin_pur_post_outlet_idx', false],
            ['finance_purchasing_postings', ['supplier_source_id'], 'fin_pur_post_supplier_idx', false],
            ['finance_purchasing_postings', ['supplier_type'], 'fin_pur_post_supplier_type_idx', false],
            ['finance_purchasing_postings', ['warehouse_id'], 'fin_pur_post_wh_idx', false],
            ['finance_purchasing_postings', ['issue_mapping_id'], 'fin_pur_post_issue_map_idx', false],
            ['finance_purchasing_postings', ['payment_mapping_id'], 'fin_pur_post_pay_map_idx', false],
            ['finance_purchasing_postings', ['business_date'], 'fin_pur_post_bus_date_idx', false],
            ['finance_purchasing_postings', ['journal_date'], 'fin_pur_post_jrn_date_idx', false],
            ['finance_purchasing_postings', ['status'], 'fin_pur_post_status_idx', false],
            ['finance_purchasing_postings', ['posted_by_user_id'], 'fin_pur_post_posted_by_idx', false],
            ['finance_purchasing_postings', ['reopened_by_user_id'], 'fin_pur_post_reopen_by_idx', false],
            ['finance_purchasing_postings', ['created_by_user_id'], 'fin_pur_post_created_by_idx', false],
            ['finance_purchasing_postings', ['updated_by_user_id'], 'fin_pur_post_updated_by_idx', false],
            ['finance_purchasing_postings', ['invoice_id','event_type','status'], 'fin_pur_post_invoice_event_idx', false],
            ['finance_purchasing_postings', ['company_code','journal_date','status'], 'fin_pur_post_company_date_idx', false],

            // lines
            ['finance_purchasing_posting_lines', ['purchasing_posting_id'], 'fin_pur_line_post_idx', false],
            ['finance_purchasing_posting_lines', ['invoice_item_id'], 'fin_pur_line_invitem_idx', false],
            ['finance_purchasing_posting_lines', ['sku_id'], 'fin_pur_line_sku_idx', false],
            ['finance_purchasing_posting_lines', ['target_account_id'], 'fin_pur_line_acc_idx', false],
            ['finance_purchasing_posting_lines', ['marking'], 'fin_pur_line_mark_idx', false],
            ['finance_purchasing_posting_lines', ['purchasing_posting_id','line_no'], 'fin_pur_line_no_uq', true],

            // allocations
            ['finance_purchasing_posting_allocations', ['purchasing_posting_id'], 'fin_pur_alloc_post_idx', false],
            ['finance_purchasing_posting_allocations', ['marking'], 'fin_pur_alloc_mark_idx', false],
            ['finance_purchasing_posting_allocations', ['purchasing_posting_id','marking'], 'fin_pur_alloc_mark_uq', true],

            // journals
            ['finance_purchasing_posting_journals', ['purchasing_posting_id'], 'fin_pur_j_post_idx', false],
            ['finance_purchasing_posting_journals', ['posting_version'], 'fin_pur_j_ver_idx', false],
            ['finance_purchasing_posting_journals', ['marking'], 'fin_pur_j_mark_idx', false],
            ['finance_purchasing_posting_journals', ['journal_entry_id'], 'fin_pur_j_entry_idx', false],
            ['finance_purchasing_posting_journals', ['journal_no'], 'fin_pur_j_no_idx', false],
            ['finance_purchasing_posting_journals', ['reversal_journal_id'], 'fin_pur_j_rev_idx', false],
            ['finance_purchasing_posting_journals', ['reversal_journal_no'], 'fin_pur_j_rev_no_idx', false],
            ['finance_purchasing_posting_journals', ['purchasing_posting_id','posting_version','marking'], 'fin_pur_j_ver_mark_uq', true],
        ];

        foreach ($indexes as [$table, $columns, $name, $unique]) {
            $this->ensureIndex($table, $columns, $name, $unique);
        }
    }

    /** @param array<int,string> $columns */
    private function ensureIndex(string $table, array $columns, string $name, bool $unique): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $name)) {
            return;
        }

        // If the failed migration managed to create an equivalent index under
        // Laravel's generated name, do not add a duplicate index.
        if ($this->indexColumnsExist($table, $columns, $unique)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($columns, $name, $unique): void {
            $unique ? $t->unique($columns, $name) : $t->index($columns, $name);
        });
    }

    private function ensureForeignKeys(): void
    {
        $fks = [
            ['finance_purchasing_posting_mappings', 'outlet_id', 'fin_pur_map_outlet_fk', 'outlets', 'id', 'null'],
            ['finance_purchasing_posting_mappings', 'default_debit_account_id', 'fin_pur_map_debit_fk', 'finance_chart_of_accounts', 'id', 'restrict'],
            ['finance_purchasing_posting_mappings', 'ap_account_id', 'fin_pur_map_ap_fk', 'finance_chart_of_accounts', 'id', 'restrict'],
            ['finance_purchasing_posting_mappings', 'tax_account_id', 'fin_pur_map_tax_fk', 'finance_chart_of_accounts', 'id', 'restrict'],

            ['finance_purchasing_payment_mappings', 'outlet_id', 'fin_pur_paymap_outlet_fk', 'outlets', 'id', 'null'],
            ['finance_purchasing_payment_mappings', 'cash_account_id', 'fin_pur_paymap_cash_fk', 'finance_chart_of_accounts', 'id', 'restrict'],

            ['finance_purchasing_postings', 'outbox_id', 'fin_pur_post_outbox_fk', 'pur_finance_posting_outbox', 'id', 'restrict'],
            ['finance_purchasing_postings', 'invoice_id', 'fin_pur_post_invoice_fk', 'pur_invoices', 'id', 'restrict'],
            ['finance_purchasing_postings', 'payment_id', 'fin_pur_post_payment_fk', 'pur_invoice_payments', 'id', 'null'],
            ['finance_purchasing_postings', 'outlet_id', 'fin_pur_post_outlet_fk', 'outlets', 'id', 'restrict'],
            ['finance_purchasing_postings', 'issue_mapping_id', 'fin_pur_post_map_fk', 'finance_purchasing_posting_mappings', 'id', 'restrict'],
            ['finance_purchasing_postings', 'payment_mapping_id', 'fin_pur_post_paymap_fk', 'finance_purchasing_payment_mappings', 'id', 'restrict'],

            ['finance_purchasing_posting_lines', 'purchasing_posting_id', 'fin_pur_line_post_fk', 'finance_purchasing_postings', 'id', 'cascade'],
            ['finance_purchasing_posting_lines', 'invoice_item_id', 'fin_pur_line_invitem_fk', 'pur_invoice_items', 'id', 'null'],
            ['finance_purchasing_posting_lines', 'target_account_id', 'fin_pur_line_acc_fk', 'finance_chart_of_accounts', 'id', 'restrict'],

            ['finance_purchasing_posting_allocations', 'purchasing_posting_id', 'fin_pur_alloc_post_fk', 'finance_purchasing_postings', 'id', 'cascade'],

            ['finance_purchasing_posting_journals', 'purchasing_posting_id', 'fin_pur_j_post_fk', 'finance_purchasing_postings', 'id', 'cascade'],
            ['finance_purchasing_posting_journals', 'journal_entry_id', 'fin_pur_j_entry_fk', 'finance_journal_entries', 'id', 'restrict'],
            ['finance_purchasing_posting_journals', 'reversal_journal_id', 'fin_pur_j_rev_fk', 'finance_journal_entries', 'id', 'null'],
        ];

        foreach ($fks as [$table, $column, $name, $parent, $parentColumn, $delete]) {
            if (! Schema::hasTable($table)
                || ! Schema::hasColumn($table, $column)
                || ! Schema::hasTable($parent)
                || $this->foreignKeyExists($table, $name)
                || $this->foreignKeyColumnExists($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($column, $name, $parent, $parentColumn, $delete): void {
                $fk = $t->foreign($column, $name)->references($parentColumn)->on($parent);
                match ($delete) {
                    'cascade' => $fk->cascadeOnDelete(),
                    'null' => $fk->nullOnDelete(),
                    default => $fk->restrictOnDelete(),
                };
            });
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                if (($index['name'] ?? null) === $name) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    /** @param array<int,string> $columns */
    private function indexColumnsExist(string $table, array $columns, bool $unique): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                $indexColumns = array_values(array_map('strval', $index['columns'] ?? []));
                if ($indexColumns === array_values($columns)
                    && (! $unique || (bool) ($index['unique'] ?? false))) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        try {
            foreach (Schema::getForeignKeys($table) as $foreign) {
                if (($foreign['name'] ?? null) === $name) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private function foreignKeyColumnExists(string $table, string $column): bool
    {
        try {
            foreach (Schema::getForeignKeys($table) as $foreign) {
                if (in_array($column, array_map('strval', $foreign['columns'] ?? []), true)) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private function seedMappings(): void
    {
        $acc = DB::table('finance_chart_of_accounts')
            ->whereIn('code', ['1-10200','6-60300','2-20100','1-10500','1-10001','1-10007'])
            ->get()
            ->keyBy('code');

        foreach (['1-10200','6-60300','2-20100','1-10500','1-10001','1-10007'] as $code) {
            if (! $acc->has($code)) {
                throw new RuntimeException("COA default Purchasing {$code} tidak ditemukan.");
            }
        }

        foreach (['BKJB','MDMF'] as $company) {
            $defaults = [
                'GOODS_RECEIPT' => '1-10200',
                'SERVICE_ACCEPTANCE' => '6-60300',
                'REIMBURSE_PAYMENT' => '6-60300',
            ];

            foreach ($defaults as $kind => $debit) {
                $key = "{$company}:ALL:{$kind}";
                if (! DB::table('finance_purchasing_posting_mappings')->where('mapping_key', $key)->exists()) {
                    DB::table('finance_purchasing_posting_mappings')->insert([
                        'id' => (string) Str::ulid(),
                        'mapping_key' => $key,
                        'company_code' => $company,
                        'outlet_id' => null,
                        'source_document_kind' => $kind,
                        'default_debit_account_id' => $acc[$debit]->id,
                        'ap_account_id' => $acc['2-20100']->id,
                        'tax_account_id' => $acc['1-10500']->id,
                        'default_marking' => 'MARKING',
                        'is_active' => true,
                        'notes' => 'Default Finance Iterasi 10. Baris dapat dipindah ke Asset/Expense sebelum posting.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            foreach (['CASH','PETTY_CASH'] as $method) {
                $key = "{$company}:ALL:{$method}";
                if (! DB::table('finance_purchasing_payment_mappings')->where('mapping_key', $key)->exists()) {
                    DB::table('finance_purchasing_payment_mappings')->insert([
                        'id' => (string) Str::ulid(),
                        'mapping_key' => $key,
                        'company_code' => $company,
                        'outlet_id' => null,
                        'payment_method' => $method,
                        'cash_account_id' => $acc['1-10001']->id,
                        'is_active' => true,
                        'notes' => 'Default Kas. Sesuaikan per outlet bila perlu.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            foreach (['BANK_TRANSFER','GIRO','VIRTUAL_ACCOUNT','OTHER'] as $method) {
                $key = "{$company}:ALL:{$method}";
                if (! DB::table('finance_purchasing_payment_mappings')->where('mapping_key', $key)->exists()) {
                    DB::table('finance_purchasing_payment_mappings')->insert([
                        'id' => (string) Str::ulid(),
                        'mapping_key' => $key,
                        'company_code' => $company,
                        'outlet_id' => null,
                        'payment_method' => $method,
                        'cash_account_id' => $acc['1-10007']->id,
                        'is_active' => true,
                        'notes' => 'Default Bank BCA global. Wajib review dan override ke rekening PT/outlet yang benar sebelum go-live.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    private function registerAccess(): void
    {
        $permissions = [
            'finance.purchasing_posting.view',
            'finance.purchasing_posting.create',
            'finance.purchasing_posting.update',
            'finance.purchasing_posting.delete',
            'finance.purchasing_posting.post',
            'finance.purchasing_posting.reopen',
            'finance.purchasing_posting.manage_mapping',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::query()
            ->where('guard_name', 'web')
            ->whereIn(DB::raw('LOWER(name)'), ['admin','administrator','superadmin','super-admin'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $portal = DB::table('access_portals')->where('code', 'finance')->first();
        if (! $portal) {
            return;
        }

        $now = now();
        $existing = DB::table('access_menus')->where('code', 'finance-purchasing-posting')->first();
        $menuId = (string) ($existing->id ?? Str::ulid());

        DB::table('access_menus')->updateOrInsert(
            ['code' => 'finance-purchasing-posting'],
            [
                'id' => $menuId,
                'portal_id' => $portal->id,
                'name' => 'Purchasing Posting',
                'path' => '/finance/purchasing-posting',
                'sort_order' => 110,
                'permission_view' => 'finance.purchasing_posting.view',
                'permission_create' => 'finance.purchasing_posting.create',
                'permission_update' => 'finance.purchasing_posting.update',
                'permission_delete' => 'finance.purchasing_posting.delete',
                'is_active' => true,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        if (! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $sourceId = DB::table('access_menus')->where('code', 'finance-cogs-posting')->value('id')
            ?: DB::table('access_menus')->where('code', 'finance-general-ledger')->value('id');

        $rows = $sourceId
            ? DB::table('access_role_menu_permissions')->where('menu_id', $sourceId)->get()
            : collect();

        foreach ($rows as $row) {
            $query = DB::table('access_role_menu_permissions')
                ->where('access_role_id', $row->access_role_id)
                ->where('menu_id', $menuId);

            $row->access_level_id === null
                ? $query->whereNull('access_level_id')
                : $query->where('access_level_id', $row->access_level_id);

            if ($query->exists()) {
                continue;
            }

            $role = Schema::hasTable('access_roles')
                ? DB::table('access_roles')->where('id', $row->access_role_id)->first(['code','spatie_role_name'])
                : null;

            $admin = str_contains(strtoupper((string) ($role->code ?? '')), 'ADMIN')
                || str_contains(strtolower((string) ($role->spatie_role_name ?? '')), 'admin');

            DB::table('access_role_menu_permissions')->insert([
                'id' => (string) Str::ulid(),
                'access_role_id' => $row->access_role_id,
                'access_level_id' => $row->access_level_id,
                'menu_id' => $menuId,
                'can_view' => (bool) $row->can_view,
                'can_create' => $admin,
                'can_edit' => $admin,
                'can_delete' => $admin,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
