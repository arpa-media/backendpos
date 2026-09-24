<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('report_materialization_run_chunks') && ! Schema::hasColumn('report_materialization_run_chunks', 'priority')) {
            Schema::table('report_materialization_run_chunks', function (Blueprint $table): void {
                $table->unsignedSmallInteger('priority')->default(0)->after('sequence');
                $table->index(['run_id', 'stage', 'status', 'priority', 'sequence'], 'rmchunks_run_stage_prio_idx');
            });
        }

        if (! Schema::hasTable('report_materialization_recovery_requests')) {
            Schema::create('report_materialization_recovery_requests', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('requested_by')->nullable();
                $table->string('pipeline', 20);
                $table->json('outlet_ids');
                $table->date('date_from');
                $table->date('date_to');
                $table->string('status', 20)->default('queued');
                $table->ulid('run_id')->nullable();
                $table->timestamp('attached_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at'], 'rmrecovery_status_created_idx');
                $table->index(['run_id', 'status'], 'rmrecovery_run_status_idx');
                $table->index(['pipeline', 'date_from', 'date_to'], 'rmrecovery_scope_idx');
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: recovery history is operational audit data.
    }
};
