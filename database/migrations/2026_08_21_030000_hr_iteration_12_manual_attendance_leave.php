<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendAttendances();
        $this->createAttendanceManualLogs();
        $this->extendLeaveRequests();
        $this->registerPermissions();
    }

    private function extendAttendances(): void
    {
        if (! Schema::hasTable('HR_attendances')) return;

        $addCreator = ! Schema::hasColumn('HR_attendances', 'manual_created_by_user_id');
        $addUpdater = ! Schema::hasColumn('HR_attendances', 'manual_updated_by_user_id');
        $addReason = ! Schema::hasColumn('HR_attendances', 'manual_reason');
        $addApprovalNote = ! Schema::hasColumn('HR_attendances', 'manual_approval_note');
        $addCreatedAt = ! Schema::hasColumn('HR_attendances', 'manual_created_at');
        $addUpdatedAt = ! Schema::hasColumn('HR_attendances', 'manual_updated_at');

        Schema::table('HR_attendances', function (Blueprint $table) use ($addCreator, $addUpdater, $addReason, $addApprovalNote, $addCreatedAt, $addUpdatedAt): void {
            if ($addCreator) $table->char('manual_created_by_user_id', 26)->nullable();
            if ($addUpdater) $table->char('manual_updated_by_user_id', 26)->nullable();
            if ($addReason) $table->text('manual_reason')->nullable();
            if ($addApprovalNote) $table->text('manual_approval_note')->nullable();
            if ($addCreatedAt) $table->timestamp('manual_created_at')->nullable();
            if ($addUpdatedAt) $table->timestamp('manual_updated_at')->nullable();
            if ($addCreator) $table->foreign('manual_created_by_user_id', 'hr_att_manual_creator_fk')->references('id')->on('users')->nullOnDelete();
            if ($addUpdater) $table->foreign('manual_updated_by_user_id', 'hr_att_manual_updater_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createAttendanceManualLogs(): void
    {
        if (Schema::hasTable('HR_attendance_manual_logs')) return;

        Schema::create('HR_attendance_manual_logs', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('attendance_id', 26);
            $table->string('action', 40)->index();
            $table->char('actor_user_id', 26)->nullable();
            $table->string('actor_name_snapshot', 255)->nullable();
            $table->text('reason')->nullable();
            $table->text('approval_note')->nullable();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['attendance_id', 'created_at'], 'hr_att_manual_log_att_idx');
            $table->foreign('attendance_id', 'hr_att_manual_log_att_fk')->references('id')->on('HR_attendances')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'hr_att_manual_log_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function extendLeaveRequests(): void
    {
        if (! Schema::hasTable('HR_leave_requests')) return;

        $addSource = ! Schema::hasColumn('HR_leave_requests', 'source');
        $addCreator = ! Schema::hasColumn('HR_leave_requests', 'manual_created_by_user_id');
        $addCreatedAt = ! Schema::hasColumn('HR_leave_requests', 'manual_created_at');
        $addNote = ! Schema::hasColumn('HR_leave_requests', 'manual_note');

        Schema::table('HR_leave_requests', function (Blueprint $table) use ($addSource, $addCreator, $addCreatedAt, $addNote): void {
            if ($addSource) $table->string('source', 32)->default('self-service')->index();
            if ($addCreator) $table->char('manual_created_by_user_id', 26)->nullable();
            if ($addCreatedAt) $table->timestamp('manual_created_at')->nullable();
            if ($addNote) $table->text('manual_note')->nullable();
            if ($addCreator) $table->foreign('manual_created_by_user_id', 'hr_leave_manual_creator_fk')->references('id')->on('users')->nullOnDelete();
        });

        DB::table('HR_leave_requests')->whereNull('source')->update(['source' => 'self-service']);
    }

    private function registerPermissions(): void
    {
        if (! Schema::hasTable('permissions')) return;
        $guard = config('auth.defaults.guard', 'web');
        foreach ([
            'hr.attendance.manual.create',
            'hr.attendance.manual.update',
            'hr.leave.manual.create',
        ] as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Non-destructive: attendance and leave data are payroll/audit evidence.
    }
};
