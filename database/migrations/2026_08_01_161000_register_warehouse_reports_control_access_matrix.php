<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('access_menus') || !Schema::hasTable('access_portals')) return;
        $portal = DB::table('access_portals')->where('code','warehouse-operations')->first();
        if (!$portal) return;
        $now = now();
        $menus = [
            ['warehouse-report-executive','Executive Warehouse','/warehouse/reports/executive',610,'warehouse.report.executive'],
            ['warehouse-report-operations','Sales, Purchase & Inventory','/warehouse/reports/operations',620,'warehouse.report.operations'],
            ['warehouse-report-finance','AR, AP & Margin','/warehouse/reports/finance',630,'warehouse.report.finance'],
            ['warehouse-report-audit','Audit Trail','/warehouse/reports/audit',640,'warehouse.report.audit'],
            ['warehouse-report-reconciliation','Reconciliation Control','/warehouse/reports/reconciliation',650,'warehouse.control.reconciliation'],
        ];
        foreach($menus as [$code,$name,$path,$sort,$permission]){
            $existing=DB::table('access_menus')->where('code',$code)->first();
            DB::table('access_menus')->updateOrInsert(['code'=>$code],[
                'id'=>$existing->id??(string)Str::ulid(),'portal_id'=>$portal->id,'name'=>$name,'path'=>$path,'sort_order'=>$sort,
                'permission_view'=>$permission.'.view','permission_create'=>$permission.'.run','permission_update'=>$permission.'.resolve','permission_delete'=>null,
                'is_active'=>true,'created_at'=>$existing->created_at??$now,'updated_at'=>$now,
            ]);
        }
        foreach(['warehouse.report.executive.view','warehouse.report.operations.view','warehouse.report.finance.view','warehouse.report.audit.view','warehouse.report.export','warehouse.control.reconciliation.view','warehouse.control.reconciliation.run','warehouse.control.reconciliation.resolve'] as $name)
            Permission::findOrCreate($name,config('auth.defaults.guard','web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
    public function down(): void {}
};
