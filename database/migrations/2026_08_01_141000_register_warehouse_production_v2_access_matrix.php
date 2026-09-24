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
        if (! Schema::hasTable('access_menus') || ! Schema::hasTable('access_portals')) return;
        $portal = DB::table('access_portals')->where('code', 'warehouse-operations')->first();
        if (! $portal) return;
        $now = now();
        $menus = [
            ['warehouse-production-boms','BOM Produksi','/warehouse/production/boms',520,'warehouse.production.bom'],
            ['warehouse-production-costing','Costing & WIP','/warehouse/production/costing',530,'warehouse.production.cost'],
            ['warehouse-production-control','Yield & Waste Control','/warehouse/production/control',540,'warehouse.production.control'],
        ];
        foreach ($menus as [$code,$name,$path,$sort,$permission]) {
            $existing = DB::table('access_menus')->where('code',$code)->first();
            DB::table('access_menus')->updateOrInsert(['code'=>$code],[
                'id'=>$existing->id ?? (string) Str::ulid(), 'portal_id'=>$portal->id,
                'name'=>$name, 'path'=>$path, 'sort_order'=>$sort,
                'permission_view'=>$permission.'.view',
                'permission_create'=>$permission.'.create',
                'permission_update'=>$permission.'.update',
                'permission_delete'=>$permission.'.delete',
                'is_active'=>true, 'created_at'=>$existing->created_at ?? $now, 'updated_at'=>$now,
            ]);
        }
        foreach ([
            'warehouse.production.bom.view','warehouse.production.bom.create','warehouse.production.bom.update','warehouse.production.bom.delete',
            'warehouse.production.cost.view','warehouse.production.cost.create','warehouse.production.cost.update','warehouse.production.cost.delete',
            'warehouse.production.waste.create','warehouse.production.control.view',
        ] as $name) Permission::findOrCreate($name, config('auth.defaults.guard','web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
    public function down(): void {}
};
