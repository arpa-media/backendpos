<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureManualColumns();
        $this->ensureAuditTable();

        if (Schema::hasTable('permissions')) {
            Permission::findOrCreate('hr.attendance.daily-report.manual.create', config('auth.defaults.guard', 'web'));
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function ensureManualColumns(): void
    {
        if (! Schema::hasTable('HR_attendances')) return;

        $missing = [
            'manual_created_by_user_id' => ! Schema::hasColumn('HR_attendances', 'manual_created_by_user_id'),
            'manual_updated_by_user_id' => ! Schema::hasColumn('HR_attendances', 'manual_updated_by_user_id'),
            'manual_reason' => ! Schema::hasColumn('HR_attendances', 'manual_reason'),
            'manual_approval_note' => ! Schema::hasColumn('HR_attendances', 'manual_approval_note'),
            'manual_created_at' => ! Schema::hasColumn('HR_attendances', 'manual_created_at'),
            'manual_updated_at' => ! Schema::hasColumn('HR_attendances', 'manual_updated_at'),
        ];
        if (! in_array(true, $missing, true)) return;

        Schema::table('HR_attendances', function (Blueprint $table) use ($missing): void {
            if ($missing['manual_created_by_user_id']) $table->char('manual_created_by_user_id', 26)->nullable();
            if ($missing['manual_updated_by_user_id']) $table->char('manual_updated_by_user_id', 26)->nullable();
            if ($missing['manual_reason']) $table->text('manual_reason')->nullable();
            if ($missing['manual_approval_note']) $table->text('manual_approval_note')->nullable();
            if ($missing['manual_created_at']) $table->timestamp('manual_created_at')->nullable();
            if ($missing['manual_updated_at']) $table->timestamp('manual_updated_at')->nullable();
        });
    }

    private function ensureAuditTable(): void
    {
        if (Schema::hasTable('HR_attendance_manual_logs') || ! Schema::hasTable('HR_attendances')) return;

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

            $table->index(['attendance_id', 'created_at'], 'hr_att_i16_audit_att_idx');
            $table->foreign('attendance_id', 'hr_att_i16_audit_att_fk')->references('id')->on('HR_attendances')->cascadeOnDelete();
            if (Schema::hasTable('users')) {
                $table->foreign('actor_user_id', 'hr_att_i16_audit_usr_fk')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        // Non-destructive: attendance/audit data must remain intact.
    }
};
