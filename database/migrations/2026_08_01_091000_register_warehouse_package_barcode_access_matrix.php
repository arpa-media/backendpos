<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
return new class extends Migration {
 public function up(): void {
  if(!Schema::hasTable('access_portals')||!Schema::hasTable('access_menus'))return;
  $portal=DB::table('access_portals')->where('code','warehouse-operations')->first(); if(!$portal)return;
  $now=now(); $existing=DB::table('access_menus')->where('code','warehouse-inventory-package-barcodes')->first(); $menuId=(string)($existing->id??Str::ulid());
  DB::table('access_menus')->updateOrInsert(['code'=>'warehouse-inventory-package-barcodes'],['id'=>$menuId,'portal_id'=>$portal->id,'name'=>'Barcode & Kemasan','path'=>'/warehouse/inventory/package-barcodes','sort_order'=>225,'permission_view'=>'warehouse.inventory.package.view','permission_create'=>'warehouse.inventory.package.create','permission_update'=>'warehouse.inventory.package.update','permission_delete'=>'warehouse.inventory.package.delete','is_active'=>true,'created_at'=>$existing->created_at??$now,'updated_at'=>$now]);
  $guard=(string)config('auth.defaults.guard','web'); foreach(['view','create','update','delete'] as $action) Permission::findOrCreate("warehouse.inventory.package.{$action}",$guard);
  if(Schema::hasTable('access_roles')&&Schema::hasTable('access_role_menu_permissions')){
   $roles=DB::table('access_roles')->select('id','code')->get(); $levels=Schema::hasTable('access_levels')?DB::table('access_levels')->pluck('id')->map(fn($id)=>(string)$id)->all():[];
   foreach($roles as $role){$enabled=in_array(strtoupper(trim((string)$role->code)),['ADMIN','WAREHOUSE'],true); foreach(array_merge([null],$levels) as $levelId){$q=DB::table('access_role_menu_permissions')->where('access_role_id',$role->id)->where('menu_id',$menuId); $levelId===null?$q->whereNull('access_level_id'):$q->where('access_level_id',$levelId); if($q->exists())continue; DB::table('access_role_menu_permissions')->insert(['id'=>(string)Str::ulid(),'access_role_id'=>$role->id,'access_level_id'=>$levelId,'menu_id'=>$menuId,'can_view'=>$enabled,'can_create'=>$enabled,'can_edit'=>$enabled,'can_delete'=>$enabled,'created_at'=>$now,'updated_at'=>$now]);}}
  }
  if(app()->bound(PermissionRegistrar::class))app(PermissionRegistrar::class)->forgetCachedPermissions();
 }
 public function down(): void {}
};
