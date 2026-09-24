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
        $portal = DB::table('access_portals')->where('code', 'warehouse-operations')->first();
        if (!$portal) return;
        $now = now();
        $menus = [
            ['warehouse-hardening-go-live','Go-Live & Health Control','/warehouse/control/go-live',660,'warehouse.control.go_live'],
            ['warehouse-hardening-reversal','Reversal Control','/warehouse/control/reversals',670,'warehouse.control.reversal'],
        ];
        foreach ($menus as [$code,$name,$path,$sort,$permission]) {
            $existing = DB::table('access_menus')->where('code', $code)->first();
            DB::table('access_menus')->updateOrInsert(['code' => $code], [
                'id' => $existing->id ?? (string) Str::ulid(), 'portal_id' => $portal->id,
                'name' => $name, 'path' => $path, 'sort_order' => $sort,
                'permission_view' => $permission.'.view', 'permission_create' => $permission.'.run',
                'permission_update' => $permission.'.approve', 'permission_delete' => null,
                'is_active' => true, 'created_at' => $existing->created_at ?? $now, 'updated_at' => $now,
            ]);
        }
        foreach ([
            'warehouse.control.go_live.view','warehouse.control.go_live.run','warehouse.control.go_live.waive',
            'warehouse.control.reversal.view','warehouse.control.reversal.create','warehouse.control.reversal.approve',
            'warehouse.control.health.view','warehouse.control.backfill.run'
        ] as $name) Permission::findOrCreate($name, config('auth.defaults.guard', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
    public function down(): void {}
};
