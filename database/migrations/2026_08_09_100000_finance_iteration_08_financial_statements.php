<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration {
    private array $menus=[
        ['code'=>'finance-balance-sheet','name'=>'Balance Sheet','path'=>'/finance/balance-sheet','sort_order'=>60,'view'=>'finance.balance_sheet.view','update'=>null],
        ['code'=>'finance-profit-loss','name'=>'Profit Loss','path'=>'/finance/profit-loss','sort_order'=>70,'view'=>'finance.profit_loss.view','update'=>null],
        ['code'=>'finance-cash-flow','name'=>'Cash Flow','path'=>'/finance/cash-flow','sort_order'=>80,'view'=>'finance.cash_flow.view','update'=>'finance.cash_flow.manage_mapping'],
    ];

    public function up():void
    {
        foreach(['finance_chart_of_accounts','finance_journal_entries','finance_journal_entry_lines','finance_outlet_company_mappings'] as $table){
            if(!Schema::hasTable($table))throw new RuntimeException("Finance Iterasi 08 membutuhkan {$table}. Apply Iterasi 03 dan 07 terlebih dahulu.");
        }
        $this->createRules();
        $this->seedRules();
        $this->registerAccess();
    }

    public function down():void
    {
        if(Schema::hasTable('access_menus')){
            foreach($this->menus as $menu){
                $id=DB::table('access_menus')->where('code',$menu['code'])->value('id');
                if($id&&Schema::hasTable('access_role_menu_permissions'))DB::table('access_role_menu_permissions')->where('menu_id',$id)->delete();
                DB::table('access_menus')->where('code',$menu['code'])->delete();
            }
        }
        Schema::dropIfExists('finance_financial_statement_rules');
    }

    private function createRules():void
    {
        if(Schema::hasTable('finance_financial_statement_rules'))return;
        Schema::create('finance_financial_statement_rules',function(Blueprint $table):void{
            $table->ulid('id')->primary();
            $table->char('account_id',26)->unique();
            $table->string('balance_sheet_section',24)->nullable()->index();
            $table->string('profit_loss_section',24)->nullable()->index();
            $table->string('cash_flow_section',24)->default('OPERATING')->index();
            $table->boolean('is_cash_account')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->text('notes')->nullable();
            $table->char('updated_by_user_id',26)->nullable()->index();
            $table->timestamps();
            $table->foreign('account_id','fin_stmt_rule_account_fk')->references('id')->on('finance_chart_of_accounts')->cascadeOnDelete();
        });
    }

    private function seedRules():void
    {
        $rows=DB::table('finance_chart_of_accounts')->orderBy('code')->get(['id','code','name','account_type']);
        $now=now();$sort=0;
        foreach($rows as $coa){
            $sort+=10;$type=strtoupper((string)$coa->account_type);$code=(string)$coa->code;$name=(string)$coa->name;
            $bs=in_array($type,['ASSET','LIABILITY','EQUITY'],true)?$type:null;
            $pl=in_array($type,['REVENUE','COGS','EXPENSE','OTHER_INCOME','OTHER_EXPENSE','TAX'],true)?$type:null;
            $isCash=$type==='ASSET'&&$this->isCashName($name);
            $cf=$this->defaultCashFlowSection($type,$code,$isCash);
            DB::table('finance_financial_statement_rules')->updateOrInsert(['account_id'=>$coa->id],[
                'id'=>(string)(DB::table('finance_financial_statement_rules')->where('account_id',$coa->id)->value('id')?:Str::ulid()),
                'balance_sheet_section'=>$bs,'profit_loss_section'=>$pl,'cash_flow_section'=>$cf,'is_cash_account'=>$isCash,
                'sort_order'=>$sort,'notes'=>'Default Iterasi 08 dari klasifikasi COA. Cash Flow dapat disesuaikan dari menu Cash Flow → Mapping.',
                'created_at'=>DB::table('finance_financial_statement_rules')->where('account_id',$coa->id)->value('created_at')?:$now,'updated_at'=>$now,
            ]);
        }
    }

    private function isCashName(string $name):bool
    {
        $n=trim($name);
        if(preg_match('/^Kas(?:\s|-|$)/iu',$n))return true;
        if(str_contains(mb_strtoupper($n),'BANK'))return true;
        return in_array(mb_strtoupper($n),['GOPAY','OVO','DANA','LINK AJA','CASHLEZ'],true);
    }

    private function defaultCashFlowSection(string $type,string $code,bool $isCash):string
    {
        if($isCash)return 'NON_CASH';
        if($type==='ASSET'&&(str_starts_with($code,'1-107')||str_starts_with($code,'1-108')))return 'INVESTING';
        if($type==='LIABILITY'&&str_starts_with($code,'2-204'))return 'FINANCING';
        if($type==='EQUITY'){
            return in_array($code,['3-30000','3-30001','3-30200'],true)?'FINANCING':'NON_CASH';
        }
        return 'OPERATING';
    }

    private function registerAccess():void
    {
        $permissions=['finance.balance_sheet.view','finance.profit_loss.view','finance.cash_flow.view','finance.cash_flow.manage_mapping'];
        foreach($permissions as $p)Permission::findOrCreate($p,'web');
        Role::query()->where('guard_name','web')->whereIn(DB::raw('LOWER(name)'),['admin','administrator','superadmin','super-admin'])
            ->get()->each(fn(Role $r)=>$r->givePermissionTo($permissions));
        if(!Schema::hasTable('access_portals')||!Schema::hasTable('access_menus'))return;
        $portal=DB::table('access_portals')->where('code','finance')->first();if(!$portal)return;
        $sourceMenuId=DB::table('access_menus')->where('code','finance-general-ledger')->value('id')?:DB::table('access_menus')->where('code','finance-dashboard')->value('id');
        $sourceRows=$sourceMenuId&&Schema::hasTable('access_role_menu_permissions')?DB::table('access_role_menu_permissions')->where('menu_id',$sourceMenuId)->get():collect();
        $now=now();
        foreach($this->menus as $menu){
            $existing=DB::table('access_menus')->where('code',$menu['code'])->first();$menuId=(string)($existing->id??Str::ulid());
            DB::table('access_menus')->updateOrInsert(['code'=>$menu['code']],[
                'id'=>$menuId,'portal_id'=>$portal->id,'name'=>$menu['name'],'path'=>$menu['path'],'sort_order'=>$menu['sort_order'],
                'permission_view'=>$menu['view'],'permission_create'=>null,'permission_update'=>$menu['update'],'permission_delete'=>null,
                'is_active'=>true,'created_at'=>$existing->created_at??$now,'updated_at'=>$now,
            ]);
            if(!Schema::hasTable('access_role_menu_permissions'))continue;
            foreach($sourceRows as $source){
                $q=DB::table('access_role_menu_permissions')->where('access_role_id',$source->access_role_id)->where('menu_id',$menuId);
                $source->access_level_id===null?$q->whereNull('access_level_id'):$q->where('access_level_id',$source->access_level_id);
                if($q->exists())continue;
                $role=Schema::hasTable('access_roles')?DB::table('access_roles')->where('id',$source->access_role_id)->first(['code','spatie_role_name']):null;
                $isAdmin=str_contains(strtoupper((string)($role->code??'')),'ADMIN')||str_contains(strtolower((string)($role->spatie_role_name??'')),'admin');
                DB::table('access_role_menu_permissions')->insert([
                    'id'=>(string)Str::ulid(),'access_role_id'=>$source->access_role_id,'access_level_id'=>$source->access_level_id,'menu_id'=>$menuId,
                    'can_view'=>(bool)$source->can_view,'can_create'=>false,'can_edit'=>$menu['update']?$isAdmin:false,'can_delete'=>false,'created_at'=>$now,'updated_at'=>$now,
                ]);
            }
        }
    }
};
