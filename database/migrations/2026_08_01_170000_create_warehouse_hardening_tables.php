<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wh_go_live_runs')) {
            Schema::create('wh_go_live_runs', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('run_number', 70)->unique();
                $table->string('environment', 40)->default('production');
                $table->enum('status', ['running','passed','failed','passed_with_waiver'])->default('running')->index();
                $table->unsignedInteger('total_checks')->default(0);
                $table->unsignedInteger('passed_checks')->default(0);
                $table->unsignedInteger('failed_checks')->default(0);
                $table->unsignedInteger('warning_checks')->default(0);
                $table->json('summary')->nullable();
                $table->ulid('started_by_user_id')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('wh_go_live_check_results')) {
            Schema::create('wh_go_live_check_results', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('go_live_run_id')->index();
                $table->string('check_code', 100)->index();
                $table->string('category', 50)->index();
                $table->enum('severity', ['info','warning','critical'])->default('critical')->index();
                $table->enum('status', ['passed','failed','waived'])->index();
                $table->text('message');
                $table->decimal('expected_value', 24, 4)->nullable();
                $table->decimal('actual_value', 24, 4)->nullable();
                $table->json('evidence')->nullable();
                $table->text('waiver_reason')->nullable();
                $table->ulid('waived_by_user_id')->nullable();
                $table->timestamp('waived_at')->nullable();
                $table->timestamps();
                $table->unique(['go_live_run_id','check_code'], 'wh_gl_check_run_code_uq');
            });
        }
        if (!Schema::hasTable('wh_operation_locks')) {
            Schema::create('wh_operation_locks', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('lock_key', 190)->unique();
                $table->string('operation_type', 80)->index();
                $table->string('owner_token', 120);
                $table->dateTime('acquired_at');
                $table->dateTime('expires_at')->index();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('wh_reversal_requests')) {
            Schema::create('wh_reversal_requests', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('request_number', 70)->unique();
                $table->string('document_type', 80)->index();
                $table->ulid('document_id')->index();
                $table->string('document_number', 100)->nullable()->index();
                $table->enum('status', ['submitted','approved','rejected','executed','cancelled'])->default('submitted')->index();
                $table->text('reason');
                $table->json('source_snapshot')->nullable();
                $table->ulid('requested_by_user_id')->nullable();
                $table->timestamp('requested_at')->nullable();
                $table->ulid('approved_by_user_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->text('approval_notes')->nullable();
                $table->timestamps();
                $table->unique(['document_type','document_id','status'], 'wh_reversal_doc_status_uq');
            });
        }
    }

    public function down(): void
    {
        // Non-destructive by design for go-live and audit evidence.
    }
};
