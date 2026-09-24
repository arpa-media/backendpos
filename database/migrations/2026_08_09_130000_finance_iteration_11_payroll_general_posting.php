<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration {
    public function up():void
    {
        foreach(['finance_posting_templates','finance_posting_template_lines','finance_chart_of_accounts','finance_journal_entries','finance_journal_entry_lines','finance_outlet_company_mappings'] as $t)if(!Schema::hasTable($t))throw new RuntimeException("Finance Iterasi 11 membutuhkan {$t} dari Iterasi 03.");
        $this->tables();$this->access();
    }

    public function down():void
    {
        if(Schema::hasTable('access_menus'))foreach(['finance-payroll-posting','finance-general-posting'] as $code){$id=DB::table('access_menus')->where('code',$code)->value('id');if($id&&Schema::hasTable('access_role_menu_permissions'))DB::table('access_role_menu_permissions')->where('menu_id',$id)->delete();DB::table('access_menus')->where('code',$code)->delete();}
        Schema::dropIfExists('finance_general_posting_journals');Schema::dropIfExists('finance_general_postings');Schema::dropIfExists('finance_payroll_posting_inbox');
    }

    private function tables():void
    {
        if(!Schema::hasTable('finance_payroll_posting_inbox'))Schema::create('finance_payroll_posting_inbox',function(Blueprint $t):void{
            $t->ulid('id')->primary();$t->string('contract_version',32);$t->string('external_request_key',150)->unique();$t->string('source_system',40)->index();$t->string('payroll_batch_id',120)->index();$t->string('company_code',16)->index();$t->char('outlet_id',26)->nullable()->index();
            $t->date('period_from')->index();$t->date('period_to')->index();$t->date('business_date')->index();$t->string('currency',3)->default('IDR');$t->decimal('gross_pay',20,2)->default(0);$t->decimal('deductions',20,2)->default(0);$t->decimal('net_pay',20,2)->default(0);$t->decimal('payable',20,2)->default(0);$t->unsignedInteger('employee_count')->default(0);
            $t->string('status',48)->default('RECEIVED_WAITING_HR_INTEGRATION')->index();$t->string('source_fingerprint',64);$t->json('payload')->nullable();$t->timestamp('received_at');$t->char('received_by_user_id',26)->nullable()->index();$t->timestamps();
            $t->foreign('outlet_id','fin_payroll_inbox_outlet_fk')->references('id')->on('outlets')->nullOnDelete();$t->index(['company_code','period_from','period_to'],'fin_payroll_period_idx');
        });

        if(!Schema::hasTable('finance_general_postings'))Schema::create('finance_general_postings',function(Blueprint $t):void{
            $t->ulid('id')->primary();$t->string('posting_no',80)->unique();$t->string('source_key',150)->unique();$t->string('source_code',40)->index();$t->string('reference_no',120)->nullable()->index();
            $t->string('company_code',16)->index();$t->char('outlet_id',26)->nullable()->index();$t->string('marking',16)->index();$t->char('template_id',26)->index();$t->date('business_date')->index();$t->date('journal_date')->index();$t->text('description');
            $t->decimal('amount',20,2);$t->decimal('subtotal',20,2)->default(0);$t->decimal('tax',20,2)->default(0);$t->decimal('discount',20,2)->default(0);$t->decimal('rounding',20,2)->default(0);$t->decimal('mdr',20,2)->default(0);$t->decimal('admin_fee',20,2)->default(0);$t->decimal('payable',20,2)->default(0);
            $t->json('metadata')->nullable();$t->string('source_fingerprint',64);$t->string('status',16)->default('DRAFT')->index();$t->unsignedInteger('posting_version')->default(0);$t->timestamp('posted_at')->nullable();$t->char('posted_by_user_id',26)->nullable()->index();$t->timestamp('reopened_at')->nullable();$t->char('reopened_by_user_id',26)->nullable()->index();$t->text('reopen_reason')->nullable();$t->char('created_by_user_id',26)->nullable()->index();$t->char('updated_by_user_id',26)->nullable()->index();$t->timestamps();
            $t->foreign('outlet_id','fin_gen_post_outlet_fk')->references('id')->on('outlets')->nullOnDelete();$t->foreign('template_id','fin_gen_post_tpl_fk')->references('id')->on('finance_posting_templates')->restrictOnDelete();$t->index(['company_code','business_date','status'],'fin_gen_company_date_idx');$t->index(['outlet_id','business_date','status'],'fin_gen_outlet_date_idx');
        });

        if(!Schema::hasTable('finance_general_posting_journals'))Schema::create('finance_general_posting_journals',function(Blueprint $t):void{
            $t->ulid('id')->primary();$t->char('general_posting_id',26)->index();$t->unsignedInteger('posting_version')->index();$t->char('journal_entry_id',26)->index();$t->string('journal_no',48)->index();$t->char('reversal_journal_id',26)->nullable()->index();$t->string('reversal_journal_no',48)->nullable()->index();$t->timestamp('posted_at');$t->timestamp('reversed_at')->nullable();$t->timestamps();
            $t->foreign('general_posting_id','fin_gen_j_post_fk')->references('id')->on('finance_general_postings')->cascadeOnDelete();$t->foreign('journal_entry_id','fin_gen_j_entry_fk')->references('id')->on('finance_journal_entries')->restrictOnDelete();$t->foreign('reversal_journal_id','fin_gen_j_rev_fk')->references('id')->on('finance_journal_entries')->nullOnDelete();$t->unique(['general_posting_id','posting_version'],'fin_gen_j_version_uq');
        });
    }

    private function access():void
    {
        $permissions=[
            'finance.payroll_posting.view','finance.payroll_posting.create','finance.payroll_posting.update','finance.payroll_posting.delete','finance.payroll_posting.post',
            'finance.general_posting.view','finance.general_posting.create','finance.general_posting.update','finance.general_posting.delete','finance.general_posting.post','finance.general_posting.reopen',
        ];
        foreach($permissions as $p)Permission::findOrCreate($p,'web');
        Role::query()->where('guard_name','web')->whereIn(DB::raw('LOWER(name)'),['admin','administrator','superadmin','super-admin'])->get()->each(fn(Role $r)=>$r->givePermissionTo($permissions));
        if(!Schema::hasTable('access_portals')||!Schema::hasTable('access_menus'))return;$portal=DB::table('access_portals')->where('code','finance')->first();if(!$portal)return;$now=now();
        $menus=[
            ['code'=>'finance-payroll-posting','name'=>'Payroll Posting','path'=>'/finance/payroll-posting','sort'=>100,'prefix'=>'finance.payroll_posting'],
            ['code'=>'finance-general-posting','name'=>'General Posting','path'=>'/finance/general-posting','sort'=>120,'prefix'=>'finance.general_posting'],
        ];
        foreach($menus as $menu){$old=DB::table('access_menus')->where('code',$menu['code'])->first();$menuId=(string)($old->id??Str::ulid());DB::table('access_menus')->updateOrInsert(['code'=>$menu['code']],['id'=>$menuId,'portal_id'=>$portal->id,'name'=>$menu['name'],'path'=>$menu['path'],'sort_order'=>$menu['sort'],'permission_view'=>$menu['prefix'].'.view','permission_create'=>$menu['prefix'].'.create','permission_update'=>$menu['prefix'].'.update','permission_delete'=>$menu['prefix'].'.delete','is_active'=>true,'created_at'=>$old->created_at??$now,'updated_at'=>$now]);$this->cloneMatrix($menuId,$now);}
    }

    private function cloneMatrix(string $menuId,$now):void
    {
        if(!Schema::hasTable('access_role_menu_permissions'))return;$sourceId=DB::table('access_menus')->where('code','finance-purchasing-posting')->value('id')?:DB::table('access_menus')->where('code','finance-cogs-posting')->value('id');if(!$sourceId)return;
        foreach(DB::table('access_role_menu_permissions')->where('menu_id',$sourceId)->get() as $r){$q=DB::table('access_role_menu_permissions')->where('access_role_id',$r->access_role_id)->where('menu_id',$menuId);$r->access_level_id===null?$q->whereNull('access_level_id'):$q->where('access_level_id',$r->access_level_id);if($q->exists())continue;$role=Schema::hasTable('access_roles')?DB::table('access_roles')->where('id',$r->access_role_id)->first(['code','spatie_role_name']):null;$admin=str_contains(strtoupper((string)($role->code??'')),'ADMIN')||str_contains(strtolower((string)($role->spatie_role_name??'')),'admin');DB::table('access_role_menu_permissions')->insert(['id'=>(string)Str::ulid(),'access_role_id'=>$r->access_role_id,'access_level_id'=>$r->access_level_id,'menu_id'=>$menuId,'can_view'=>(bool)$r->can_view,'can_create'=>$admin,'can_edit'=>$admin,'can_delete'=>$admin,'created_at'=>$now,'updated_at'=>$now]);}
    }
};
