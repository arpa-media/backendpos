<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void {
        foreach (['wh_productions','wh_v7_production_stock_balances','wh_v3_production_results'] as $table) {
            if (!Schema::hasTable($table)) throw new RuntimeException('V13 I21: Missing '. $table);
        }
        if (!Schema::hasTable('wh_v13_i21_production_cost_states')) {
            Schema::create('wh_v13_i21_production_cost_states',function(Blueprint $t){
                $t->ulid('production_id')->primary();
                $t->ulid('warehouse_id')->index();
                $t->string('status',32)->default('pending_opname');
                $t->decimal('posted_output_value',22,2)->default(0);
                $t->decimal('expected_output_value',22,2)->default(0);
                $t->decimal('delta_value',22,2)->default(0);
                $t->unsignedInteger('approved_result_count')->default(0);
                $t->timestamp('opname_finalized_at')->nullable();
                $t->timestamps();
                // No FK in V13 I21: reset/legacy schema drift must not interrupt existing data.
            });
        }
        if (Schema::hasTable('access_portals') && Schema::hasTable('access_menus')) {
            $portal=DB::table('access_portals')->where('code','warehouse-operations')->first();
            if ($portal) {
                $path='/warehouse/production/stock'; $code='warehouse-production-stock-v13';
                $menu=DB::table('access_menus')->where('code',$code)->first();
                $data=[
                    'portal_id'=>$portal->id,'name'=>'Production Stock','path'=>$path,
                    'sort_order'=>548,'permission_view'=>'warehouse.production.view',
                    'permission_create'=>'warehouse.production.create',
                    'permission_update'=>'warehouse.production.update','permission_delete'=>'warehouse.production.update',
                    'is_active'=>true,'updated_at'=>now(),
                ];
                if ($menu) DB::table('access_menus')->where('id',$menu->id)->update($data);
                else {
                    $id=(string)Str::ulid();
                    DB::table('access_menus')->insert(['id'=>$id,'code'=>$code,'created_at'=>now()]+$data);
                    // Permissions for new menu derive from existing Production Order grants.
                    if (Schema::hasTable('access_role_menu_permissions')) {
                        $source=DB::table('access_menus')->where('portal_id',$portal->id)->where('path','/warehouse/production/orders')->first();
                        if ($source) foreach(DB::table('access_role_menu_permissions')->where('menu_id',$source->id)->get() as $grant) {
                            $insert=(array)$grant;
                            $insert['id']=(string)Str::ulid();$insert['menu_id']=$id;
                            if (array_key_exists('created_at',$insert)) $insert['created_at']=now();
                            if (array_key_exists('updated_at',$insert)) $insert['updated_at']=now();
                            DB::table('access_role_menu_permissions')->insert($insert);
                        }
                    }
                }
            }
        }
    }
    public function down(): void {
        // Non-destructive rollback. Historical cost states remain as audit evidence.
        // Menu access may be removed manually only after reviewing grants.
    }
};
