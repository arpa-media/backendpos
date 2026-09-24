<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration {
    private array $menu=[
        'code'=>'finance-general-ledger','name'=>'General Ledger','path'=>'/finance/general-ledger','sort_order'=>50,
        'permission_view'=>'finance.general_ledger.view',
    ];

    public function up(): void
    {
        foreach(['finance_journal_entries','finance_journal_entry_lines','finance_chart_of_accounts','finance_outlet_company_mappings'] as $table){
            if(!Schema::hasTable($table))throw new RuntimeException("Finance Iterasi 07 membutuhkan {$table}. Apply Iterasi 03 terlebih dahulu.");
        }
        $this->addIndex('finance_journal_entries','fin_gl_status_jdate_scope_idx',['status','journal_date','company_code','marking']);
        $this->addIndex('finance_journal_entries','fin_gl_status_bdate_scope_idx',['status','business_date','company_code','marking']);
        $this->addIndex('finance_journal_entry_lines','fin_gl_account_entry_idx',['account_id','journal_entry_id']);
        $this->registerAccess();
    }

    public function down(): void
    {
        if(Schema::hasTable('access_menus')){
            $id=DB::table('access_menus')->where('code',$this->menu['code'])->value('id');
            if($id&&Schema::hasTable('access_role_menu_permissions'))DB::table('access_role_menu_permissions')->where('menu_id',$id)->delete();
            DB::table('access_menus')->where('code',$this->menu['code'])->delete();
        }
        $this->dropIndex('finance_journal_entries','fin_gl_status_jdate_scope_idx');
        $this->dropIndex('finance_journal_entries','fin_gl_status_bdate_scope_idx');
        $this->dropIndex('finance_journal_entry_lines','fin_gl_account_entry_idx');
    }

    private function registerAccess(): void
    {
        Permission::findOrCreate('finance.general_ledger.view','web');
        Role::query()->where('guard_name','web')->whereIn(DB::raw('LOWER(name)'),['admin','administrator','superadmin','super-admin'])
            ->get()->each(fn(Role $r)=>$r->givePermissionTo('finance.general_ledger.view'));
        if(!Schema::hasTable('access_portals')||!Schema::hasTable('access_menus'))return;
        $portal=DB::table('access_portals')->where('code','finance')->first();if(!$portal)return;
        $existing=DB::table('access_menus')->where('code',$this->menu['code'])->first();
        $menuId=(string)($existing->id??Str::ulid());$now=now();
        DB::table('access_menus')->updateOrInsert(['code'=>$this->menu['code']],[
            'id'=>$menuId,'portal_id'=>$portal->id,'name'=>$this->menu['name'],'path'=>$this->menu['path'],'sort_order'=>$this->menu['sort_order'],
            'permission_view'=>$this->menu['permission_view'],'permission_create'=>null,'permission_update'=>null,'permission_delete'=>null,
            'is_active'=>true,'created_at'=>$existing->created_at??$now,'updated_at'=>$now,
        ]);
        if(!Schema::hasTable('access_role_menu_permissions'))return;
        $sourceMenuId=DB::table('access_menus')->where('code','finance-dashboard')->value('id');
        $sourceRows=$sourceMenuId?DB::table('access_role_menu_permissions')->where('menu_id',$sourceMenuId)->get():collect();
        foreach($sourceRows as $source){
            $q=DB::table('access_role_menu_permissions')->where('access_role_id',$source->access_role_id)->where('menu_id',$menuId);
            $source->access_level_id===null?$q->whereNull('access_level_id'):$q->where('access_level_id',$source->access_level_id);
            if($q->exists())continue;
            DB::table('access_role_menu_permissions')->insert([
                'id'=>(string)Str::ulid(),'access_role_id'=>$source->access_role_id,'access_level_id'=>$source->access_level_id,'menu_id'=>$menuId,
                'can_view'=>(bool)$source->can_view,'can_create'=>false,'can_edit'=>false,'can_delete'=>false,'created_at'=>$now,'updated_at'=>$now,
            ]);
        }
    }

    private function addIndex(string $table,string $name,array $columns): void
    {
        if(DB::getDriverName()!=='mysql'||$this->indexExists($table,$name))return;
        $quoted=implode(',',array_map(fn($c)=>'`'.str_replace('`','',$c).'`',$columns));
        DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$quoted})");
    }

    private function dropIndex(string $table,string $name): void
    {
        if(Schema::hasTable($table)&&$this->indexExists($table,$name))DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
    }

    private function indexExists(string $table,string $name): bool
    {
        if(DB::getDriverName()!=='mysql')return false;
        return DB::table('information_schema.statistics')->whereRaw('table_schema = DATABASE()')->where('table_name',$table)->where('index_name',$name)->exists();
    }
};
