<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            'finance_chart_of_accounts','finance_journal_entries','finance_journal_entry_lines',
            'finance_financial_statement_rules','finance_outlet_company_mappings',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Financial Statement Export membutuhkan {$table}. Apply baseline Finance Financial Statements terlebih dahulu.");
            }
        }
        $this->registerAccess();
    }

    public function down(): void
    {
        // Access entry is intentionally retained to avoid breaking saved Access Matrix snapshots.
    }

    private function registerAccess(): void
    {
        $permission='finance.financial_statement_export.view';
        Permission::findOrCreate($permission,'web');
        Role::query()->where('guard_name','web')->whereIn(DB::raw('LOWER(name)'),['admin','administrator','superadmin','super-admin'])
            ->get()->each(fn(Role $role)=>$role->givePermissionTo($permission));

        if(!Schema::hasTable('access_portals')||!Schema::hasTable('access_menus'))return;
        $portal=DB::table('access_portals')->where('code','finance')->first();
        if(!$portal)return;
        $existing=DB::table('access_menus')->where('code','finance-financial-statement-export')->first();
        $menuId=(string)($existing->id??Str::ulid());$now=now();
        DB::table('access_menus')->updateOrInsert(['code'=>'finance-financial-statement-export'],[
            'id'=>$menuId,'portal_id'=>$portal->id,'name'=>'Financial Statement Export','path'=>'/finance/financial-statement-export','sort_order'=>85,
            'permission_view'=>$permission,'permission_create'=>null,'permission_update'=>null,'permission_delete'=>null,
            'is_active'=>true,'created_at'=>$existing->created_at??$now,'updated_at'=>$now,
        ]);

        if(!Schema::hasTable('access_role_menu_permissions'))return;
        $sourceIds=DB::table('access_menus')->whereIn('code',['finance-balance-sheet','finance-profit-loss','finance-cash-flow'])->pluck('id')->map(fn($v)=>(string)$v)->all();
        if(!$sourceIds)return;

        $sourceRows=DB::table('access_role_menu_permissions')->whereIn('menu_id',$sourceIds)->where('can_view',true)->get();
        foreach($sourceRows as $source){
            $q=DB::table('access_role_menu_permissions')->where('access_role_id',$source->access_role_id)->where('menu_id',$menuId);
            $source->access_level_id===null?$q->whereNull('access_level_id'):$q->where('access_level_id',$source->access_level_id);
            if($q->exists())continue;
            DB::table('access_role_menu_permissions')->insert([
                'id'=>(string)Str::ulid(),'access_role_id'=>$source->access_role_id,'access_level_id'=>$source->access_level_id,'menu_id'=>$menuId,
                'can_view'=>true,'can_create'=>false,'can_edit'=>false,'can_delete'=>false,'created_at'=>$now,'updated_at'=>$now,
            ]);
        }
    }
};
