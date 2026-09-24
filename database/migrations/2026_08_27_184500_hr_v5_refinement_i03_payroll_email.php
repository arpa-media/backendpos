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
        if (! Schema::hasTable('HR_payroll_slip_email_logs')) {
            Schema::create('HR_payroll_slip_email_logs', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->string('document_key', 120)->unique('hr_slip_mail_doc_uq');
                $t->string('source_type', 20)->index('hr_slip_mail_source_idx');
                $t->char('source_id', 26)->index('hr_slip_mail_source_id_idx');
                $t->char('payroll_cutoff_id', 26)->nullable()->index('hr_slip_mail_cutoff_idx');
                $t->char('payroll_slip_id', 26)->nullable()->index('hr_slip_mail_slip_idx');
                $t->char('bonus_projection_id', 26)->nullable()->index('hr_slip_mail_bonus_idx');
                $t->char('bonus_line_id', 26)->nullable();
                $t->char('employee_id', 26)->index('hr_slip_mail_emp_idx');
                $t->string('recipient_email', 190);
                $t->string('attachment_name', 255);
                $t->char('attachment_sha256', 64)->nullable();
                $t->string('status', 20)->default('pending')->index('hr_slip_mail_status_idx');
                $t->unsignedSmallInteger('attempts')->default(0);
                $t->timestamp('last_attempt_at')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->char('sending_token', 26)->nullable();
                $t->timestamp('sending_started_at')->nullable();
                $t->text('error_message')->nullable();
                $t->json('metadata')->nullable();
                $t->char('requested_by_user_id', 26)->nullable();
                $t->char('sent_by_user_id', 26)->nullable();
                $t->timestamps();

                $t->foreign('payroll_cutoff_id', 'hr_mail_cut_fk')->references('id')->on('HR_payroll_cutoffs')->cascadeOnDelete();
                $t->foreign('payroll_slip_id', 'hr_mail_slip_fk')->references('id')->on('HR_payroll_slips')->cascadeOnDelete();
                $t->foreign('bonus_projection_id', 'hr_mail_bonus_fk')->references('id')->on('HR_bonus_projections')->cascadeOnDelete();
                $t->foreign('bonus_line_id', 'hr_mail_bline_fk')->references('id')->on('HR_bonus_projection_lines')->cascadeOnDelete();
                $t->foreign('employee_id', 'hr_mail_emp_fk')->references('id')->on('employees')->restrictOnDelete();
                $t->foreign('requested_by_user_id', 'hr_mail_req_fk')->references('id')->on('users')->nullOnDelete();
                $t->foreign('sent_by_user_id', 'hr_mail_sent_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            Permission::findOrCreate('hr.payroll.cutoff.email', $guard);
            Permission::findOrCreate('hr.bonus.projection.email', $guard);
            if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('HR_payroll_slip_email_logs');
        if (Schema::hasTable('permissions')) {
            Permission::query()->whereIn('name', ['hr.payroll.cutoff.email', 'hr.bonus.projection.email'])->delete();
            if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
