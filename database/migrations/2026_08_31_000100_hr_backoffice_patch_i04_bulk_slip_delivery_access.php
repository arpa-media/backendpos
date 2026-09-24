<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
        if (! $portal) return;

        $now = now();

        $payrollMenuId = $this->upsertMenu(
            (string) $portal->id,
            'hr-payroll-slip-bulk-i04',
            'Kirim Slip Gaji Bulk',
            '/human-resource/cutoff-gaji/kirim-slip-bulk',
            55,
            'hr.payroll.cutoff.view',
            'hr.payroll.cutoff.email',
            null,
            null,
            $now,
        );

        $bonusMenuId = $this->upsertMenu(
            (string) $portal->id,
            'hr-bonus-slip-bulk-i04',
            'Kirim Slip Bonus Bulk',
            '/human-resource/cutoff-bonus/kirim-slip-bulk',
            56,
            'hr.bonus.projection.view',
            'hr.bonus.projection.email',
            null,
            null,
            $now,
        );

        $this->cloneMatrixForBulk('hr-payroll-cutoff', $payrollMenuId, $now);
        $this->cloneMatrixForBulk('hr-bonus-cutoff', $bonusMenuId, $now);

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Non-destructive: Access Matrix rows are kept so role configuration is not
        // silently lost when deployment rollback is performed. Admin can disable the
        // menu through Access Matrix when required.
    }

    private function upsertMenu(
        string $portalId,
        string $code,
        string $name,
        string $path,
        int $sortOrder,
        ?string $view,
        ?string $create,
        ?string $update,
        ?string $delete,
        $now,
    ): string {
        $old = DB::table('access_menus')->where('code', $code)->first();
        $id = (string) ($old->id ?? Str::ulid());

        DB::table('access_menus')->updateOrInsert(['code' => $code], [
            'id' => $id,
            'portal_id' => $portalId,
            'name' => $name,
            'path' => $path,
            'sort_order' => $sortOrder,
            'permission_view' => $view,
            'permission_create' => $create,
            'permission_update' => $update,
            'permission_delete' => $delete,
            'is_active' => true,
            'created_at' => $old->created_at ?? $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function cloneMatrixForBulk(string $sourceCode, string $targetMenuId, $now): void
    {
        if (! Schema::hasTable('access_role_menu_permissions')) return;

        $sourceMenuId = DB::table('access_menus')->where('code', $sourceCode)->value('id');
        if (! $sourceMenuId) return;

        foreach (DB::table('access_role_menu_permissions')->where('menu_id', $sourceMenuId)->get() as $row) {
            $query = DB::table('access_role_menu_permissions')
                ->where('access_role_id', $row->access_role_id)
                ->where('menu_id', $targetMenuId);

            $row->access_level_id === null
                ? $query->whereNull('access_level_id')
                : $query->where('access_level_id', $row->access_level_id);

            if ($query->exists()) continue;

            // Bulk sending is operationally an Edit/Send action. Existing users who
            // can edit/create the source cutoff receive Create=ON here by default.
            $canSend = (bool) $row->can_edit || (bool) $row->can_create;

            DB::table('access_role_menu_permissions')->insert([
                'id' => (string) Str::ulid(),
                'access_role_id' => $row->access_role_id,
                'access_level_id' => $row->access_level_id,
                'menu_id' => $targetMenuId,
                'can_view' => (bool) $row->can_view,
                'can_create' => $canSend,
                'can_edit' => false,
                'can_delete' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
