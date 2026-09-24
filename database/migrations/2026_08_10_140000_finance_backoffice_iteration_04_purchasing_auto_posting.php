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
        foreach (['finance_chart_of_accounts','finance_journal_entries','finance_journal_entry_lines','pur_finance_posting_outbox','pur_invoices'] as $table) {
            if (!Schema::hasTable($table)) throw new RuntimeException("Finance Backoffice Iterasi 04 membutuhkan {$table}.");
        }

        if (!Schema::hasTable('finance_purchasing_auto_events')) {
            Schema::create('finance_purchasing_auto_events', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->string('event_key',191)->unique();
                $t->string('event_type',80)->index();
                $t->string('source_type',80)->index();
                $t->string('source_id',80)->index();
                $t->char('invoice_id',26)->nullable()->index();
                $t->char('payment_id',26)->nullable()->index();
                $t->char('goods_receipt_id',26)->nullable()->index();
                $t->char('outbox_id',26)->nullable()->index();
                $t->string('company_code',16)->nullable()->index();
                $t->char('outlet_id',26)->nullable()->index();
                $t->date('business_date')->nullable()->index();
                $t->decimal('amount',20,2)->default(0);
                $t->string('status',30)->default('PENDING')->index();
                $t->char('journal_entry_id',26)->nullable()->index();
                $t->string('journal_no',48)->nullable();
                $t->string('covered_by_event_key',191)->nullable()->index();
                $t->json('mapping_snapshot')->nullable();
                $t->json('payload')->nullable();
                $t->text('last_error')->nullable();
                $t->unsignedInteger('attempts')->default(0);
                $t->timestamp('posted_at')->nullable();
                $t->char('posted_by_user_id',26)->nullable()->index();
                $t->timestamps();
                $t->index(['outlet_id','business_date','status'],'fin_pur_auto_outlet_date_status_idx');
                $t->index(['invoice_id','event_type','status'],'fin_pur_auto_invoice_event_idx');
                $t->foreign('journal_entry_id','fin_pur_auto_journal_fk')->references('id')->on('finance_journal_entries')->nullOnDelete();
                $t->foreign('outlet_id','fin_pur_auto_outlet_fk')->references('id')->on('outlets')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('finance_warehouse_payment_account_mappings')) {
            Schema::create('finance_warehouse_payment_account_mappings', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->char('payment_account_id',26)->unique();
                $t->string('company_code',16)->nullable()->index();
                $t->char('outlet_id',26)->nullable()->index();
                $t->char('cash_account_id',26)->index();
                $t->boolean('is_active')->default(true)->index();
                $t->text('notes')->nullable();
                $t->char('created_by_user_id',26)->nullable();
                $t->char('updated_by_user_id',26)->nullable();
                $t->timestamps();
                if (Schema::hasTable('wh_v3_payment_accounts')) {
                    $t->foreign('payment_account_id','fin_wh_paymap_payment_fk')->references('id')->on('wh_v3_payment_accounts')->cascadeOnDelete();
                }
                $t->foreign('cash_account_id','fin_wh_paymap_cash_fk')->references('id')->on('finance_chart_of_accounts')->restrictOnDelete();
                $t->foreign('outlet_id','fin_wh_paymap_outlet_fk')->references('id')->on('outlets')->nullOnDelete();
            });
        }

        $this->registerAccess();
    }

    private function registerAccess(): void
    {
        $permissions=[
            'finance.purchasing_posting.view','finance.purchasing_posting.create','finance.purchasing_posting.update',
            'finance.purchasing_posting.delete','finance.purchasing_posting.post','finance.purchasing_posting.reopen',
            'finance.purchasing_posting.manage_mapping','finance.purchasing_posting.retry_auto',
        ];
        foreach($permissions as $permission) Permission::findOrCreate($permission,'web');
        Role::query()->where('guard_name','web')->whereIn(DB::raw('LOWER(name)'),['admin','administrator','superadmin','super-admin'])->get()
            ->each(fn(Role $role)=>$role->givePermissionTo($permissions));

        if (!Schema::hasTable('access_portals') || !Schema::hasTable('access_menus')) return;
        $portal=DB::table('access_portals')->where('code','finance')->first(); if(!$portal)return;
        $now=now(); $existing=DB::table('access_menus')->where('code','finance-purchasing-posting')->first(); $menuId=(string)($existing->id??Str::ulid());
        DB::table('access_menus')->updateOrInsert(['code'=>'finance-purchasing-posting'],[
            'id'=>$menuId,'portal_id'=>$portal->id,'name'=>'Purchasing Posting','path'=>'/finance/purchasing-posting','sort_order'=>55,
            'permission_view'=>'finance.purchasing_posting.view','permission_create'=>'finance.purchasing_posting.create',
            'permission_update'=>'finance.purchasing_posting.update','permission_delete'=>'finance.purchasing_posting.delete','is_active'=>true,
            'created_at'=>$existing->created_at??$now,'updated_at'=>$now,
        ]);
        if(!Schema::hasTable('access_role_menu_permissions'))return;
        $sourceId=DB::table('access_menus')->where('code','finance-cogs-posting')->value('id')
            ?: DB::table('access_menus')->where('code','finance-general-ledger')->value('id');
        if(!$sourceId)return;
        foreach(DB::table('access_role_menu_permissions')->where('menu_id',$sourceId)->get() as $row){
            $q=DB::table('access_role_menu_permissions')->where('access_role_id',$row->access_role_id)->where('menu_id',$menuId);
            $row->access_level_id===null?$q->whereNull('access_level_id'):$q->where('access_level_id',$row->access_level_id);
            if($q->exists())continue;
            $role=Schema::hasTable('access_roles')?DB::table('access_roles')->where('id',$row->access_role_id)->first(['code','spatie_role_name']):null;
            $admin=str_contains(strtoupper((string)($role->code??'')),'ADMIN')||str_contains(strtolower((string)($role->spatie_role_name??'')),'admin');
            DB::table('access_role_menu_permissions')->insert([
                'id'=>(string)Str::ulid(),'access_role_id'=>$row->access_role_id,'access_level_id'=>$row->access_level_id,'menu_id'=>$menuId,
                'can_view'=>(bool)$row->can_view,'can_create'=>$admin,'can_edit'=>$admin,'can_delete'=>$admin,
                'created_at'=>$now,'updated_at'=>$now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_warehouse_payment_account_mappings');
        Schema::dropIfExists('finance_purchasing_auto_events');
    }
};
