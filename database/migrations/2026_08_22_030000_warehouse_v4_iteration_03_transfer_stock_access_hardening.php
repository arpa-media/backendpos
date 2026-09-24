<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const ENABLED_ROLES = ['ADMIN', 'WAREHOUSE'];
    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        foreach ([
            'warehouse.transfer.view','warehouse.transfer.create','warehouse.transfer.update','warehouse.transfer.delete',
            'warehouse.transfer.submit','warehouse.transfer.assign','warehouse.transfer.dispatch','warehouse.transfer.receive',
            'warehouse.logistics.receiving.view','warehouse.logistics.receiving.create','warehouse.logistics.receiving.update','warehouse.logistics.receiving.delete',
        ] as $permission) {
            if (Schema::hasTable('permissions')) Permission::findOrCreate($permission, $guard);
        }

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code', 'warehouse-operations')->first();
        if (! $portal) return;
        $now = now();
        foreach ([
            ['warehouse-v3-sales-transfer','Transfer Stock','/warehouse/transfers',430,'warehouse.transfer'],
            ['warehouse-v3-logistics-receiving','Receiving Stock','/warehouse/logistics/receiving-stock',630,'warehouse.logistics.receiving'],
        ] as [$code,$name,$path,$sort,$base]) {
            $row = DB::table('access_menus')->where('portal_id',$portal->id)->where('path',$path)->first();
            $id = (string) ($row->id ?? Str::ulid());
            $payload = [
                'portal_id'=>$portal->id,'name'=>$name,'path'=>$path,'sort_order'=>$sort,
                'permission_view'=>$base.'.view','permission_create'=>$base.'.create','permission_update'=>$base.'.update','permission_delete'=>$base.'.delete',
                'is_active'=>true,'updated_at'=>$now,
            ];
            if ($row) DB::table('access_menus')->where('id',$id)->update($payload);
            else DB::table('access_menus')->insert($payload + ['id'=>$id,'code'=>$code,'created_at'=>$now]);
            $this->seedMenuMatrix($id, $now);
        }
        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedMenuMatrix(string $menuId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        $roles = DB::table('access_roles')->select('id','code')->get();
        $levels = Schema::hasTable('access_levels') ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all() : [];
        foreach ($roles as $role) {
            $code = strtoupper(trim((string) $role->code));
            $enabled = in_array($code, self::ENABLED_ROLES, true);
            foreach (array_merge([null], $levels) as $levelId) {
                $query = DB::table('access_role_menu_permissions')->where('access_role_id',$role->id)->where('menu_id',$menuId);
                $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id',$levelId);
                if ($query->exists()) continue;
                DB::table('access_role_menu_permissions')->insert([
                    'id'=>(string) Str::ulid(),'access_role_id'=>$role->id,'access_level_id'=>$levelId,'menu_id'=>$menuId,
                    'can_view'=>$enabled,'can_create'=>$enabled,'can_edit'=>$enabled,'can_delete'=>$enabled && $code === 'ADMIN',
                    'created_at'=>$now,'updated_at'=>$now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: menu/access matrix may already be customized by administrators.
    }
};
