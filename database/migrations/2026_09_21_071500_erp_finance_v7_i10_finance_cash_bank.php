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
    private const TYPES = [
        'cash_in' => ['Cash In','/finance/cash-in',201,'BKM'],
        'cash_out' => ['Cash Out','/finance/cash-out',202,'BKK'],
        'bank_in' => ['Bank In','/finance/bank-in',203,'BBM'],
        'bank_out' => ['Bank Out','/finance/bank-out',204,'BBK'],
        'book_transfer' => ['Book Transfer','/finance/book-transfer',200,'BT'],
    ];

    public function up(): void
    {
        foreach (['users','finance_companies','finance_chart_of_accounts','finance_general_postings'] as $table) {
            if (! Schema::hasTable($table)) throw new RuntimeException("I10 membutuhkan tabel {$table}. Apply I01-I09 terlebih dahulu.");
        }

        $this->accounts();
        $this->transactions();
        $this->events();
        $this->seedDefaultAccounts();
        $this->registerAccess();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function accounts(): void
    {
        if (Schema::hasTable('finance_treasury_accounts')) return;
        Schema::create('finance_treasury_accounts', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('company_code',20)->index();
            $t->string('code',60);
            $t->string('name',160);
            $t->string('account_type',16)->index(); // CASH/BANK
            $t->string('bank_name',120)->nullable();
            $t->string('account_name',160)->nullable();
            $t->string('account_number',120)->nullable();
            $t->char('finance_coa_id',26)->index();
            $t->boolean('is_active')->default(true)->index();
            $t->char('created_by_user_id',26)->nullable()->index();
            $t->char('updated_by_user_id',26)->nullable()->index();
            $t->timestamps();
            $t->unique(['company_code','code'],'fin_treas_acc_company_code_uq');
            $t->index(['company_code','account_type','is_active'],'fin_treas_acc_company_type_idx');
            $t->foreign('finance_coa_id','fin_treas_acc_coa_fk')->references('id')->on('finance_chart_of_accounts')->restrictOnDelete();
            $t->foreign('created_by_user_id','fin_treas_acc_creator_fk')->references('id')->on('users')->nullOnDelete();
            $t->foreign('updated_by_user_id','fin_treas_acc_updater_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function transactions(): void
    {
        if (Schema::hasTable('finance_treasury_transactions')) return;
        Schema::create('finance_treasury_transactions', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('treasury_number',90)->unique();
            $t->string('company_code',20)->index();
            $t->string('transaction_type',24)->index();
            $t->string('document_template',16)->index();
            $t->string('marking',16)->default('MARKING')->index();
            $t->date('transaction_date')->index();
            $t->decimal('amount',22,2);
            $t->string('currency_code',3)->default('IDR');
            $t->char('from_treasury_account_id',26)->nullable()->index();
            $t->char('to_treasury_account_id',26)->nullable()->index();
            $t->char('counter_account_id',26)->nullable()->index();
            $t->json('from_account_snapshot')->nullable();
            $t->json('to_account_snapshot')->nullable();
            $t->json('counter_account_snapshot')->nullable();
            $t->string('counterparty_name',180)->nullable();
            $t->string('reference_number',140)->nullable()->index();
            $t->text('description')->nullable();
            $t->text('notes')->nullable();
            $t->string('status',20)->default('DRAFT')->index();
            $t->string('source_key',191)->unique();
            $t->char('general_posting_id',26)->nullable()->index();
            $t->json('metadata')->nullable();
            $t->char('created_by_user_id',26)->nullable()->index();
            $t->char('updated_by_user_id',26)->nullable()->index();
            $t->char('submitted_by_user_id',26)->nullable()->index();
            $t->timestamp('submitted_at')->nullable();
            $t->char('approved_by_user_id',26)->nullable()->index();
            $t->timestamp('approved_at')->nullable();
            $t->char('rejected_by_user_id',26)->nullable()->index();
            $t->timestamp('rejected_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->timestamps();
            $t->index(['company_code','transaction_type','status','transaction_date'],'fin_treas_company_type_status_idx');
            $t->foreign('from_treasury_account_id','fin_treas_from_acc_fk')->references('id')->on('finance_treasury_accounts')->nullOnDelete();
            $t->foreign('to_treasury_account_id','fin_treas_to_acc_fk')->references('id')->on('finance_treasury_accounts')->nullOnDelete();
            $t->foreign('counter_account_id','fin_treas_counter_coa_fk')->references('id')->on('finance_chart_of_accounts')->nullOnDelete();
            $t->foreign('general_posting_id','fin_treas_gp_fk')->references('id')->on('finance_general_postings')->nullOnDelete();
            $t->foreign('created_by_user_id','fin_treas_creator_fk')->references('id')->on('users')->nullOnDelete();
            $t->foreign('updated_by_user_id','fin_treas_updater_fk')->references('id')->on('users')->nullOnDelete();
            $t->foreign('submitted_by_user_id','fin_treas_submit_fk')->references('id')->on('users')->nullOnDelete();
            $t->foreign('approved_by_user_id','fin_treas_approve_fk')->references('id')->on('users')->nullOnDelete();
            $t->foreign('rejected_by_user_id','fin_treas_reject_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function events(): void
    {
        if (Schema::hasTable('finance_treasury_events')) return;
        Schema::create('finance_treasury_events', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->char('treasury_transaction_id',26)->index();
            $t->string('event_type',60)->index();
            $t->string('from_status',20)->nullable();
            $t->string('to_status',20)->nullable();
            $t->text('message')->nullable();
            $t->json('metadata')->nullable();
            $t->char('actor_user_id',26)->nullable()->index();
            $t->timestamp('occurred_at')->useCurrent()->index();
            $t->timestamps();
            $t->foreign('treasury_transaction_id','fin_treas_evt_tx_fk')->references('id')->on('finance_treasury_transactions')->cascadeOnDelete();
            $t->foreign('actor_user_id','fin_treas_evt_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function seedDefaultAccounts(): void
    {
        $cash = DB::table('finance_chart_of_accounts')->where('code','1-10001')->where('is_active',true)->value('id');
        $bank = DB::table('finance_chart_of_accounts')->where('code','1-10002')->where('is_active',true)->value('id');
        if (! $cash || ! $bank) throw new RuntimeException('I10 membutuhkan COA 1-10001 Kas dan 1-10002 Rekening Bank.');
        foreach (DB::table('finance_companies')->where('is_active',true)->whereIn('code',['BKJB','MDMF'])->pluck('code') as $company) {
            foreach ([['CASH','Kas '.$company,'CASH',$cash],['BANK','Rekening Bank '.$company,'BANK',$bank]] as [$code,$name,$type,$coa]) {
                if (DB::table('finance_treasury_accounts')->where('company_code',$company)->where('code',$code)->exists()) continue;
                DB::table('finance_treasury_accounts')->insert([
                    'id'=>(string)Str::ulid(),'company_code'=>$company,'code'=>$code,'name'=>$name,'account_type'=>$type,
                    'bank_name'=>null,'account_name'=>$company,'account_number'=>null,'finance_coa_id'=>$coa,'is_active'=>true,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
        }
    }

    private function registerAccess(): void
    {
        $permissions=[];
        foreach (array_keys(self::TYPES) as $type) {
            $base='finance.treasury.'.str_replace('_','.',$type);
            foreach (['view','create','update','submit','approve'] as $action) $permissions[]="{$base}.{$action}";
        }
        foreach($permissions as $permission) Permission::findOrCreate($permission,'web');
        Role::query()->where('guard_name','web')->whereIn(DB::raw('LOWER(name)'),['admin','administrator','superadmin','super-admin','finance'])->get()->each(fn(Role $r)=>$r->givePermissionTo($permissions));

        if (!Schema::hasTable('access_portals') || !Schema::hasTable('access_menus')) return;
        $portal=DB::table('access_portals')->where('code','finance')->first(); if(!$portal)return;
        $now=now();
        foreach(self::TYPES as $type=>[$name,$path,$sort]) {
            $base='finance.treasury.'.str_replace('_','.',$type);$code='finance-i10-'.str_replace('_','-',$type);
            $existing=DB::table('access_menus')->where('path',$path)->where('portal_id',$portal->id)->first() ?: DB::table('access_menus')->where('code',$code)->first();
            $id=(string)($existing->id??Str::ulid());
            DB::table('access_menus')->updateOrInsert(['id'=>$id],[
                'code'=>$code,'portal_id'=>$portal->id,'name'=>$name,'path'=>$path,'sort_order'=>$sort,
                'permission_view'=>"{$base}.view",'permission_create'=>"{$base}.create",'permission_update'=>"{$base}.update",'permission_delete'=>null,
                'is_active'=>true,'created_at'=>$existing->created_at??$now,'updated_at'=>$now,
            ]);
            $this->seedMatrix($id,$now);
        }
    }

    private function seedMatrix(string $menuId,$now): void
    {
        if(!Schema::hasTable('access_roles')||!Schema::hasTable('access_role_menu_permissions'))return;
        $levels=Schema::hasTable('access_levels')?DB::table('access_levels')->pluck('id')->map(fn($x)=>(string)$x)->all():[];
        foreach(DB::table('access_roles')->get(['id','code']) as $role){
            $enabled=in_array(strtoupper((string)$role->code),['ADMIN','FINANCE','MANAGER','MANAJER'],true);
            foreach(array_merge([null],$levels) as $level){
                $q=DB::table('access_role_menu_permissions')->where('access_role_id',$role->id)->where('menu_id',$menuId);$level===null?$q->whereNull('access_level_id'):$q->where('access_level_id',$level);
                $payload=['can_view'=>$enabled,'can_create'=>$enabled,'can_edit'=>$enabled,'can_delete'=>false,'updated_at'=>$now];
                if($q->exists())$q->update($payload);else DB::table('access_role_menu_permissions')->insert($payload+['id'=>(string)Str::ulid(),'access_role_id'=>$role->id,'access_level_id'=>$level,'menu_id'=>$menuId,'created_at'=>$now]);
            }
        }
    }

    public function down(): void {}
};
