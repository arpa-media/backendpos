<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->repairPunishmentSoftDeletes();
        $this->repairHumanResourceNavigationCatalog();
    }

    private function repairPunishmentSoftDeletes(): void
    {
        foreach (['HR_violations', 'HR_warning_letters'] as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'deleted_at')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    private function repairHumanResourceNavigationCatalog(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
        if (! $portal) {
            return;
        }

        $now = now();
        $userManagement = DB::table('access_menus')->where('code', 'hr-user-management')->first();

        if ($userManagement) {
            DB::table('access_menus')->where('id', $userManagement->id)->update([
                'portal_id' => $portal->id,
                'name' => 'User Management',
                'path' => '/user-management',
                'sort_order' => 20,
                'permission_view' => 'user_management.view',
                'permission_update' => 'user_management.edit',
                'is_active' => true,
                'updated_at' => $now,
            ]);
        } else {
            $userManagementId = (string) Str::ulid();
            DB::table('access_menus')->insert([
                'id' => $userManagementId,
                'portal_id' => $portal->id,
                'code' => 'hr-user-management',
                'name' => 'User Management',
                'path' => '/user-management',
                'sort_order' => 20,
                'permission_view' => 'user_management.view',
                'permission_create' => null,
                'permission_update' => 'user_management.edit',
                'permission_delete' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->copyLegacyUsersMatrix($userManagementId, $now);
        }

        // Requirement Iterasi 09 hanya menghapus menu dummy "Users".
        DB::table('access_menus')
            ->where('portal_id', $portal->id)
            ->where('code', 'hr-users')
            ->update(['is_active' => false, 'updated_at' => $now]);

        foreach ([
            'hr-recruitment-interview' => ['name' => 'Interview', 'path' => '/human-resource/recruitment/interview', 'sort_order' => 28],
            'hr-bonus-cutoff' => ['name' => 'Cutoff Bonus', 'path' => '/human-resource/cutoff-bonus', 'sort_order' => 54],
        ] as $code => $payload) {
            DB::table('access_menus')->where('portal_id', $portal->id)->where('code', $code)->update(array_merge($payload, [
                'is_active' => true,
                'updated_at' => $now,
            ]));
        }
    }

    private function copyLegacyUsersMatrix(string $targetMenuId, $now): void
    {
        if (! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $legacyMenuId = DB::table('access_menus')->where('code', 'hr-users')->value('id');
        if (! $legacyMenuId) {
            return;
        }

        foreach (DB::table('access_role_menu_permissions')->where('menu_id', $legacyMenuId)->get() as $row) {
            $query = DB::table('access_role_menu_permissions')
                ->where('access_role_id', $row->access_role_id)
                ->where('menu_id', $targetMenuId);
            $row->access_level_id === null
                ? $query->whereNull('access_level_id')
                : $query->where('access_level_id', $row->access_level_id);

            if ($query->exists()) {
                continue;
            }

            DB::table('access_role_menu_permissions')->insert([
                'id' => (string) Str::ulid(),
                'access_role_id' => $row->access_role_id,
                'access_level_id' => $row->access_level_id,
                'menu_id' => $targetMenuId,
                'can_view' => (bool) $row->can_view,
                'can_create' => (bool) $row->can_create,
                'can_edit' => (bool) $row->can_edit,
                'can_delete' => (bool) $row->can_delete,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive hotfix: soft-delete columns and repaired catalog rows are retained.
    }
};
