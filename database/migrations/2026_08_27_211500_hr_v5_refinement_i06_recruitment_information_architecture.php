<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        $this->seedPermissions();
        $this->seedAccessMatrix();
    }

    public function down(): void
    {
        // Non-destructive: menu/access history is intentionally retained.
    }

    private function seedPermissions(): void
    {
        if (! Schema::hasTable('permissions')) return;

        $guard = config('auth.defaults.guard', 'web');
        foreach ([
            'hr.recruitment.applicant_register.view',
            'hr.recruitment.applicant_register.manage',
        ] as $permission) {
            Permission::findOrCreate($permission, $guard);
        }
    }

    private function seedAccessMatrix(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;

        $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
        if (! $portal) return;

        $now = now();
        $source = DB::table('access_menus')->where('code', 'hr-recruitment')->first();

        // Existing Recruitment becomes the Dashboard Recruitment entry in the new group.
        if ($source) {
            DB::table('access_menus')->where('id', $source->id)->update([
                'name' => 'Dashboard Recruitment',
                'updated_at' => $now,
            ]);
        }

        $vacancyId = $this->upsertMenu(
            portalId: (string) $portal->id,
            code: 'hr-recruitment-vacancy',
            name: 'Vacancy',
            path: '/human-resource/recruitment/vacancy',
            sortOrder: 28,
            view: 'hr.recruitment.view',
            create: 'hr.recruitment.create',
            update: 'hr.recruitment.update',
            delete: 'hr.recruitment.delete',
            now: $now,
        );

        $applicantId = $this->upsertMenu(
            portalId: (string) $portal->id,
            code: 'hr-recruitment-applicant-register',
            name: 'Applicant Register',
            path: '/human-resource/recruitment/applicant-register',
            sortOrder: 29,
            view: 'hr.recruitment.applicant_register.view',
            create: null,
            update: 'hr.recruitment.applicant_register.manage',
            delete: null,
            now: $now,
        );

        // Keep Interview as its own existing access-matrix item, just position it after Applicant Register.
        DB::table('access_menus')->where('code', 'hr-recruitment-interview')->update([
            'sort_order' => 30,
            'updated_at' => $now,
        ]);

        if (! $source || ! Schema::hasTable('access_role_menu_permissions')) return;

        // New menu rows inherit current Recruitment visibility/write grants only at migration time.
        // Afterwards Vacancy and Applicant Register are independently configurable in Access Matrix.
        $sourceRows = DB::table('access_role_menu_permissions')->where('menu_id', $source->id)->get();
        foreach ($sourceRows as $grant) {
            $this->copyGrant($grant, $vacancyId, preserveCreate: true, preserveEdit: true, preserveDelete: true, now: $now);
            $this->copyGrant($grant, $applicantId, preserveCreate: false, preserveEdit: true, preserveDelete: false, now: $now);
        }
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
        $existing = DB::table('access_menus')->where('code', $code)->first();
        $id = (string) ($existing->id ?? Str::ulid());

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
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function copyGrant(object $source, string $targetMenuId, bool $preserveCreate, bool $preserveEdit, bool $preserveDelete, $now): void
    {
        $query = DB::table('access_role_menu_permissions')
            ->where('access_role_id', $source->access_role_id)
            ->where('menu_id', $targetMenuId);

        $source->access_level_id === null
            ? $query->whereNull('access_level_id')
            : $query->where('access_level_id', $source->access_level_id);

        if ($query->exists()) return;

        DB::table('access_role_menu_permissions')->insert([
            'id' => (string) Str::ulid(),
            'access_role_id' => $source->access_role_id,
            'access_level_id' => $source->access_level_id,
            'menu_id' => $targetMenuId,
            'can_view' => (bool) $source->can_view,
            'can_create' => $preserveCreate ? (bool) $source->can_create : false,
            'can_edit' => $preserveEdit ? (bool) $source->can_edit : false,
            'can_delete' => $preserveDelete ? (bool) $source->can_delete : false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
