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
            'finance_chart_of_accounts','finance_posting_templates','finance_posting_template_lines',
            'finance_journal_entries','finance_journal_entry_lines','finance_payroll_posting_inbox',
            'finance_general_postings','finance_general_posting_journals',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Finance Backoffice Iterasi 05 membutuhkan {$table}.");
            }
        }

        $this->extendTemplates();
        $this->extendPayrollInbox();
        $this->createPayrollPayments();
        $this->seedOperationalTemplates();
        $this->registerAccess();
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_payroll_payments');
        // Kolom dan system template sengaja tidak dihapus pada rollback untuk menjaga audit/configuration history.
    }

    private function extendTemplates(): void
    {
        Schema::table('finance_posting_templates', function (Blueprint $t): void {
            if (! Schema::hasColumn('finance_posting_templates', 'system_key')) {
                $t->string('system_key', 80)->nullable();
            }
            if (! Schema::hasColumn('finance_posting_templates', 'is_system')) {
                $t->boolean('is_system')->default(false);
            }
            if (! Schema::hasColumn('finance_posting_templates', 'manual_selectable')) {
                $t->boolean('manual_selectable')->default(false);
            }
        });

        $indexExists = collect(DB::select("SHOW INDEX FROM finance_posting_templates"))
            ->contains(fn ($r) => (string) ($r->Key_name ?? '') === 'fin_tpl_system_key_uq');
        if (! $indexExists) {
            Schema::table('finance_posting_templates', fn (Blueprint $t) => $t->unique('system_key', 'fin_tpl_system_key_uq'));
        }
    }

    private function extendPayrollInbox(): void
    {
        Schema::table('finance_payroll_posting_inbox', function (Blueprint $t): void {
            $columns = [
                'request_type' => fn () => $t->string('request_type', 20)->default('PAYROLL')->index(),
                'reference_no' => fn () => $t->string('reference_no', 120)->nullable()->index(),
                'description' => fn () => $t->text('description')->nullable(),
                'marking' => fn () => $t->string('marking', 16)->default('MARKING')->index(),
                'accrual_template_id' => fn () => $t->char('accrual_template_id', 26)->nullable()->index(),
                'accrual_journal_id' => fn () => $t->char('accrual_journal_id', 26)->nullable()->index(),
                'paid_total' => fn () => $t->decimal('paid_total', 20, 2)->default(0),
                'balance_due' => fn () => $t->decimal('balance_due', 20, 2)->default(0),
                'submitted_at' => fn () => $t->timestamp('submitted_at')->nullable(),
                'submitted_by_user_id' => fn () => $t->char('submitted_by_user_id', 26)->nullable()->index(),
                'approved_at' => fn () => $t->timestamp('approved_at')->nullable(),
                'approved_by_user_id' => fn () => $t->char('approved_by_user_id', 26)->nullable()->index(),
                'accrued_at' => fn () => $t->timestamp('accrued_at')->nullable(),
                'accrued_by_user_id' => fn () => $t->char('accrued_by_user_id', 26)->nullable()->index(),
            ];
            foreach ($columns as $name => $callback) {
                if (! Schema::hasColumn('finance_payroll_posting_inbox', $name)) $callback();
            }
        });

        DB::table('finance_payroll_posting_inbox')
            ->where('status', 'RECEIVED_WAITING_HR_INTEGRATION')
            ->update(['status' => 'SUBMITTED', 'submitted_at' => DB::raw('COALESCE(received_at, created_at)'), 'updated_at' => now()]);

        DB::table('finance_payroll_posting_inbox')->whereNull('request_type')->update(['request_type' => 'PAYROLL']);
        DB::table('finance_payroll_posting_inbox')->whereNull('marking')->update(['marking' => 'MARKING']);
        DB::table('finance_payroll_posting_inbox')->where('balance_due', 0)->update([
            'balance_due' => DB::raw('GREATEST(COALESCE(net_pay, 0) - COALESCE(paid_total, 0), 0)'),
        ]);
    }

    private function createPayrollPayments(): void
    {
        if (Schema::hasTable('finance_payroll_payments')) return;
        Schema::create('finance_payroll_payments', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->char('payroll_posting_id', 26)->index();
            $t->string('payment_no', 80)->unique();
            $t->string('idempotency_key', 191)->unique();
            $t->date('payment_date')->index();
            $t->decimal('amount', 20, 2);
            $t->char('payment_account_id', 26)->index();
            $t->string('payer_name', 160)->nullable();
            $t->string('reference_no', 120)->nullable()->index();
            $t->text('notes')->nullable();
            $t->char('journal_entry_id', 26)->nullable()->index();
            $t->string('status', 20)->default('POSTED')->index();
            $t->char('created_by_user_id', 26)->nullable()->index();
            $t->timestamps();
            $t->foreign('payroll_posting_id', 'fin_paypay_posting_fk')->references('id')->on('finance_payroll_posting_inbox')->cascadeOnDelete();
            $t->foreign('payment_account_id', 'fin_paypay_account_fk')->references('id')->on('finance_chart_of_accounts')->restrictOnDelete();
            $t->foreign('journal_entry_id', 'fin_paypay_journal_fk')->references('id')->on('finance_journal_entries')->nullOnDelete();
        });
    }

    private function seedOperationalTemplates(): void
    {
        $accounts = DB::table('finance_chart_of_accounts')->whereIn('code', [
            '1-10002','1-10200','2-20100','2-20200','2-20201','5-50000','6-60100','6-60101','6-60106',
        ])->where('is_active', true)->where('is_postable', true)->get(['id','code'])->keyBy('code');

        $missing = collect(['1-10002','1-10200','2-20100','2-20200','2-20201','5-50000','6-60100','6-60101','6-60106'])
            ->reject(fn ($code) => $accounts->has($code))->values();
        if ($missing->isNotEmpty()) {
            throw new RuntimeException('COA wajib untuk system journal template belum tersedia: '.$missing->join(', '));
        }

        $defs = [
            ['key'=>'STOCK_GR_ACCRUAL','code'=>'SYS-STOCK-GR-ACCRUAL','name'=>'Stock Request / GR · Persediaan & Hutang','source'=>'PURCHASING','description'=>'GR menambah persediaan dan hutang usaha.','lines'=>[
                ['1-10200','DEBIT','{{amount}}','Persediaan dari {{reference_no}}'],['2-20100','CREDIT','{{amount}}','Hutang persediaan {{reference_no}}'],
            ]],
            ['key'=>'STOCK_GR_PAYMENT','code'=>'SYS-STOCK-AP-PAYMENT','name'=>'Pembayaran Hutang Persediaan','source'=>'PURCHASING','description'=>'Pelunasan hutang persediaan melalui kas/bank.','lines'=>[
                ['2-20100','DEBIT','{{amount}}','Pelunasan hutang persediaan {{reference_no}}'],['1-10002','CREDIT','{{amount}}','Pembayaran {{reference_no}}'],
            ]],
            ['key'=>'COGS_VALUATION','code'=>'SYS-COGS-VALUATION','name'=>'COGS Valuation','source'=>'COGS','description'=>'Valuasi COGS: beban pokok terhadap persediaan.','lines'=>[
                ['5-50000','DEBIT','{{amount}}','COGS {{reference_no}}'],['1-10200','CREDIT','{{amount}}','Pengurangan persediaan COGS {{reference_no}}'],
            ]],
            ['key'=>'PURCHASING_LIABILITY','code'=>'SYS-PUR-LIABILITY','name'=>'Purchasing · Pengakuan Hutang','source'=>'PURCHASING','description'=>'Default liability purchasing; COA debit dapat disesuaikan sesuai jenis pembelian.','lines'=>[
                ['6-60100','DEBIT','{{amount}}','Purchasing {{reference_no}}'],['2-20100','CREDIT','{{amount}}','Hutang usaha {{reference_no}}'],
            ]],
            ['key'=>'PURCHASING_PAYMENT','code'=>'SYS-PUR-PAYMENT','name'=>'Purchasing · Pembayaran Hutang','source'=>'PURCHASING','description'=>'Pembayaran hutang Purchasing.','lines'=>[
                ['2-20100','DEBIT','{{amount}}','Pelunasan hutang {{reference_no}}'],['1-10002','CREDIT','{{amount}}','Pembayaran Purchasing {{reference_no}}'],
            ]],
            ['key'=>'PAYROLL_ACCRUAL','code'=>'SYS-PAYROLL-ACCRUAL','name'=>'Payroll · Pengajuan / Accrual','source'=>'PAYROLL','description'=>'Accrual payroll; gross payroll menjadi beban, net pay menjadi hutang gaji, deductions menjadi hutang lainnya.','lines'=>[
                ['6-60101','DEBIT','{{gross_pay}}','Beban gaji {{reference_no}}'],['2-20201','CREDIT','{{net_pay}}','Hutang gaji {{reference_no}}'],['2-20200','CREDIT','{{deductions}}','Potongan payroll {{reference_no}}'],
            ]],
            ['key'=>'BONUS_ACCRUAL','code'=>'SYS-BONUS-ACCRUAL','name'=>'Bonus · Pengajuan / Accrual','source'=>'PAYROLL','description'=>'Accrual bonus/THR.','lines'=>[
                ['6-60106','DEBIT','{{gross_pay}}','Beban bonus {{reference_no}}'],['2-20201','CREDIT','{{net_pay}}','Hutang bonus {{reference_no}}'],['2-20200','CREDIT','{{deductions}}','Potongan bonus {{reference_no}}'],
            ]],
            ['key'=>'PAYROLL_PAYMENT','code'=>'SYS-PAYROLL-PAYMENT','name'=>'Payroll · Pembayaran','source'=>'PAYROLL','description'=>'Pembayaran hutang gaji; credit account diganti ke rekening yang dipilih saat payment.','lines'=>[
                ['2-20201','DEBIT','{{amount}}','Pembayaran gaji {{reference_no}}'],['1-10002','CREDIT','{{amount}}','Sumber pembayaran {{reference_no}}'],
            ]],
            ['key'=>'BONUS_PAYMENT','code'=>'SYS-BONUS-PAYMENT','name'=>'Bonus · Pembayaran','source'=>'PAYROLL','description'=>'Pembayaran hutang bonus; credit account diganti ke rekening yang dipilih saat payment.','lines'=>[
                ['2-20201','DEBIT','{{amount}}','Pembayaran bonus {{reference_no}}'],['1-10002','CREDIT','{{amount}}','Sumber pembayaran {{reference_no}}'],
            ]],
            ['key'=>'GENERAL_EXPENSE_LIABILITY','code'=>'SYS-GENERAL-EXPENSE-AP','name'=>'General · Expense & Liability','source'=>'GENERAL','description'=>'Default General Posting: Biaya Umum & Administratif terhadap Hutang Lainnya. Review mapping sebelum digunakan.','lines'=>[
                ['6-60100','DEBIT','{{amount}}','General expense {{reference_no}}'],['2-20200','CREDIT','{{amount}}','General liability {{reference_no}}'],
            ]],
        ];

        foreach ($defs as $def) {
            $existing = DB::table('finance_posting_templates')->where('system_key', $def['key'])->first()
                ?: DB::table('finance_posting_templates')->where('code', $def['code'])->first();
            $id = (string) ($existing->id ?? Str::ulid());
            $now = now();
            $payload = [
                'code'=>$def['code'],'name'=>$def['name'],'source_type'=>$def['source'],'system_key'=>$def['key'],
                'company_code'=>null,'outlet_id'=>null,'marking'=>null,'description'=>$def['description'],
                'is_system'=>true,'manual_selectable'=>true,'is_active'=>true,'deleted_at'=>null,'updated_at'=>$now,
            ];
            if ($existing) DB::table('finance_posting_templates')->where('id', $id)->update($payload);
            else DB::table('finance_posting_templates')->insert($payload + ['id'=>$id,'created_at'=>$now]);

            DB::table('finance_posting_template_lines')->where('template_id', $id)->delete();
            foreach ($def['lines'] as $index => [$code,$side,$formula,$memo]) {
                DB::table('finance_posting_template_lines')->insert([
                    'id'=>(string) Str::ulid(),'template_id'=>$id,'sort_order'=>$index+1,'account_id'=>$accounts[$code]->id,
                    'side'=>$side,'amount_formula'=>$formula,'memo_template'=>$memo,'meta'=>json_encode(['system_role'=>$index===1 && str_contains($def['key'],'PAYMENT')?'PAYMENT_SOURCE':null]),
                    'created_at'=>$now,'updated_at'=>$now,
                ]);
            }
        }
    }

    private function registerAccess(): void
    {
        $permissions = [
            'finance.payroll_posting.view','finance.payroll_posting.create','finance.payroll_posting.update','finance.payroll_posting.delete',
            'finance.payroll_posting.submit','finance.payroll_posting.approve','finance.payroll_posting.post','finance.payroll_posting.pay',
            'finance.general_posting.view','finance.general_posting.create','finance.general_posting.update','finance.general_posting.delete',
            'finance.general_posting.post','finance.general_posting.reopen',
        ];
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');
        Role::query()->where('guard_name','web')->whereIn(DB::raw('LOWER(name)'), ['admin','administrator','superadmin','super-admin'])
            ->get()->each(fn (Role $role) => $role->givePermissionTo($permissions));

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code','finance')->first();
        if (! $portal) return;
        $this->upsertMenu($portal->id, 'finance-payroll-posting', 'Payroll & Bonus Posting', '/finance/payroll-posting', 90, 'finance.payroll_posting');
        $this->upsertMenu($portal->id, 'finance-general-posting', 'General Posting', '/finance/general-posting', 95, 'finance.general_posting');
    }

    private function upsertMenu(string $portalId, string $code, string $name, string $path, int $sort, string $prefix): void
    {
        $old = DB::table('access_menus')->where('code',$code)->first();
        $menuId = (string) ($old->id ?? Str::ulid());
        $now = now();
        DB::table('access_menus')->updateOrInsert(['code'=>$code], [
            'id'=>$menuId,'portal_id'=>$portalId,'name'=>$name,'path'=>$path,'sort_order'=>$sort,
            'permission_view'=>$prefix.'.view','permission_create'=>$prefix.'.create','permission_update'=>$prefix.'.update','permission_delete'=>$prefix.'.delete',
            'is_active'=>true,'created_at'=>$old->created_at ?? $now,'updated_at'=>$now,
        ]);
        if (! Schema::hasTable('access_role_menu_permissions')) return;
        $sourceId = DB::table('access_menus')->where('code','finance-purchasing-posting')->value('id')
            ?: DB::table('access_menus')->where('code','finance-manual-journal')->value('id');
        if (! $sourceId) return;
        foreach (DB::table('access_role_menu_permissions')->where('menu_id',$sourceId)->get() as $row) {
            $q = DB::table('access_role_menu_permissions')->where('access_role_id',$row->access_role_id)->where('menu_id',$menuId);
            $row->access_level_id === null ? $q->whereNull('access_level_id') : $q->where('access_level_id',$row->access_level_id);
            if ($q->exists()) continue;
            $role = Schema::hasTable('access_roles') ? DB::table('access_roles')->where('id',$row->access_role_id)->first(['code','spatie_role_name']) : null;
            $admin = str_contains(strtoupper((string)($role->code ?? '')), 'ADMIN') || str_contains(strtolower((string)($role->spatie_role_name ?? '')), 'admin');
            DB::table('access_role_menu_permissions')->insert([
                'id'=>(string)Str::ulid(),'access_role_id'=>$row->access_role_id,'access_level_id'=>$row->access_level_id,'menu_id'=>$menuId,
                'can_view'=>(bool)$row->can_view,'can_create'=>$admin,'can_edit'=>$admin,'can_delete'=>$admin,'created_at'=>$now,'updated_at'=>$now,
            ]);
        }
    }
};
