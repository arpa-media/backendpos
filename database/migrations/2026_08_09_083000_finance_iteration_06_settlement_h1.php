<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration {
    private array $menu = [
        'code'=>'finance-settlement','name'=>'Settlement','path'=>'/finance/settlement','sort_order'=>40,
        'permission_view'=>'finance.settlement.view','permission_create'=>'finance.settlement.create',
        'permission_update'=>'finance.settlement.update','permission_delete'=>'finance.settlement.delete',
    ];

    public function up(): void
    {
        foreach (['finance_reconciliations','finance_reconciliation_payments','finance_reconciliation_payment_allocations','finance_reconciliation_postings','finance_journal_entries','finance_chart_of_accounts'] as $table) {
            if (! Schema::hasTable($table)) throw new \RuntimeException("Finance Iterasi 06 membutuhkan {$table}. Apply Iterasi 03 dan 05 terlebih dahulu.");
        }
        $this->seedSettlementCoas();
        $this->createTables();
        $this->registerPermissionsAndAccessMatrix();
        $this->createReconciliationReversalGuard();
    }

    private function createTables(): void
    {
        if (! Schema::hasTable('finance_settlement_mappings')) {
            Schema::create('finance_settlement_mappings', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('mapping_key',191)->unique();
                $table->string('company_code',16)->index();
                $table->char('outlet_id',26)->nullable()->index();
                $table->char('payment_method_id',26)->nullable()->index();
                $table->string('payment_method_name',120)->index();
                $table->string('marking',16)->default('ALL')->index();
                $table->unsignedTinyInteger('settlement_days')->default(1);
                $table->char('bank_account_id',26)->index();
                $table->char('mdr_expense_account_id',26)->nullable()->index();
                $table->char('admin_fee_expense_account_id',26)->nullable()->index();
                $table->decimal('mdr_rate',10,6)->default(0);
                $table->decimal('mdr_fixed',18,2)->default(0);
                $table->decimal('admin_fee_rate',10,6)->default(0);
                $table->decimal('admin_fee_fixed',18,2)->default(0);
                $table->boolean('is_active')->default(true)->index();
                $table->text('notes')->nullable();
                $table->char('created_by_user_id',26)->nullable()->index();
                $table->char('updated_by_user_id',26)->nullable()->index();
                $table->timestamps();
                $table->foreign('outlet_id','fin_stl_map_outlet_fk')->references('id')->on('outlets')->nullOnDelete();
                $table->foreign('bank_account_id','fin_stl_map_bank_fk')->references('id')->on('finance_chart_of_accounts')->restrictOnDelete();
                $table->foreign('mdr_expense_account_id','fin_stl_map_mdr_fk')->references('id')->on('finance_chart_of_accounts')->nullOnDelete();
                $table->foreign('admin_fee_expense_account_id','fin_stl_map_admin_fk')->references('id')->on('finance_chart_of_accounts')->nullOnDelete();
                $table->index(['company_code','outlet_id','payment_method_id','marking'],'fin_stl_map_scope_idx');
            });
        }

        if (! Schema::hasTable('finance_settlement_sources')) {
            Schema::create('finance_settlement_sources', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('source_key',191)->unique();
                $table->char('reconciliation_id',26)->index();
                $table->string('reconciliation_no',48)->index();
                $table->char('reconciliation_posting_id',26)->index();
                $table->char('reconciliation_payment_id',26)->index();
                $table->char('reconciliation_allocation_id',26)->index();
                $table->unsignedInteger('posting_version')->default(1)->index();
                $table->string('company_code',16)->index();
                $table->char('outlet_id',26)->index();
                $table->date('business_date')->index();
                $table->date('expected_settlement_date')->index();
                $table->string('marking',16)->index();
                $table->char('payment_method_id',26)->nullable()->index();
                $table->string('payment_method_name',120)->index();
                $table->char('source_journal_entry_id',26)->index();
                $table->string('source_journal_no',48)->index();
                $table->char('clearing_account_id',26)->index();
                $table->string('clearing_account_code',32);
                $table->string('clearing_account_name',180);
                $table->decimal('original_amount',18,2)->default(0);
                $table->decimal('settled_amount',18,2)->default(0);
                $table->decimal('outstanding_amount',18,2)->default(0);
                $table->char('mapping_id',26)->nullable()->index();
                $table->string('status',24)->default('OPEN')->index();
                $table->timestamps();
                $table->foreign('reconciliation_id','fin_stl_src_rec_fk')->references('id')->on('finance_reconciliations')->restrictOnDelete();
                $table->foreign('reconciliation_posting_id','fin_stl_src_post_fk')->references('id')->on('finance_reconciliation_postings')->restrictOnDelete();
                $table->foreign('reconciliation_payment_id','fin_stl_src_pay_fk')->references('id')->on('finance_reconciliation_payments')->restrictOnDelete();
                $table->foreign('reconciliation_allocation_id','fin_stl_src_alloc_fk')->references('id')->on('finance_reconciliation_payment_allocations')->restrictOnDelete();
                $table->foreign('outlet_id','fin_stl_src_outlet_fk')->references('id')->on('outlets')->restrictOnDelete();
                $table->foreign('source_journal_entry_id','fin_stl_src_journal_fk')->references('id')->on('finance_journal_entries')->restrictOnDelete();
                $table->foreign('clearing_account_id','fin_stl_src_clear_fk')->references('id')->on('finance_chart_of_accounts')->restrictOnDelete();
                $table->foreign('mapping_id','fin_stl_src_map_fk')->references('id')->on('finance_settlement_mappings')->nullOnDelete();
                $table->index(['company_code','business_date','status'],'fin_stl_src_company_date_idx');
                $table->index(['outlet_id','expected_settlement_date','status'],'fin_stl_src_outlet_expected_idx');
            });
        }

        if (! Schema::hasTable('finance_settlements')) {
            Schema::create('finance_settlements', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('settlement_no',48)->unique();
                $table->char('settlement_source_id',26)->index();
                $table->char('mapping_id',26)->nullable()->index();
                $table->date('settlement_date')->index();
                $table->date('business_date')->index();
                $table->string('company_code',16)->index();
                $table->char('outlet_id',26)->index();
                $table->string('marking',16)->index();
                $table->char('payment_method_id',26)->nullable()->index();
                $table->string('payment_method_name',120)->index();
                $table->char('clearing_account_id',26)->index();
                $table->char('bank_account_id',26)->index();
                $table->char('mdr_expense_account_id',26)->nullable()->index();
                $table->char('admin_fee_expense_account_id',26)->nullable()->index();
                $table->decimal('clearing_amount',18,2)->default(0);
                $table->decimal('bank_received_amount',18,2)->default(0);
                $table->decimal('mdr_amount',18,2)->default(0);
                $table->decimal('admin_fee_amount',18,2)->default(0);
                $table->string('status',16)->default('DRAFT')->index();
                $table->unsignedInteger('posting_version')->default(0);
                $table->char('journal_entry_id',26)->nullable()->index();
                $table->string('journal_no',48)->nullable()->index();
                $table->char('reversal_journal_id',26)->nullable()->index();
                $table->string('reversal_journal_no',48)->nullable()->index();
                $table->text('note')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->char('posted_by_user_id',26)->nullable()->index();
                $table->timestamp('reversed_at')->nullable();
                $table->char('reversed_by_user_id',26)->nullable()->index();
                $table->char('created_by_user_id',26)->nullable()->index();
                $table->char('updated_by_user_id',26)->nullable()->index();
                $table->timestamps();
                $table->foreign('settlement_source_id','fin_stl_src_fk')->references('id')->on('finance_settlement_sources')->restrictOnDelete();
                $table->foreign('mapping_id','fin_stl_mapping_fk')->references('id')->on('finance_settlement_mappings')->nullOnDelete();
                $table->foreign('outlet_id','fin_stl_outlet_fk')->references('id')->on('outlets')->restrictOnDelete();
                $table->foreign('clearing_account_id','fin_stl_clear_fk')->references('id')->on('finance_chart_of_accounts')->restrictOnDelete();
                $table->foreign('bank_account_id','fin_stl_bank_fk')->references('id')->on('finance_chart_of_accounts')->restrictOnDelete();
                $table->foreign('mdr_expense_account_id','fin_stl_mdr_fk')->references('id')->on('finance_chart_of_accounts')->nullOnDelete();
                $table->foreign('admin_fee_expense_account_id','fin_stl_admin_fk')->references('id')->on('finance_chart_of_accounts')->nullOnDelete();
                $table->foreign('journal_entry_id','fin_stl_journal_fk')->references('id')->on('finance_journal_entries')->nullOnDelete();
                $table->foreign('reversal_journal_id','fin_stl_rev_fk')->references('id')->on('finance_journal_entries')->nullOnDelete();
                $table->index(['settlement_source_id','status'],'fin_stl_source_status_idx');
                $table->index(['company_code','settlement_date','status'],'fin_stl_company_date_idx');
            });
        }
    }

    private function seedSettlementCoas(): void
    {
        $now=now();
        foreach ([
            ['code'=>'6-60007','name'=>'Biaya MDR Merchant','parent'=>'6-60000'],
            ['code'=>'6-60111','name'=>'Biaya Administrasi Bank','parent'=>'6-60100'],
        ] as $coa) {
            if (DB::table('finance_chart_of_accounts')->where('code',$coa['code'])->exists()) continue;
            $parent=DB::table('finance_chart_of_accounts')->where('code',$coa['parent'])->first(['id','level_no']);
            DB::table('finance_chart_of_accounts')->insert([
                'id'=>(string)Str::ulid(),'code'=>$coa['code'],'name'=>$coa['name'],'account_type'=>'EXPENSE','normal_balance'=>'DEBIT',
                'parent_id'=>$parent?->id,'level_no'=>$parent?min(255,(int)$parent->level_no+1):2,'is_header'=>false,'is_postable'=>true,'is_active'=>true,
                'created_at'=>$now,'updated_at'=>$now,
            ]);
        }
    }

    private function registerPermissionsAndAccessMatrix(): void
    {
        $permissions=['finance.settlement.view','finance.settlement.create','finance.settlement.update','finance.settlement.delete','finance.settlement.post','finance.settlement.reopen','finance.settlement.manage_mapping'];
        foreach($permissions as $permission) Permission::findOrCreate($permission,'web');
        Role::query()->where('guard_name','web')->whereIn(DB::raw('LOWER(name)'),['admin','administrator','superadmin','super-admin'])->get()->each(fn(Role $r)=>$r->givePermissionTo($permissions));
        if(!Schema::hasTable('access_portals')||!Schema::hasTable('access_menus'))return;
        $portal=DB::table('access_portals')->where('code','finance')->first();if(!$portal)return;
        $existing=DB::table('access_menus')->where('code',$this->menu['code'])->first();$menuId=(string)($existing->id??Str::ulid());$now=now();
        DB::table('access_menus')->updateOrInsert(['code'=>$this->menu['code']],[
            'id'=>$menuId,'portal_id'=>$portal->id,'name'=>$this->menu['name'],'path'=>$this->menu['path'],'sort_order'=>$this->menu['sort_order'],
            'permission_view'=>$this->menu['permission_view'],'permission_create'=>$this->menu['permission_create'],'permission_update'=>$this->menu['permission_update'],'permission_delete'=>$this->menu['permission_delete'],
            'is_active'=>true,'created_at'=>$existing->created_at??$now,'updated_at'=>$now,
        ]);
        if(!Schema::hasTable('access_role_menu_permissions'))return;
        $sourceMenuId=DB::table('access_menus')->where('code','finance-dashboard')->value('id');
        $sourceRows=$sourceMenuId?DB::table('access_role_menu_permissions')->where('menu_id',$sourceMenuId)->get():collect();
        foreach($sourceRows as $source){
            $q=DB::table('access_role_menu_permissions')->where('access_role_id',$source->access_role_id)->where('menu_id',$menuId);
            $source->access_level_id===null?$q->whereNull('access_level_id'):$q->where('access_level_id',$source->access_level_id);
            if($q->exists())continue;
            $role=Schema::hasTable('access_roles')?DB::table('access_roles')->where('id',$source->access_role_id)->first(['code','spatie_role_name']):null;
            $isAdmin=str_contains(strtoupper((string)($role->code??'')),'ADMIN')||str_contains(strtolower((string)($role->spatie_role_name??'')),'admin');
            DB::table('access_role_menu_permissions')->insert(['id'=>(string)Str::ulid(),'access_role_id'=>$source->access_role_id,'access_level_id'=>$source->access_level_id,'menu_id'=>$menuId,'can_view'=>(bool)$source->can_view,'can_create'=>$isAdmin,'can_edit'=>$isAdmin,'can_delete'=>$isAdmin,'created_at'=>$now,'updated_at'=>$now]);
        }
    }

    private function createReconciliationReversalGuard(): void
    {
        if(!in_array(DB::getDriverName(), ['mysql','mariadb'], true)||!Schema::hasTable('finance_journal_entries'))return;

        try {
            DB::unprepared('DROP TRIGGER IF EXISTS finance_iter06_guard_recon_reversal');
            DB::unprepared("CREATE TRIGGER finance_iter06_guard_recon_reversal BEFORE UPDATE ON finance_journal_entries FOR EACH ROW BEGIN IF OLD.source_type = 'RECONCILIATION' AND OLD.status = 'POSTED' AND NEW.status = 'REVERSED' AND EXISTS (SELECT 1 FROM finance_settlement_sources s JOIN finance_settlements t ON t.settlement_source_id = s.id WHERE s.source_journal_entry_id = OLD.id AND t.status IN ('DRAFT','POSTED')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reconciliation sudah memiliki Settlement DRAFT/POSTED. Hapus/reversal Settlement terlebih dahulu.'; END IF; END");
        } catch (\Throwable $e) {
            // Shared hosting / managed MySQL sering mengaktifkan binary logging tetapi
            // tidak memberi SUPER/SYSTEM_VARIABLES_ADMIN untuk CREATE TRIGGER.
            // FinanceGeneralPostingService membawa guard aplikasi yang ekuivalen,
            // sehingga trigger ini hanya defense-in-depth dan bukan syarat migration.
            report($e);
        }
    }

    public function down(): void
    {
        if(in_array(DB::getDriverName(), ['mysql','mariadb'], true)) {
            try { DB::unprepared('DROP TRIGGER IF EXISTS finance_iter06_guard_recon_reversal'); } catch (\Throwable $e) { report($e); }
        }
        if(Schema::hasTable('access_menus'))DB::table('access_menus')->where('code',$this->menu['code'])->update(['is_active'=>false,'updated_at'=>now()]);
        Schema::dropIfExists('finance_settlements');
        Schema::dropIfExists('finance_settlement_sources');
        Schema::dropIfExists('finance_settlement_mappings');
    }
};
