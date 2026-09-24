<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendApplications();
        $this->extendInterviews();
        $this->createFlowSchedules();
        $this->createPracticalTests();
        $this->createWorkflowEvents();
        $this->backfillWorkflowState();
    }

    private function extendApplications(): void
    {
        if (! Schema::hasTable('HR_applications')) return;

        if (! Schema::hasColumn('HR_applications', 'workflow_stage')) {
            Schema::table('HR_applications', function (Blueprint $table): void {
                $table->string('workflow_stage', 40)->nullable()->after('stage');
            });
        }
        if (! Schema::hasColumn('HR_applications', 'workflow_outcome')) {
            Schema::table('HR_applications', function (Blueprint $table): void {
                $table->string('workflow_outcome', 40)->nullable()->after('workflow_stage');
            });
        }
        if (! Schema::hasColumn('HR_applications', 'workflow_updated_at')) {
            Schema::table('HR_applications', function (Blueprint $table): void {
                $table->timestamp('workflow_updated_at')->nullable()->after('stage_changed_at');
            });
        }

        if (! $this->indexExists('HR_applications', 'hr_app_wf_stage_idx')) {
            Schema::table('HR_applications', function (Blueprint $table): void {
                $table->index(['workflow_stage', 'workflow_outcome'], 'hr_app_wf_stage_idx');
            });
        }
    }

    private function extendInterviews(): void
    {
        if (! Schema::hasTable('HR_interviews')) return;

        if (! Schema::hasColumn('HR_interviews', 'purpose_answer')) {
            Schema::table('HR_interviews', function (Blueprint $table): void {
                $table->longText('purpose_answer')->nullable()->after('interviewer_name_snapshot');
            });
        }
        if (! Schema::hasColumn('HR_interviews', 'characteristics_answer')) {
            Schema::table('HR_interviews', function (Blueprint $table): void {
                $table->longText('characteristics_answer')->nullable()->after('purpose_answer');
            });
        }
        if (! Schema::hasColumn('HR_interviews', 'technical_answer')) {
            Schema::table('HR_interviews', function (Blueprint $table): void {
                $table->longText('technical_answer')->nullable()->after('characteristics_answer');
            });
        }
    }

    private function createFlowSchedules(): void
    {
        if (Schema::hasTable('HR_recruitment_flow_schedules')) return;

        Schema::create('HR_recruitment_flow_schedules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id');
            $table->string('flow_type', 32); // interview / practical / onboarding_contract
            $table->unsignedSmallInteger('sequence_no')->default(1);
            $table->dateTime('scheduled_at');
            $table->string('status', 24)->default('scheduled'); // scheduled / rescheduled / completed / cancelled
            $table->char('rescheduled_from_id', 26)->nullable();
            $table->text('reschedule_reason')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['application_id', 'flow_type', 'sequence_no'], 'hr_rfs_app_flow_seq_uq');
            $table->index(['flow_type', 'status', 'scheduled_at'], 'hr_rfs_flow_time_idx');
            $table->foreign('application_id', 'hr_rfs_app_fk')->references('id')->on('HR_applications')->cascadeOnDelete();
            $table->foreign('rescheduled_from_id', 'hr_rfs_prev_fk')->references('id')->on('HR_recruitment_flow_schedules')->nullOnDelete();
            $table->foreign('created_by_user_id', 'hr_rfs_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createPracticalTests(): void
    {
        if (Schema::hasTable('HR_recruitment_practical_tests')) return;

        Schema::create('HR_recruitment_practical_tests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id');
            $table->unsignedSmallInteger('sequence_no')->default(1);
            $table->char('schedule_id', 26)->nullable();
            $table->dateTime('conducted_at')->nullable();
            $table->foreignUlid('evaluator_user_id')->nullable();
            $table->string('evaluator_name_snapshot', 180)->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->string('result', 32); // passed / not_passed / reserve
            $table->longText('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['application_id', 'sequence_no'], 'hr_rpt_app_seq_uq');
            $table->index(['application_id', 'recorded_at'], 'hr_rpt_app_date_idx');
            $table->foreign('application_id', 'hr_rpt_app_fk')->references('id')->on('HR_applications')->cascadeOnDelete();
            $table->foreign('schedule_id', 'hr_rpt_sched_fk')->references('id')->on('HR_recruitment_flow_schedules')->nullOnDelete();
            $table->foreign('evaluator_user_id', 'hr_rpt_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createWorkflowEvents(): void
    {
        if (Schema::hasTable('HR_recruitment_workflow_events')) return;

        Schema::create('HR_recruitment_workflow_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id');
            $table->string('event_type', 48);
            $table->string('from_stage', 40)->nullable();
            $table->string('to_stage', 40)->nullable();
            $table->string('result', 40)->nullable();
            $table->char('schedule_id', 26)->nullable();
            $table->char('interview_id', 26)->nullable();
            $table->char('practical_test_id', 26)->nullable();
            $table->foreignUlid('actor_user_id')->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['application_id', 'occurred_at'], 'hr_rwe_app_time_idx');
            $table->index(['to_stage', 'event_type'], 'hr_rwe_stage_evt_idx');
            $table->foreign('application_id', 'hr_rwe_app_fk')->references('id')->on('HR_applications')->cascadeOnDelete();
            $table->foreign('schedule_id', 'hr_rwe_sched_fk')->references('id')->on('HR_recruitment_flow_schedules')->nullOnDelete();
            $table->foreign('interview_id', 'hr_rwe_int_fk')->references('id')->on('HR_interviews')->nullOnDelete();
            $table->foreign('practical_test_id', 'hr_rwe_prac_fk')->references('id')->on('HR_recruitment_practical_tests')->nullOnDelete();
            $table->foreign('actor_user_id', 'hr_rwe_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function backfillWorkflowState(): void
    {
        if (! Schema::hasTable('HR_applications') || ! Schema::hasColumn('HR_applications', 'workflow_stage')) return;

        DB::table('HR_applications')->whereNull('workflow_stage')->update([
            'workflow_stage' => DB::raw("CASE LOWER(COALESCE(stage,''))
                WHEN 'call_for_interview' THEN 'interview_called'
                WHEN 'interviewed' THEN 'interview_result'
                WHEN 'rejected_partial' THEN 'interview_result'
                WHEN 'accepted_spt' THEN 'completed'
                WHEN 'accepted_pkwt' THEN 'completed'
                WHEN 'rejected_all' THEN 'completed'
                ELSE 'applied' END"),
            'workflow_outcome' => DB::raw("CASE LOWER(COALESCE(stage,''))
                WHEN 'interviewed' THEN 'passed'
                WHEN 'rejected_partial' THEN 'reserve'
                WHEN 'accepted_spt' THEN 'accepted_spt'
                WHEN 'accepted_pkwt' THEN 'accepted_pkwt'
                WHEN 'rejected_all' THEN 'not_passed'
                ELSE NULL END"),
            'workflow_updated_at' => DB::raw('COALESCE(stage_changed_at, updated_at, created_at)'),
        ]);
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = DB::connection()->getDatabaseName();
        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    public function down(): void
    {
        // Non-destructive by design. Recruitment workflow history is an HR audit record.
    }
};
