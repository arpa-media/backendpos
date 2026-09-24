<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('HR_recruitment_presence_windows')) {
            Schema::create('HR_recruitment_presence_windows', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('application_id');
                $table->char('schedule_id', 26);
                $table->string('flow_type', 32); // interview / practical / onboarding_contract
                $table->unsignedSmallInteger('sequence_no')->default(1);
                $table->string('status', 16)->default('open'); // open / closed
                $table->foreignUlid('opened_by_user_id')->nullable();
                $table->timestamp('opened_at');
                $table->foreignUlid('closed_by_user_id')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->text('note')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['schedule_id', 'sequence_no'], 'hr_rpw_sched_seq_uq');
                $table->index(['application_id', 'flow_type', 'status'], 'hr_rpw_app_flow_st_idx');
                $table->index(['schedule_id', 'status'], 'hr_rpw_sched_st_idx');
                $table->foreign('application_id', 'hr_rpw_app_fk')->references('id')->on('HR_applications')->cascadeOnDelete();
                $table->foreign('schedule_id', 'hr_rpw_sched_fk')->references('id')->on('HR_recruitment_flow_schedules')->cascadeOnDelete();
                $table->foreign('opened_by_user_id', 'hr_rpw_open_user_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('closed_by_user_id', 'hr_rpw_close_user_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('HR_recruitment_presence_events')) {
            Schema::create('HR_recruitment_presence_events', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('application_id');
                $table->char('schedule_id', 26);
                $table->char('presence_window_id', 26);
                $table->foreignUlid('career_account_id');
                $table->string('flow_type', 32);
                $table->string('workflow_stage_snapshot', 40);
                $table->timestamp('present_at');
                $table->string('source', 32)->default('career_self_service');
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['schedule_id', 'career_account_id'], 'hr_rpe_sched_career_uq');
                $table->index(['application_id', 'flow_type', 'present_at'], 'hr_rpe_app_flow_time_idx');
                $table->foreign('application_id', 'hr_rpe_app_fk')->references('id')->on('HR_applications')->cascadeOnDelete();
                $table->foreign('schedule_id', 'hr_rpe_sched_fk')->references('id')->on('HR_recruitment_flow_schedules')->cascadeOnDelete();
                $table->foreign('presence_window_id', 'hr_rpe_win_fk')->references('id')->on('HR_recruitment_presence_windows')->cascadeOnDelete();
                $table->foreign('career_account_id', 'hr_rpe_career_fk')->references('id')->on('HR_career_accounts')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Audit-safe by design. Recruitment presence history is intentionally retained.
    }
};
