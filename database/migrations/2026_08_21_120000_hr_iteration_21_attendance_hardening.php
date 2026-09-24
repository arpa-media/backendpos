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
    private const OPERATIONAL_EXCLUSIONS = ['STAKEHOLDER', 'OBSERVER'];

    public function up(): void
    {
        $this->extendAttendanceSchema();
        $this->repairAccessMatrix();
        $this->hideLegacyUsersMenu();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function extendAttendanceSchema(): void
    {
        if (! Schema::hasTable('HR_attendances')) return;

        Schema::table('HR_attendances', function (Blueprint $table): void {
            if (! Schema::hasColumn('HR_attendances', 'shift_schedule_id')) {
                $table->ulid('shift_schedule_id')->nullable()->after('business_date');
            }
            if (! Schema::hasColumn('HR_attendances', 'late_minutes')) {
                $table->unsignedInteger('late_minutes')->nullable()->after('calculation_eligible');
            }
            if (! Schema::hasColumn('HR_attendances', 'work_minutes')) {
                $table->unsignedInteger('work_minutes')->nullable()->after('late_minutes');
            }
            if (! Schema::hasColumn('HR_attendances', 'calculation_status')) {
                $table->string('calculation_status', 40)->nullable()->after('work_minutes');
            }
            if (! Schema::hasColumn('HR_attendances', 'calculation_version')) {
                $table->string('calculation_version', 40)->nullable()->after('calculation_status');
            }
            if (! Schema::hasColumn('HR_attendances', 'calculated_at')) {
                $table->timestamp('calculated_at')->nullable()->after('calculation_version');
            }
            if (! Schema::hasColumn('HR_attendances', 'checkout_exception_flags')) {
                $table->json('checkout_exception_flags')->nullable()->after('exception_flags');
            }
        });

        // Add indexes separately so partially applied environments remain safe.
        if (! $this->indexExists('HR_attendances', 'hr_att_calc_date_status_idx')) {
            Schema::table('HR_attendances', function (Blueprint $table): void {
                $table->index(['business_date', 'calculation_status'], 'hr_att_calc_date_status_idx');
            });
        }
        if (! $this->indexExists('HR_attendances', 'hr_att_sched_calc_idx')) {
            Schema::table('HR_attendances', function (Blueprint $table): void {
                $table->index(['shift_schedule_id', 'business_date'], 'hr_att_sched_calc_idx');
            });
        }
    }

    private function repairAccessMatrix(): void
    {
        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            Permission::findOrCreate('hr.attendance.data.export', $guard);
            Permission::findOrCreate('hr.attendance.recalculate', $guard);
        }
        if (! Schema::hasTable('access_menus')) return;

        $now = now();
        $dataMenu = DB::table('access_menus')->where('code', 'hr-data-attendance')->first();
        if ($dataMenu) {
            DB::table('access_menus')->where('id', $dataMenu->id)->update([
                'permission_create' => 'hr.attendance.data.export',
                'updated_at' => $now,
            ]);
            $this->mirrorViewCapabilityToAction((string) $dataMenu->id, 'can_create', $now);
        }

        $dailyMenu = DB::table('access_menus')->where('code', 'hr-attendance-daily-report')->first();
        if ($dailyMenu) {
            DB::table('access_menus')->where('id', $dailyMenu->id)->update([
                'permission_update' => 'hr.attendance.recalculate',
                'updated_at' => $now,
            ]);
            $this->mirrorViewCapabilityToAction((string) $dailyMenu->id, 'can_edit', $now);
        }

        // Iterasi 03/07 originally only inserted missing self-service rows. Existing false rows
        // from older Access Matrix data therefore kept quick menus disabled. Repair those rows.
        foreach (['hr-self-shift-schedule', 'hr-self-leave-request'] as $code) {
            $menuId = DB::table('access_menus')->where('code', $code)->value('id');
            if ($menuId) $this->repairOperationalSelfMenu((string) $menuId, $code, $now);
        }
    }

    private function mirrorViewCapabilityToAction(string $menuId, string $actionColumn, $now): void
    {
        if (! Schema::hasTable('access_role_menu_permissions')) return;
        DB::table('access_role_menu_permissions')
            ->where('menu_id', $menuId)
            ->where('can_view', true)
            ->update([$actionColumn => true, 'updated_at' => $now]);
    }

    private function repairOperationalSelfMenu(string $menuId, string $menuCode, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;

        // Repair both existing and missing role/level rows. This is intentionally an
        // upsert because old installations may have a customized/incomplete matrix.
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];

        foreach (DB::table('access_roles')->get(['id', 'code']) as $role) {
            $code = strtoupper(trim((string) $role->code));
            $allowed = ! in_array($code, self::OPERATIONAL_EXCLUSIONS, true);

            foreach (array_merge([null], $levels) as $levelId) {
                $rights = [
                    'can_view' => $allowed,
                    'can_create' => $menuCode === 'hr-self-leave-request' ? $allowed : false,
                    'can_edit' => false,
                    'can_delete' => $menuCode === 'hr-self-leave-request' ? $allowed : false,
                ];
                $this->upsertMatrix((string) $role->id, $levelId, $menuId, $rights, $now);
            }
        }
    }

    private function upsertMatrix(string $roleId, ?string $levelId, string $menuId, array $rights, $now): void
    {
        $query = DB::table('access_role_menu_permissions')
            ->where('access_role_id', $roleId)
            ->where('menu_id', $menuId);
        $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
        $existing = $query->first();

        $payload = array_merge($rights, ['updated_at' => $now]);
        if ($existing) {
            DB::table('access_role_menu_permissions')->where('id', $existing->id)->update($payload);
            return;
        }

        DB::table('access_role_menu_permissions')->insert(array_merge($payload, [
            'id' => (string) Str::ulid(),
            'access_role_id' => $roleId,
            'access_level_id' => $levelId,
            'menu_id' => $menuId,
            'created_at' => $now,
        ]));
    }

    private function hideLegacyUsersMenu(): void
    {
        if (! Schema::hasTable('access_menus')) return;
        $hrPortal = Schema::hasTable('access_portals')
            ? DB::table('access_portals')->where('code', 'human-resource')->value('id')
            : null;

        $query = DB::table('access_menus')->where(function ($q): void {
            $q->where('code', 'hr-users')
                ->orWhereRaw("LOWER(TRIM(COALESCE(path,''))) = '/users'");
        });
        if ($hrPortal) $query->where('portal_id', $hrPortal);
        $query->update(['is_active' => false, 'updated_at' => now()]);
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            $database = DB::connection()->getDatabaseName();
            return DB::table('information_schema.statistics')
                ->where('table_schema', $database)
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function down(): void
    {
        // Non-destructive: attendance metrics and Access Matrix repairs are audit-relevant.
    }
};
