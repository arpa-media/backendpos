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
    private const PORTAL = 'warehouse-operations';

    public function up(): void
    {
        foreach (['outlets', 'users', 'access_portals', 'access_menus'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Warehouse v4 Iterasi 04 membutuhkan tabel {$table} dari baseline POS.");
            }
        }

        $this->createTables();
        $this->seedChartOfAccounts();
        $this->seedPostingTemplates();
        $this->registerAccessMatrix();
    }

    private function createTables(): void
    {
        if (! Schema::hasTable('wh_v4_finance_coa')) {
            Schema::create('wh_v4_finance_coa', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('code', 32)->unique();
                $table->string('name', 180);
                $table->string('account_type', 32)->index();
                $table->string('normal_balance', 8)->default('DEBIT');
                $table->boolean('is_header')->default(false);
                $table->boolean('is_postable')->default(true);
                $table->boolean('is_system')->default(false);
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->text('description')->nullable();
                $table->char('created_by_user_id', 26)->nullable()->index();
                $table->char('updated_by_user_id', 26)->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wh_v4_finance_posting_templates')) {
            Schema::create('wh_v4_finance_posting_templates', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('code', 80)->unique();
                $table->string('name', 180);
                $table->string('source_type', 80)->index();
                $table->text('description')->nullable();
                $table->boolean('is_system')->default(false);
                $table->boolean('is_active')->default(true)->index();
                $table->char('created_by_user_id', 26)->nullable()->index();
                $table->char('updated_by_user_id', 26)->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wh_v4_finance_posting_template_lines')) {
            Schema::create('wh_v4_finance_posting_template_lines', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('template_id', 26)->index();
                $table->unsignedInteger('sort_order')->default(1);
                $table->char('account_id', 26)->index();
                $table->string('side', 8);
                $table->string('amount_key', 80);
                $table->decimal('multiplier', 20, 6)->default(1);
                $table->string('memo_template', 255)->nullable();
                $table->timestamps();

                $table->foreign('template_id', 'whv4_fin_tpl_line_tpl_fk')
                    ->references('id')->on('wh_v4_finance_posting_templates')->cascadeOnDelete();
                $table->foreign('account_id', 'whv4_fin_tpl_line_coa_fk')
                    ->references('id')->on('wh_v4_finance_coa')->restrictOnDelete();
                $table->index(['template_id', 'sort_order'], 'whv4_fin_tpl_sort_idx');
            });
        }

        if (! Schema::hasTable('wh_v4_finance_general_postings')) {
            Schema::create('wh_v4_finance_general_postings', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('posting_no', 64)->unique();
                $table->string('source_key', 191)->nullable()->unique();
                $table->string('source_type', 80)->default('MANUAL')->index();
                $table->string('source_id', 100)->nullable()->index();
                $table->string('reference_no', 140)->nullable()->index();
                $table->char('warehouse_id', 26)->index();
                $table->date('business_date')->index();
                $table->date('journal_date')->index();
                $table->char('template_id', 26)->nullable()->index();
                $table->string('currency_code', 3)->default('IDR');
                $table->text('description')->nullable();
                $table->string('status', 16)->default('DRAFT')->index();
                $table->decimal('total_debit', 22, 2)->default(0);
                $table->decimal('total_credit', 22, 2)->default(0);
                $table->string('source_fingerprint', 64)->nullable();
                $table->json('metadata')->nullable();
                $table->char('reversal_of_posting_id', 26)->nullable()->index();
                $table->char('reversal_posting_id', 26)->nullable()->index();
                $table->timestamp('posted_at')->nullable();
                $table->char('posted_by_user_id', 26)->nullable()->index();
                $table->timestamp('reversed_at')->nullable();
                $table->char('reversed_by_user_id', 26)->nullable()->index();
                $table->char('created_by_user_id', 26)->nullable()->index();
                $table->char('updated_by_user_id', 26)->nullable()->index();
                $table->timestamps();

                $table->foreign('template_id', 'whv4_fin_gp_tpl_fk')
                    ->references('id')->on('wh_v4_finance_posting_templates')->nullOnDelete();
                $table->foreign('reversal_of_posting_id', 'whv4_fin_gp_rev_of_fk')
                    ->references('id')->on('wh_v4_finance_general_postings')->nullOnDelete();
                $table->foreign('reversal_posting_id', 'whv4_fin_gp_rev_fk')
                    ->references('id')->on('wh_v4_finance_general_postings')->nullOnDelete();
                $table->index(['warehouse_id', 'business_date', 'status'], 'whv4_fin_gp_wh_date_idx');
                $table->index(['source_type', 'source_id'], 'whv4_fin_gp_source_idx');
            });
        }

        if (! Schema::hasTable('wh_v4_finance_general_posting_lines')) {
            Schema::create('wh_v4_finance_general_posting_lines', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('general_posting_id', 26)->index();
                $table->unsignedInteger('line_no')->default(1);
                $table->char('account_id', 26)->index();
                $table->string('account_code', 32)->index();
                $table->string('account_name', 180);
                $table->string('account_type', 32);
                $table->string('normal_balance', 8);
                $table->text('description')->nullable();
                $table->decimal('debit', 22, 2)->default(0);
                $table->decimal('credit', 22, 2)->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('general_posting_id', 'whv4_fin_gpl_gp_fk')
                    ->references('id')->on('wh_v4_finance_general_postings')->cascadeOnDelete();
                $table->foreign('account_id', 'whv4_fin_gpl_coa_fk')
                    ->references('id')->on('wh_v4_finance_coa')->restrictOnDelete();
                $table->index(['general_posting_id', 'line_no'], 'whv4_fin_gpl_line_idx');
            });
        }

        if (! Schema::hasTable('wh_v4_finance_general_posting_events')) {
            Schema::create('wh_v4_finance_general_posting_events', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('general_posting_id', 26)->nullable()->index();
                $table->string('event_type', 60)->index();
                $table->json('payload')->nullable();
                $table->char('actor_user_id', 26)->nullable()->index();
                $table->timestamps();

                $table->foreign('general_posting_id', 'whv4_fin_gpe_gp_fk')
                    ->references('id')->on('wh_v4_finance_general_postings')->cascadeOnDelete();
            });
        }
    }

    private function seedChartOfAccounts(): void
    {
        $rows = [
            ['1000', 'ASET', 'ASSET', 'DEBIT', true, false, 1000, 'Header aset Warehouse.'],
            ['1010', 'Kas Warehouse', 'ASSET', 'DEBIT', false, true, 1010, 'Kas fisik Warehouse.'],
            ['1020', 'Bank Warehouse', 'ASSET', 'DEBIT', false, true, 1020, 'Saldo rekening bank Warehouse.'],
            ['1100', 'Piutang Usaha Warehouse', 'ASSET', 'DEBIT', false, true, 1100, 'Piutang Stock Request dan Sales Customer.'],
            ['1200', 'PERSEDIAAN', 'ASSET', 'DEBIT', true, false, 1200, 'Header persediaan Warehouse.'],
            ['1210', 'Persediaan Warehouse', 'ASSET', 'DEBIT', false, true, 1210, 'Nilai persediaan fisik Warehouse.'],
            ['1220', 'Persediaan Dalam Perjalanan', 'ASSET', 'DEBIT', false, true, 1220, 'Inventory in transit antar-Warehouse.'],
            ['1230', 'Work In Process Produksi', 'ASSET', 'DEBIT', false, true, 1230, 'WIP antara material out dan finished goods in.'],
            ['1240', 'PO Clearing / Goods Ordered', 'ASSET', 'DEBIT', false, true, 1240, 'Clearing nilai PO yang sudah menjadi hutang sebelum stock receipt.'],
            ['1300', 'PPN Masukan', 'ASSET', 'DEBIT', false, true, 1300, 'Pajak masukan pembelian Warehouse bila digunakan.'],
            ['2000', 'LIABILITAS', 'LIABILITY', 'CREDIT', true, false, 2000, 'Header kewajiban Warehouse.'],
            ['2100', 'Hutang Usaha Warehouse', 'LIABILITY', 'CREDIT', false, true, 2100, 'Hutang supplier dari Purchase Order.'],
            ['2200', 'PPN Keluaran', 'LIABILITY', 'CREDIT', false, true, 2200, 'Pajak keluaran penjualan Warehouse bila digunakan.'],
            ['4000', 'PENDAPATAN', 'REVENUE', 'CREDIT', true, false, 4000, 'Header pendapatan Warehouse.'],
            ['4100', 'Penjualan Warehouse', 'REVENUE', 'CREDIT', false, true, 4100, 'Pendapatan Stock Request dan Sales Customer.'],
            ['4200', 'Pendapatan Lain Warehouse', 'REVENUE', 'CREDIT', false, true, 4200, 'Pendapatan non-sales Warehouse.'],
            ['5000', 'HARGA POKOK PENJUALAN', 'EXPENSE', 'DEBIT', true, false, 5000, 'Header HPP Warehouse.'],
            ['5100', 'HPP Warehouse', 'EXPENSE', 'DEBIT', false, true, 5100, 'Cost inventory yang keluar untuk penjualan.'],
            ['5200', 'Selisih Produksi', 'EXPENSE', 'DEBIT', false, true, 5200, 'Variance yield/cost proses produksi.'],
            ['5300', 'Selisih Persediaan', 'EXPENSE', 'DEBIT', false, true, 5300, 'Adjustment loss/variance persediaan.'],
            ['6000', 'BEBAN OPERASIONAL', 'EXPENSE', 'DEBIT', true, false, 6000, 'Header beban operasional Warehouse.'],
            ['6100', 'Beban Operasional Warehouse', 'EXPENSE', 'DEBIT', false, true, 6100, 'Cash-out/bank-out operasional Warehouse.'],
        ];

        $now = now();
        foreach ($rows as [$code, $name, $type, $normal, $header, $postable, $sort, $description]) {
            if (DB::table('wh_v4_finance_coa')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('wh_v4_finance_coa')->insert([
                'id' => (string) Str::ulid(),
                'code' => $code,
                'name' => $name,
                'account_type' => $type,
                'normal_balance' => $normal,
                'is_header' => $header,
                'is_postable' => $postable,
                'is_system' => true,
                'is_active' => true,
                'sort_order' => $sort,
                'description' => $description,
                'created_by_user_id' => null,
                'updated_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedPostingTemplates(): void
    {
        $templates = [
            [
                'code' => 'PURCHASE_ORDER_APPROVED',
                'name' => 'Purchase Order Approved → Hutang',
                'source_type' => 'PURCHASE_ORDER',
                'description' => 'PO approved mengakui hutang dan nilai goods ordered/PO clearing.',
                'lines' => [
                    ['1240', 'DEBIT', 'payable', 1, 'PO clearing {{reference_no}}'],
                    ['2100', 'CREDIT', 'payable', 1, 'Hutang supplier {{reference_no}}'],
                ],
            ],
            [
                'code' => 'PURCHASE_STOCK_RECEIPT',
                'name' => 'Stock Receipt Purchase',
                'source_type' => 'STOCK_MOVEMENT',
                'description' => 'GR/Stock In memindahkan nilai PO clearing menjadi persediaan.',
                'lines' => [
                    ['1210', 'DEBIT', 'inventory_value', 1, 'Persediaan masuk {{reference_no}}'],
                    ['1240', 'CREDIT', 'inventory_value', 1, 'Release PO clearing {{reference_no}}'],
                ],
            ],
            [
                'code' => 'INVOICE_PAYMENT_CASH',
                'name' => 'Pelunasan Hutang via Kas',
                'source_type' => 'INCOMING_PAYMENT',
                'description' => 'Pembayaran incoming invoice menggunakan kas.',
                'lines' => [
                    ['2100', 'DEBIT', 'payment', 1, 'Pelunasan hutang {{reference_no}}'],
                    ['1010', 'CREDIT', 'payment', 1, 'Kas keluar {{reference_no}}'],
                ],
            ],
            [
                'code' => 'INVOICE_PAYMENT_BANK',
                'name' => 'Pelunasan Hutang via Bank',
                'source_type' => 'INCOMING_PAYMENT',
                'description' => 'Pembayaran incoming invoice menggunakan transfer/bank.',
                'lines' => [
                    ['2100', 'DEBIT', 'payment', 1, 'Pelunasan hutang {{reference_no}}'],
                    ['1020', 'CREDIT', 'payment', 1, 'Bank keluar {{reference_no}}'],
                ],
            ],
            [
                'code' => 'WAREHOUSE_SALE',
                'name' => 'Stock Request / Sales Customer',
                'source_type' => 'WAREHOUSE_SALE',
                'description' => 'Pengakuan penjualan dan HPP dari stock movement keluar.',
                'lines' => [
                    ['1100', 'DEBIT', 'revenue', 1, 'Piutang {{reference_no}}'],
                    ['4100', 'CREDIT', 'revenue', 1, 'Penjualan {{reference_no}}'],
                    ['5100', 'DEBIT', 'cogs', 1, 'HPP {{reference_no}}'],
                    ['1210', 'CREDIT', 'cogs', 1, 'Persediaan keluar {{reference_no}}'],
                ],
            ],
            [
                'code' => 'CUSTOMER_PAYMENT_CASH',
                'name' => 'Penerimaan Piutang via Kas',
                'source_type' => 'OUTGOING_PAYMENT',
                'description' => 'Pembayaran outgoing invoice melalui kas.',
                'lines' => [
                    ['1010', 'DEBIT', 'payment', 1, 'Kas masuk {{reference_no}}'],
                    ['1100', 'CREDIT', 'payment', 1, 'Pelunasan piutang {{reference_no}}'],
                ],
            ],
            [
                'code' => 'CUSTOMER_PAYMENT_BANK',
                'name' => 'Penerimaan Piutang via Bank',
                'source_type' => 'OUTGOING_PAYMENT',
                'description' => 'Pembayaran outgoing invoice melalui transfer/bank.',
                'lines' => [
                    ['1020', 'DEBIT', 'payment', 1, 'Bank masuk {{reference_no}}'],
                    ['1100', 'CREDIT', 'payment', 1, 'Pelunasan piutang {{reference_no}}'],
                ],
            ],
            [
                'code' => 'PRODUCTION_MATERIAL_OUT',
                'name' => 'Production Material Out',
                'source_type' => 'STOCK_MOVEMENT',
                'description' => 'Material keluar dipindah ke WIP.',
                'lines' => [
                    ['1230', 'DEBIT', 'inventory_value', 1, 'WIP material {{reference_no}}'],
                    ['1210', 'CREDIT', 'inventory_value', 1, 'Material keluar {{reference_no}}'],
                ],
            ],
            [
                'code' => 'PRODUCTION_FINISHED_IN',
                'name' => 'Production Finished Goods In',
                'source_type' => 'STOCK_MOVEMENT',
                'description' => 'Finished goods masuk dari WIP.',
                'lines' => [
                    ['1210', 'DEBIT', 'inventory_value', 1, 'Finished goods {{reference_no}}'],
                    ['1230', 'CREDIT', 'inventory_value', 1, 'Release WIP {{reference_no}}'],
                ],
            ],
            [
                'code' => 'TRANSFER_DISPATCH',
                'name' => 'Transfer Stock Dispatch',
                'source_type' => 'STOCK_MOVEMENT',
                'description' => 'Barang keluar Warehouse asal menjadi inventory in transit.',
                'lines' => [
                    ['1220', 'DEBIT', 'inventory_value', 1, 'Inventory in transit {{reference_no}}'],
                    ['1210', 'CREDIT', 'inventory_value', 1, 'Persediaan Warehouse asal {{reference_no}}'],
                ],
            ],
            [
                'code' => 'TRANSFER_RECEIVE',
                'name' => 'Transfer Stock Receive',
                'source_type' => 'STOCK_MOVEMENT',
                'description' => 'Warehouse tujuan menerima inventory in transit.',
                'lines' => [
                    ['1210', 'DEBIT', 'inventory_value', 1, 'Persediaan Warehouse tujuan {{reference_no}}'],
                    ['1220', 'CREDIT', 'inventory_value', 1, 'Release inventory in transit {{reference_no}}'],
                ],
            ],
            [
                'code' => 'STOCK_ADJUSTMENT_IN',
                'name' => 'Stock Adjustment In',
                'source_type' => 'STOCK_MOVEMENT',
                'description' => 'Adjustment persediaan masuk.',
                'lines' => [
                    ['1210', 'DEBIT', 'inventory_value', 1, 'Adjustment stock in {{reference_no}}'],
                    ['4200', 'CREDIT', 'inventory_value', 1, 'Gain adjustment {{reference_no}}'],
                ],
            ],
            [
                'code' => 'STOCK_ADJUSTMENT_OUT',
                'name' => 'Stock Adjustment Out',
                'source_type' => 'STOCK_MOVEMENT',
                'description' => 'Adjustment persediaan keluar.',
                'lines' => [
                    ['5300', 'DEBIT', 'inventory_value', 1, 'Loss adjustment {{reference_no}}'],
                    ['1210', 'CREDIT', 'inventory_value', 1, 'Adjustment stock out {{reference_no}}'],
                ],
            ],
            [
                'code' => 'CASH_IN_OTHER',
                'name' => 'Cash In Lain-lain',
                'source_type' => 'TREASURY',
                'description' => 'Fondasi Cash In untuk Iterasi 06.',
                'lines' => [
                    ['1010', 'DEBIT', 'amount', 1, 'Cash in {{reference_no}}'],
                    ['4200', 'CREDIT', 'amount', 1, 'Pendapatan lain {{reference_no}}'],
                ],
            ],
            [
                'code' => 'CASH_OUT_EXPENSE',
                'name' => 'Cash Out Beban',
                'source_type' => 'TREASURY',
                'description' => 'Fondasi Cash Out untuk Iterasi 06.',
                'lines' => [
                    ['6100', 'DEBIT', 'amount', 1, 'Beban {{reference_no}}'],
                    ['1010', 'CREDIT', 'amount', 1, 'Cash out {{reference_no}}'],
                ],
            ],
            [
                'code' => 'BANK_IN_OTHER',
                'name' => 'Bank In Lain-lain',
                'source_type' => 'TREASURY',
                'description' => 'Fondasi Bank In untuk Iterasi 06.',
                'lines' => [
                    ['1020', 'DEBIT', 'amount', 1, 'Bank in {{reference_no}}'],
                    ['4200', 'CREDIT', 'amount', 1, 'Pendapatan lain {{reference_no}}'],
                ],
            ],
            [
                'code' => 'BANK_OUT_EXPENSE',
                'name' => 'Bank Out Beban',
                'source_type' => 'TREASURY',
                'description' => 'Fondasi Bank Out untuk Iterasi 06.',
                'lines' => [
                    ['6100', 'DEBIT', 'amount', 1, 'Beban {{reference_no}}'],
                    ['1020', 'CREDIT', 'amount', 1, 'Bank out {{reference_no}}'],
                ],
            ],
            [
                'code' => 'BOOK_TRANSFER',
                'name' => 'Book Transfer',
                'source_type' => 'TREASURY',
                'description' => 'Fondasi pindah buku antar rekening untuk Iterasi 06.',
                'lines' => [
                    ['1020', 'DEBIT', 'amount', 1, 'Rekening tujuan {{reference_no}}'],
                    ['1020', 'CREDIT', 'amount', 1, 'Rekening asal {{reference_no}}'],
                ],
            ],
        ];

        $now = now();
        $accountIds = DB::table('wh_v4_finance_coa')->pluck('id', 'code');

        foreach ($templates as $row) {
            $template = DB::table('wh_v4_finance_posting_templates')->where('code', $row['code'])->first();
            if (! $template) {
                $templateId = (string) Str::ulid();
                DB::table('wh_v4_finance_posting_templates')->insert([
                    'id' => $templateId,
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'source_type' => $row['source_type'],
                    'description' => $row['description'],
                    'is_system' => true,
                    'is_active' => true,
                    'created_by_user_id' => null,
                    'updated_by_user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $templateId = (string) $template->id;
            }

            if (DB::table('wh_v4_finance_posting_template_lines')->where('template_id', $templateId)->exists()) {
                continue;
            }

            foreach ($row['lines'] as $index => [$accountCode, $side, $amountKey, $multiplier, $memo]) {
                $accountId = $accountIds[$accountCode] ?? null;
                if (! $accountId) {
                    throw new RuntimeException("COA Warehouse {$accountCode} tidak ditemukan saat seed template {$row['code']}.");
                }

                DB::table('wh_v4_finance_posting_template_lines')->insert([
                    'id' => (string) Str::ulid(),
                    'template_id' => $templateId,
                    'sort_order' => $index + 1,
                    'account_id' => $accountId,
                    'side' => $side,
                    'amount_key' => $amountKey,
                    'multiplier' => $multiplier,
                    'memo_template' => $memo,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function registerAccessMatrix(): void
    {
        $permissions = [
            'warehouse.finance.coa.view',
            'warehouse.finance.coa.create',
            'warehouse.finance.coa.update',
            'warehouse.finance.posting_template.view',
            'warehouse.finance.posting_template.create',
            'warehouse.finance.posting_template.update',
            'warehouse.finance.general_posting.view',
            'warehouse.finance.general_posting.create',
            'warehouse.finance.general_posting.update',
            'warehouse.finance.general_posting.post',
            'warehouse.finance.general_posting.reverse',
        ];

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            foreach ($permissions as $permission) {
                Permission::findOrCreate($permission, $guard);
            }

            if (Schema::hasTable('roles')) {
                Role::query()
                    ->where('guard_name', $guard)
                    ->whereIn(DB::raw('LOWER(name)'), ['admin', 'administrator', 'superadmin', 'super-admin', 'warehouse'])
                    ->get()
                    ->each(fn (Role $role) => $role->givePermissionTo($permissions));
            }
        }

        $portal = DB::table('access_portals')->where('code', self::PORTAL)->first();
        if (! $portal) {
            return;
        }

        $menus = [
            [
                'code' => 'warehouse-finance-coa-v4',
                'name' => 'Chart of Account Warehouse',
                'path' => '/warehouse/finance/chart-of-accounts',
                'sort_order' => 700,
                'view' => 'warehouse.finance.coa.view',
                'create' => 'warehouse.finance.coa.create',
                'update' => 'warehouse.finance.coa.update',
                'delete' => null,
            ],
            [
                'code' => 'warehouse-finance-posting-template-v4',
                'name' => 'Template Posting Warehouse',
                'path' => '/warehouse/finance/posting-templates',
                'sort_order' => 710,
                'view' => 'warehouse.finance.posting_template.view',
                'create' => 'warehouse.finance.posting_template.create',
                'update' => 'warehouse.finance.posting_template.update',
                'delete' => null,
            ],
            [
                'code' => 'warehouse-finance-general-posting-v4',
                'name' => 'General Posting Warehouse',
                'path' => '/warehouse/finance/general-posting',
                'sort_order' => 720,
                'view' => 'warehouse.finance.general_posting.view',
                'create' => 'warehouse.finance.general_posting.create',
                'update' => 'warehouse.finance.general_posting.update',
                'delete' => null,
            ],
        ];

        $now = now();
        foreach ($menus as $menu) {
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
                    'permission_view' => $menu['view'],
                    'permission_create' => $menu['create'],
                    'permission_update' => $menu['update'],
                    'permission_delete' => $menu['delete'],
                    'is_active' => true,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ]
            );

            $this->seedMatrixRows($menuId, $now);
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function seedMatrixRows(string $menuId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];

        foreach (DB::table('access_roles')->get(['id', 'code']) as $role) {
            $enabled = in_array(strtoupper(trim((string) $role->code)), ['ADMIN', 'WAREHOUSE'], true);

            foreach (array_merge([null], $levels) as $levelId) {
                $query = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $role->id)
                    ->where('menu_id', $menuId);
                $levelId === null
                    ? $query->whereNull('access_level_id')
                    : $query->where('access_level_id', $levelId);

                if ($query->exists()) {
                    continue;
                }

                DB::table('access_role_menu_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $role->id,
                    'access_level_id' => $levelId,
                    'menu_id' => $menuId,
                    'can_view' => $enabled,
                    'can_create' => $enabled,
                    'can_edit' => $enabled,
                    'can_delete' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive by design. Finance Warehouse is audit data.
        // A rollback must never silently delete posted debit/credit history.
    }
};
