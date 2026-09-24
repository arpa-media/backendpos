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
        $this->createLeaveRequests();
        $this->createApprovalLogs();
        $this->createQuotaLedger();
        $this->seedAccessMatrix();
    }

    private function createLeaveRequests(): void
    {
        if (Schema::hasTable('HR_leave_requests')) return;

        Schema::create('HR_leave_requests', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('employee_id', 26);
            $table->char('user_id', 26)->nullable();
            $table->string('nisj_snapshot', 100)->nullable();
            $table->string('full_name_snapshot', 255)->nullable();
            $table->char('assignment_outlet_id', 26)->nullable();
            $table->string('assignment_outlet_name_snapshot', 255)->nullable();
            $table->string('type', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('requested_days')->default(1);
            $table->unsignedSmallInteger('quota_days')->default(0);
            $table->text('reason')->nullable();
            $table->string('attachment_path', 255)->nullable();
            $table->string('attachment_original_name', 255)->nullable();
            $table->string('attachment_mime', 120)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->string('attachment_compression', 80)->nullable();
            $table->string('status', 24)->default('pending_spv');
            $table->string('spv_status', 16)->default('pending');
            $table->char('spv_by', 26)->nullable();
            $table->timestamp('spv_at')->nullable();
            $table->text('spv_note')->nullable();
            $table->string('hrd_status', 16)->default('pending');
            $table->char('hrd_by', 26)->nullable();
            $table->timestamp('hrd_at')->nullable();
            $table->text('hrd_note')->nullable();
            $table->timestamp('quota_applied_at')->nullable();
            $table->char('cancelled_by', 26)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_note')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'start_date', 'end_date'], 'hr_leave_emp_period_idx');
            $table->index(['assignment_outlet_id', 'status'], 'hr_leave_outlet_status_idx');
            $table->index(['status', 'created_at'], 'hr_leave_status_created_idx');
            $table->index(['spv_status', 'hrd_status'], 'hr_leave_approval_idx');

            $table->foreign('employee_id', 'hr_leave_employee_fk')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('user_id', 'hr_leave_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assignment_outlet_id', 'hr_leave_outlet_fk')->references('id')->on('outlets')->nullOnDelete();
            $table->foreign('spv_by', 'hr_leave_spv_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('hrd_by', 'hr_leave_hrd_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by', 'hr_leave_cancel_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createApprovalLogs(): void
    {
        if (Schema::hasTable('HR_leave_approval_logs')) return;

        Schema::create('HR_leave_approval_logs', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('leave_request_id', 26);
            $table->string('stage', 20);
            $table->string('action', 24);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->char('actor_user_id', 26)->nullable();
            $table->string('actor_name_snapshot', 255)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('acted_at');
            $table->timestamps();

            $table->index(['leave_request_id', 'acted_at'], 'hr_leave_log_request_idx');
            $table->index(['stage', 'action'], 'hr_leave_log_stage_idx');
            $table->foreign('leave_request_id', 'hr_leave_log_request_fk')->references('id')->on('HR_leave_requests')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'hr_leave_log_actor_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createQuotaLedger(): void
    {
        if (Schema::hasTable('HR_leave_quota_ledgers')) return;

        Schema::create('HR_leave_quota_ledgers', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('leave_request_id', 26)->nullable();
            $table->char('employee_id', 26);
            $table->string('nisj_snapshot', 100)->nullable();
            $table->integer('delta_days');
            $table->unsignedInteger('balance_before');
            $table->unsignedInteger('balance_after');
            $table->string('reason', 120);
            $table->char('actor_user_id', 26)->nullable();
            $table->timestamp('effective_at');
            $table->timestamps();

            $table->unique('leave_request_id', 'hr_leave_quota_request_uq');
            $table->index(['employee_id', 'effective_at'], 'hr_leave_quota_emp_idx');
            $table->foreign('leave_request_id', 'hr_leave_quota_request_fk')->references('id')->on('HR_leave_requests')->nullOnDelete();
            $table->foreign('employee_id', 'hr_leave_quota_employee_fk')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('actor_user_id', 'hr_leave_quota_actor_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function seedAccessMatrix(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;

        $now = now();
        $hrPortalId = $this->ensurePortal('human-resource', 'Human Resource', 'Portal Human Resource', 20, $now);
        $attendancePortalId = $this->ensurePortal('attendance', 'Absensi Squad', 'Self service absensi, jadwal, dan ijin/cuti user', 21, $now);

        $approvalMenuId = $this->ensureMenu(
            $hrPortalId,
            'hr-approval-ijin',
            'Approval Ijin',
            '/human-resource/approval-ijin',
            43,
            'hr.leave.approval.view',
            'hr.leave.approval.spv',
            'hr.leave.approval.hrd',
            'hr.leave.approval.override_spv',
            $now
        );

        $selfMenuId = $this->ensureMenu(
            $attendancePortalId,
            'hr-self-leave-request',
            'Izin & Cuti',
            '/user-dashboard',
            30,
            'hr.leave.self.view',
            'hr.leave.self.create',
            null,
            'hr.leave.self.cancel',
            $now
        );

        $guard = config('auth.defaults.guard', 'web');
        foreach ([
            'hr.leave.self.view', 'hr.leave.self.create', 'hr.leave.self.cancel',
            'hr.leave.approval.view', 'hr.leave.approval.spv', 'hr.leave.approval.hrd', 'hr.leave.approval.override_spv',
        ] as $permission) {
            if (Schema::hasTable('permissions')) Permission::findOrCreate($permission, $guard);
        }

        $this->seedSelfMatrix($selfMenuId, $now);
        $this->seedApprovalMatrix($approvalMenuId, $hrPortalId, $now);

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function ensurePortal(string $code, string $name, string $description, int $sort, $now): string
    {
        $existing = DB::table('access_portals')->where('code', $code)->first();
        $id = (string) ($existing->id ?? Str::ulid());
        DB::table('access_portals')->updateOrInsert(['code' => $code], [
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'sort_order' => $sort,
            'is_active' => true,
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    private function ensureMenu(string $portalId, string $code, string $name, string $path, int $sort, ?string $view, ?string $create, ?string $update, ?string $delete, $now): string
    {
        $existing = DB::table('access_menus')->where('code', $code)->first();
        $id = (string) ($existing->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(['code' => $code], [
            'id' => $id,
            'portal_id' => $portalId,
            'name' => $name,
            'path' => $path,
            'sort_order' => $sort,
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

    private function seedSelfMatrix(string $menuId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];

        foreach (DB::table('access_roles')->get(['id', 'code']) as $role) {
            $allowed = ! in_array(strtoupper(trim((string) $role->code)), ['STAKEHOLDER', 'OBSERVER'], true);
            foreach (array_merge([null], $levels) as $levelId) {
                $this->insertMatrixIfMissing($role->id, $levelId, $menuId, $allowed, $allowed, false, $allowed, $now);
            }
        }
    }

    private function seedApprovalMatrix(string $menuId, string $portalId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];

        foreach (DB::table('access_roles')->get(['id', 'code']) as $role) {
            $code = strtoupper(trim((string) $role->code));
            $isAdmin = $code === 'ADMIN';
            $isManager = $code === 'MANAGER';
            foreach (array_merge([null], $levels) as $levelId) {
                $portalCanView = $isAdmin || $isManager;
                if (Schema::hasTable('access_role_portal_permissions')) {
                    $base = DB::table('access_role_portal_permissions')
                        ->where('access_role_id', $role->id)->where('portal_id', $portalId)->whereNull('access_level_id')->first();
                    $exact = $levelId === null ? null : DB::table('access_role_portal_permissions')
                        ->where('access_role_id', $role->id)->where('portal_id', $portalId)->where('access_level_id', $levelId)->first();
                    $effective = $exact ?: $base;
                    if ($effective) $portalCanView = $portalCanView || (bool) $effective->can_view;
                }
                $this->insertMatrixIfMissing($role->id, $levelId, $menuId, $portalCanView, $isAdmin, $isAdmin, $isAdmin, $now);
            }
        }
    }

    private function insertMatrixIfMissing($roleId, $levelId, string $menuId, bool $view, bool $create, bool $edit, bool $delete, $now): void
    {
        $query = DB::table('access_role_menu_permissions')->where('access_role_id', $roleId)->where('menu_id', $menuId);
        $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
        if ($query->exists()) return;

        DB::table('access_role_menu_permissions')->insert([
            'id' => (string) Str::ulid(),
            'access_role_id' => $roleId,
            'access_level_id' => $levelId,
            'menu_id' => $menuId,
            'can_view' => $view,
            'can_create' => $create,
            'can_edit' => $edit,
            'can_delete' => $delete,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Non-destructive by design. Leave/approval/quota records are payroll-relevant audit data.
    }
};
