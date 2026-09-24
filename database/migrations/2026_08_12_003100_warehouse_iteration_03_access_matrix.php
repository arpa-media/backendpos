<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const MENUS = [
        ['warehouse-v3-purchase-requests','Purchase Request','/warehouse/purchasing/purchase-requests',200,'warehouse.procurement.request'],
        ['warehouse-v3-sales-stock-request','Stock Request','/warehouse/stock-requests/inbox',400,'warehouse.stock_request.inbox'],
        ['warehouse-v3-sales-order','Sales Order','/warehouse/sales/orders',420,'warehouse.sales.order'],
        ['warehouse-v3-production-orders','Production Order','/warehouse/production/orders',500,'warehouse.production'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code','warehouse-operations')->first();
        if (! $portal) return;
        $now = now(); $guard = config('auth.defaults.guard','web');

        foreach (self::MENUS as [$code,$name,$path,$sort,$base]) {
            $menu = DB::table('access_menus')->where('portal_id',$portal->id)->where('path',$path)->first();
            if (! $menu) {
                $id=(string)Str::ulid();
                DB::table('access_menus')->insert([
                    'id'=>$id,'portal_id'=>$portal->id,'code'=>$code,'name'=>$name,'path'=>$path,'sort_order'=>$sort,
                    'permission_view'=>$base.'.view','permission_create'=>$base.'.create','permission_update'=>$base.'.update','permission_delete'=>$base.'.delete',
                    'is_active'=>true,'created_at'=>$now,'updated_at'=>$now,
                ]);
                $this->seedMissingMatrix($id,$now);
            } else {
                DB::table('access_menus')->where('id',$menu->id)->update([
                    'name'=>$name,'permission_view'=>$base.'.view','permission_create'=>$base.'.create',
                    'permission_update'=>$base.'.update','permission_delete'=>$base.'.delete','is_active'=>true,'updated_at'=>$now,
                ]);
            }
            if (Schema::hasTable('permissions')) foreach (['view','create','update','delete'] as $action) Permission::findOrCreate($base.'.'.$action,$guard);
        }

        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedMissingMatrix(string $menuId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        $levels=Schema::hasTable('access_levels')?DB::table('access_levels')->pluck('id')->map(fn($id)=>(string)$id)->all():[];
        foreach (DB::table('access_roles')->get(['id','code']) as $role) {
            $enabled=in_array(strtoupper(trim((string)$role->code)),['ADMIN','WAREHOUSE'],true);
            foreach (array_merge([null],$levels) as $levelId) {
                $q=DB::table('access_role_menu_permissions')->where('access_role_id',$role->id)->where('menu_id',$menuId);
                $levelId===null?$q->whereNull('access_level_id'):$q->where('access_level_id',$levelId);
                if ($q->exists()) continue;
                DB::table('access_role_menu_permissions')->insert([
                    'id'=>(string)Str::ulid(),'access_role_id'=>$role->id,'access_level_id'=>$levelId,'menu_id'=>$menuId,
                    'can_view'=>$enabled,'can_create'=>$enabled,'can_edit'=>$enabled,'can_delete'=>$enabled && strtoupper((string)$role->code)==='ADMIN',
                    'created_at'=>$now,'updated_at'=>$now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Preserve Access Matrix customizations. Print subroute intentionally reuses Stock Request View.
    }
};
