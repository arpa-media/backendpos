<?php

use App\Models\AccessLevel;
use App\Models\AccessRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('access_portals') || !Schema::hasTable('access_menus')) {
            return;
        }

        $now = now();
        $guard = config('auth.defaults.guard', 'web');

        if (class_exists(Permission::class)) {
            Permission::findOrCreate('owner_overview.sale_detail.view', $guard);
        }

        $upsertWithUlid = static function (string $table, array $match, array $values): ?string {
            if (!Schema::hasTable($table)) {
                return null;
            }

            $query = DB::table($table);
            foreach ($match as $column => $value) {
                if ($value === null) {
                    $query->whereNull($column);
                } else {
                    $query->where($column, $value);
                }
            }

            $existing = $query->first();
            if ($existing) {
                $updateValues = $values;
                unset($updateValues['created_at']);
                DB::table($table)->where('id', $existing->id)->update($updateValues);
                return (string) $existing->id;
            }

            $id = (string) Str::ulid();
            DB::table($table)->insert(array_merge(['id' => $id], $match, $values));
            return $id;
        };

        $portalId = DB::table('access_portals')->where('code', 'owner-overview')->value('id');
        if (! $portalId) {
            return;
        }

        $menuId = $upsertWithUlid('access_menus', ['code' => 'owner-overview-detail-sales'], [
            'portal_id' => $portalId,
            'name' => 'Detail Sales',
            'path' => '/owner-overview/detail-sales',
            'sort_order' => 11,
            'permission_view' => 'owner_overview.sale_detail.view',
            'permission_create' => null,
            'permission_update' => null,
            'permission_delete' => null,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (! $menuId || !Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $defaultLevelId = AccessLevel::query()->where('code', 'DEFAULT')->value('id')
            ?: AccessLevel::query()->where('code', 'HQ')->value('id');
        $adminRoleId = AccessRole::query()->where('code', 'ADMIN')->value('id');

        if ($adminRoleId) {
            $upsertWithUlid('access_role_menu_permissions', [
                'access_role_id' => $adminRoleId,
                'access_level_id' => $defaultLevelId,
                'menu_id' => $menuId,
            ], [
                'can_view' => 1,
                'can_create' => 0,
                'can_edit' => 0,
                'can_delete' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // no-op hotfix
    }
};
