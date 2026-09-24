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
        $this->createOvertimeTables();
        $this->registerAccessMatrix();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function createOvertimeTables(): void
    {
        if (! Schema::hasTable('HR_overtimes')) {
            Schema::create('HR_overtimes', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('attendance_id', 26)->nullable();
                $table->char('user_id', 26)->nullable();
                $table->char('employee_id', 26);
                $table->unsignedBigInteger('squad_id')->nullable();
                $table->char('outlet_id', 26)->nullable();
                $table->date('business_date');
                $table->string('timezone', 64)->default('Asia/Jakarta');

                $table->string('source', 32)->default('self-service')->index(); // self-service|manual_hr
                $table->string('status', 20)->default('open')->index(); // open|completed|cancelled
                $table->dateTime('start_at')->comment('Stored as UTC');
                $table->dateTime('end_at')->nullable()->comment('Stored as UTC');
                $table->unsignedInteger('overtime_minutes')->default(0);
                $table->decimal('overtime_rate_snapshot', 15, 2)->default(0);
                $table->decimal('amount_snapshot', 18, 2)->default(0);
                $table->text('note')->nullable();
                $table->text('manual_reason')->nullable();

                $table->char('created_by_user_id', 26)->nullable();
                $table->char('updated_by_user_id', 26)->nullable();
                $table->char('cancelled_by_user_id', 26)->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancel_reason')->nullable();
                $table->timestamps();

                // Exactly one effective overtime record per employee/business date.
                // Corrections update the same record instead of creating duplicate payroll minutes.
                $table->unique(['employee_id', 'business_date'], 'hr_ot_emp_date_uq');
                $table->index(['outlet_id', 'business_date'], 'hr_ot_outlet_date_idx');
                $table->index(['business_date', 'status'], 'hr_ot_date_status_idx');
                $table->index(['attendance_id', 'status'], 'hr_ot_att_status_idx');

                if (Schema::hasTable('HR_attendances')) {
                    $table->foreign('attendance_id', 'hr_ot_att_fk')->references('id')->on('HR_attendances')->nullOnDelete();
                }
                if (Schema::hasTable('users')) {
                    $table->foreign('user_id', 'hr_ot_user_fk')->references('id')->on('users')->nullOnDelete();
                    $table->foreign('created_by_user_id', 'hr_ot_creator_fk')->references('id')->on('users')->nullOnDelete();
                    $table->foreign('updated_by_user_id', 'hr_ot_updater_fk')->references('id')->on('users')->nullOnDelete();
                    $table->foreign('cancelled_by_user_id', 'hr_ot_cancel_fk')->references('id')->on('users')->nullOnDelete();
                }
                if (Schema::hasTable('employees')) {
                    $table->foreign('employee_id', 'hr_ot_employee_fk')->references('id')->on('employees')->cascadeOnDelete();
                }
                if (Schema::hasTable('outlets')) {
                    $table->foreign('outlet_id', 'hr_ot_outlet_fk')->references('id')->on('outlets')->nullOnDelete();
                }
            });
        }

        if (! Schema::hasTable('HR_overtime_logs')) {
            Schema::create('HR_overtime_logs', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('overtime_id', 26)->index();
                $table->string('action', 32)->index();
                $table->char('actor_user_id', 26)->nullable();
                $table->string('actor_name_snapshot', 180)->nullable();
                $table->text('note')->nullable();
                $table->json('before_json')->nullable();
                $table->json('after_json')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['overtime_id', 'created_at'], 'hr_ot_log_row_idx');
                $table->foreign('overtime_id', 'hr_ot_log_ot_fk')->references('id')->on('HR_overtimes')->cascadeOnDelete();
                if (Schema::hasTable('users')) {
                    $table->foreign('actor_user_id', 'hr_ot_log_user_fk')->references('id')->on('users')->nullOnDelete();
                }
            });
        }
    }

    private function registerAccessMatrix(): void
    {
        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            foreach (['hr.attendance.overtime.view', 'hr.attendance.overtime.create', 'hr.attendance.overtime.update'] as $permission) {
                Permission::findOrCreate($permission, $guard);
            }
        }

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;

        $now = now();
        $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
        $portalId = (string) ($portal->id ?? Str::ulid());
        DB::table('access_portals')->updateOrInsert(['code' => 'human-resource'], [
            'id' => $portalId,
            'name' => 'Human Resource',
            'description' => 'Portal Human Resource',
            'sort_order' => 20,
            'is_active' => true,
            'created_at' => $portal->created_at ?? $now,
            'updated_at' => $now,
        ]);

        $existing = DB::table('access_menus')->where('code', 'hr-attendance-overtime')->first();
        $menuId = (string) ($existing->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(['code' => 'hr-attendance-overtime'], [
            'id' => $menuId,
            'portal_id' => $portalId,
            'name' => 'Data Lembur',
            'path' => '/human-resource/data-absensi-data-lembur',
            'sort_order' => 34,
            'permission_view' => 'hr.attendance.overtime.view',
            'permission_create' => 'hr.attendance.overtime.create',
            'permission_update' => 'hr.attendance.overtime.update',
            'permission_delete' => null,
            'is_active' => true,
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ]);

        $this->seedMatrixFromDailyReport($menuId, $portalId, $now);
    }

    private function seedMatrixFromDailyReport(string $menuId, string $portalId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;

        $dailyMenuId = DB::table('access_menus')->where('code', 'hr-attendance-daily-report')->value('id');
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];

        foreach (DB::table('access_roles')->get(['id', 'code']) as $role) {
            $roleCode = strtoupper(trim((string) $role->code));
            foreach (array_merge([null], $levels) as $levelId) {
                $source = null;
                if ($dailyMenuId) {
                    $query = DB::table('access_role_menu_permissions')
                        ->where('access_role_id', $role->id)
                        ->where('menu_id', $dailyMenuId);
                    $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
                    $source = $query->first();
                }

                $fallbackView = in_array($roleCode, ['ADMIN', 'MANAGER'], true);
                $canView = $source ? (bool) $source->can_view : $fallbackView;
                $canManage = $source ? (bool) $source->can_edit : $fallbackView;

                $query = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $role->id)
                    ->where('menu_id', $menuId);
                $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
                $row = $query->first();
                $payload = [
                    'can_view' => $canView,
                    'can_create' => $canManage,
                    'can_edit' => $canManage,
                    'can_delete' => false,
                    'updated_at' => $now,
                ];

                if ($row) {
                    // Do not overwrite customized access when the migration is re-run.
                    continue;
                }

                DB::table('access_role_menu_permissions')->insert(array_merge($payload, [
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $role->id,
                    'access_level_id' => $levelId,
                    'menu_id' => $menuId,
                    'created_at' => $now,
                ]));
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: overtime is payroll/audit data and Access Matrix can be customized after deployment.
    }
};
