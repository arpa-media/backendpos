<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('outlets') && ! Schema::hasColumn('outlets', 'hr_region')) {
            Schema::table('outlets', function (Blueprint $table): void {
                $table->string('hr_region', 32)->nullable()->index('outlets_hr_region_i04_idx');
            });
        }
        if (! Schema::hasTable('hr_v13_i04_pt_mapping_audits')) {
            Schema::create('hr_v13_i04_pt_mapping_audits', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('outlet_id', 26)->index();
                $table->string('outlet_code', 50)->nullable();
                $table->string('before_company', 16)->nullable();
                $table->string('after_company', 16)->nullable();
                $table->string('before_region', 32)->nullable();
                $table->string('after_region', 32)->nullable();
                $table->string('source', 50);
                $table->char('actor_user_id', 26)->nullable();
                $table->string('reason', 255)->nullable();
                $table->timestamps();
            });
        }
        // Data PT must use the same access matrix as HR Data Master and Data Outlet.
        if (! Schema::hasTable('access_menus') || ! Schema::hasTable('access_portals')) return;
        $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
        if (! $portal) return;
        $code = 'hr-master-company';
        $existing = DB::table('access_menus')->where('code', $code)->first();
        $values = [
            'portal_id' => $portal->id, 'code' => $code,
            'name' => 'Data PT', 'path' => '/human-resource/data-master/company',
            'sort_order' => 122, 'permission_view' => 'hr.outlet.view',
            'permission_create' => 'hr.outlet.update', 'permission_update' => 'hr.outlet.update',
            'permission_delete' => 'hr.outlet.update', 'is_active' => true,
        ];
        if (Schema::hasColumn('access_menus', 'updated_at')) $values['updated_at'] = now();
        if (! $existing) {
            $id = (string) Str::ulid();
            $values['id'] = $id;
            if (Schema::hasColumn('access_menus', 'created_at')) $values['created_at'] = now();
            DB::table('access_menus')->insert($values);
        } else {
            $id = (string) $existing->id;
            DB::table('access_menus')->where('id', $id)->update($values);
        }
        // Do not touch grants that were already configured for Data PT.
        if (! Schema::hasTable('access_role_menu_permissions')) return;
        $from = DB::table('access_menus')->where('code', 'hr-master-outlet')->first()
            ?: DB::table('access_menus')->where('code', 'hr-data-master')->first();
        if (! $from) return;
        $rows = DB::table('access_role_menu_permissions')->where('menu_id', $from->id)->get();
        foreach ($rows as $row) {
            $lookup = DB::table('access_role_menu_permissions')->where('menu_id', $id)->where('access_role_id', $row->access_role_id);
            if ($row->access_level_id ?? null) $lookup->where('access_level_id', $row->access_level_id);
            else $lookup->whereNull('access_level_id');
            if ($lookup->exists()) continue;
            $insert = [
                'id' => (string) Str::ulid(), 'menu_id' => $id,
                'access_role_id' => $row->access_role_id, 'access_level_id' => $row->access_level_id ?? null,
                'can_view' => (bool) $row->can_view, 'can_create' => false,
                'can_edit' => (bool) $row->can_edit, 'can_delete' => false,
            ];
            if (Schema::hasColumn('access_role_menu_permissions', 'created_at')) $insert['created_at'] = now();
            if (Schema::hasColumn('access_role_menu_permissions', 'updated_at')) $insert['updated_at'] = now();
            DB::table('access_role_menu_permissions')->insert($insert);
        }
    }

    public function down(): void
    {
        // Preserve master and mapping/audit data on rollback. No destructive down().
    }
};
