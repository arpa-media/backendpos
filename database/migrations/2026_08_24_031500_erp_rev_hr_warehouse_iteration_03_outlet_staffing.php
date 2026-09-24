<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('HR_outlet_staffing_slots')) {
            Schema::create('HR_outlet_staffing_slots', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('outlet_id');
                $table->string('role_key', 150);
                $table->string('role_name', 150);
                $table->unsignedInteger('slot_count')->default(0);
                $table->ulid('created_by_user_id')->nullable();
                $table->ulid('updated_by_user_id')->nullable();
                $table->timestamps();
                $table->unique(['outlet_id', 'role_key'], 'hr_outlet_slot_uq');
                $table->index('outlet_id', 'hr_outlet_slot_outlet_idx');
            });
        }

        $guard = config('auth.defaults.guard', 'web');
        foreach ([
            'hr.outlet.view',
            'hr.outlet.create',
            'hr.outlet.update',
            'hr.outlet.delete',
            'hr.outlet.staffing.manage',
        ] as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        if (! Schema::hasTable('access_menus') || ! Schema::hasTable('access_portals')) {
            $this->forgetPermissionCache();
            return;
        }

        $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
        if (! $portal) {
            $this->forgetPermissionCache();
            return;
        }

        $child = DB::table('access_menus')->where('code', 'hr-master-outlet')->first();
        $payload = [
            'portal_id' => $portal->id,
            'code' => 'hr-master-outlet',
            'name' => 'Data Outlet',
            'path' => '/human-resource/data-master/outlet',
            'sort_order' => 121,
            'permission_view' => 'hr.outlet.view',
            'permission_create' => 'hr.outlet.create',
            'permission_update' => 'hr.outlet.update',
            'permission_delete' => 'hr.outlet.delete',
            'is_active' => true,
        ];
        if (Schema::hasColumn('access_menus', 'updated_at')) $payload['updated_at'] = now();

        if ($child) {
            DB::table('access_menus')->where('id', $child->id)->update($payload);
            $childId = (string) $child->id;
        } else {
            $childId = (string) Str::ulid();
            $insert = $payload + ['id' => $childId];
            if (Schema::hasColumn('access_menus', 'created_at')) $insert['created_at'] = now();
            DB::table('access_menus')->insert($insert);
        }

        $this->seedMatrixFromParent($childId);
        $this->forgetPermissionCache();
    }

    public function down(): void
    {
        // Data/configuration is intentionally preserved on rollback. Iteration
        // patches must not erase manpower targets or customized Access Matrix.
    }

    private function seedMatrixFromParent(string $childId): void
    {
        if (! Schema::hasTable('access_role_menu_permissions')) return;

        $parent = DB::table('access_menus')->where('code', 'hr-data-master')->first();
        $sourceRows = $parent
            ? DB::table('access_role_menu_permissions')->where('menu_id', $parent->id)->get()
            : collect();

        if ($sourceRows->isEmpty() && Schema::hasTable('access_role_portal_permissions')) {
            $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
            if ($portal) {
                $sourceRows = DB::table('access_role_portal_permissions')
                    ->where('portal_id', $portal->id)
                    ->where('can_view', true)
                    ->get()
                    ->map(fn ($row) => (object) [
                        'access_role_id' => $row->access_role_id,
                        'access_level_id' => $row->access_level_id ?? null,
                        'can_view' => true,
                        'can_create' => false,
                        'can_edit' => false,
                        'can_delete' => false,
                    ]);
            }
        }

        foreach ($sourceRows as $source) {
            if (! $source->access_role_id) continue;
            $query = DB::table('access_role_menu_permissions')
                ->where('access_role_id', $source->access_role_id)
                ->where('menu_id', $childId);
            if ($source->access_level_id ?? null) $query->where('access_level_id', $source->access_level_id);
            else $query->whereNull('access_level_id');
            if ($query->exists()) continue;

            $row = [
                'id' => (string) Str::ulid(),
                'access_role_id' => $source->access_role_id,
                'access_level_id' => $source->access_level_id ?? null,
                'menu_id' => $childId,
                'can_view' => (bool) ($source->can_view ?? false),
                'can_create' => (bool) ($source->can_create ?? false),
                'can_edit' => (bool) ($source->can_edit ?? false),
                'can_delete' => (bool) ($source->can_delete ?? false),
            ];
            if (Schema::hasColumn('access_role_menu_permissions', 'created_at')) $row['created_at'] = now();
            if (Schema::hasColumn('access_role_menu_permissions', 'updated_at')) $row['updated_at'] = now();
            DB::table('access_role_menu_permissions')->insert($row);
        }
    }

    private function forgetPermissionCache(): void
    {
        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
