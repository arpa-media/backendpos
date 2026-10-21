<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('access_portals') || !Schema::hasTable('access_menus')) return;
        $portal=DB::table('access_portals')->where('code','warehouse-operations')->first();
        if (!$portal) return;
        $code='warehouse-logistics-stock-in-v13';
        $path='/warehouse/logistics/stock-in';
        $old=DB::table('access_menus')->where('code',$code)->first();
        if (!$old) $old=DB::table('access_menus')->where('portal_id',$portal->id)->where('path',$path)->first();
        $id=$old->id??(string) Str::ulid();
        $payload=['portal_id'=>$portal->id,'name'=>'Stock In','path'=>$path,'sort_order'=>595,'permission_view'=>'warehouse.procurement.order.view','permission_create'=>'warehouse.procurement.order.update','permission_update'=>'warehouse.stock_in.approve','permission_delete'=>'warehouse.stock_in.approve','is_active'=>true,'updated_at'=>now()];
        if ($old) DB::table('access_menus')->where('id',$id)->update($payload);
        else DB::table('access_menus')->insert(['id'=>$id,'code'=>$code,'created_at'=>now()]+$payload);
        // Seed access matrix only for existing ADMIN and WAREHOUSE roles; backend permissions still apply.
        if (Schema::hasTable('access_roles') && Schema::hasTable('access_role_menu_permissions')) {
            $levels = Schema::hasTable('access_levels') ? DB::table('access_levels')->pluck('id')->all() : [];
            foreach (DB::table('access_roles')->select('id','code')->get() as $role) {
                $enabled = in_array(strtoupper((string)$role->code), ['ADMIN','WAREHOUSE'], true);
                foreach (array_merge([null], $levels) as $levelId) {
                    $query=DB::table('access_role_menu_permissions')->where('access_role_id',$role->id)->where('menu_id',$id);
                    $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id',$levelId);
                    if (!$query->exists()) DB::table('access_role_menu_permissions')->insert([
                        'id'=>(string)Str::ulid(),'access_role_id'=>$role->id,'access_level_id'=>$levelId,
                        'menu_id'=>$id,'can_view'=>$enabled,'can_create'=>false,'can_edit'=>$enabled,'can_delete'=>false,
                        'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
            }
        }
    }
    public function down(): void {
        if(Schema::hasTable('access_menus')) DB::table('access_menus')->where('code','warehouse-logistics-stock-in-v13')->delete();
    }
};
