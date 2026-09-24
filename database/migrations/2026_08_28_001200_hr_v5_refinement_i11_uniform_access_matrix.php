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
        if (! Schema::hasTable('permissions')) return;
        $guard=config('auth.defaults.guard','web');
        foreach(['hr.uniform.outbound.view','hr.uniform.outbound.create','hr.uniform.attribute_outbound.view','hr.uniform.attribute_outbound.create'] as $p) Permission::findOrCreate($p,$guard);
        if(!Schema::hasTable('access_portals')||!Schema::hasTable('access_menus'))return;
        $portal=DB::table('access_portals')->where('code','human-resource')->first(); if(!$portal)return; $now=now();
        $u=$this->menu((string)$portal->id,'hr-uniform-outbound','Uniform Keluar','/human-resource/manage-uniform/outbound',62,'hr.uniform.outbound.view','hr.uniform.outbound.create',$now);
        $a=$this->menu((string)$portal->id,'hr-uniform-attribute-outbound','Atribut Keluar','/human-resource/manage-uniform/attribute-outbound',63,'hr.uniform.attribute_outbound.view','hr.uniform.attribute_outbound.create',$now);
        if(Schema::hasTable('access_role_menu_permissions')){
            $source=DB::table('access_menus')->where('code','hr-uniform-inbound')->first();
            $rows=$source?DB::table('access_role_menu_permissions')->where('menu_id',$source->id)->get():collect();
            foreach($rows as $r){$this->grant($r,$u,$now);$this->grant($r,$a,$now);} }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
    public function down(): void { /* non-destructive */ }
    private function menu(string $portal,string $code,string $name,string $path,int $sort,string $view,string $create,$now):string
    { $e=DB::table('access_menus')->where('code',$code)->first();$id=(string)($e->id??Str::ulid());DB::table('access_menus')->updateOrInsert(['code'=>$code],['id'=>$id,'portal_id'=>$portal,'name'=>$name,'path'=>$path,'sort_order'=>$sort,'permission_view'=>$view,'permission_create'=>$create,'permission_update'=>null,'permission_delete'=>null,'is_active'=>true,'created_at'=>$e->created_at??$now,'updated_at'=>$now]);return$id; }
    private function grant(object $r,string $menu,$now):void
    { $q=DB::table('access_role_menu_permissions')->where('access_role_id',$r->access_role_id)->where('menu_id',$menu);$r->access_level_id===null?$q->whereNull('access_level_id'):$q->where('access_level_id',$r->access_level_id);if($q->exists())return;DB::table('access_role_menu_permissions')->insert(['id'=>(string)Str::ulid(),'access_role_id'=>$r->access_role_id,'access_level_id'=>$r->access_level_id,'menu_id'=>$menu,'can_view'=>(bool)$r->can_view,'can_create'=>(bool)$r->can_create,'can_edit'=>false,'can_delete'=>false,'created_at'=>$now,'updated_at'=>$now]); }
};
