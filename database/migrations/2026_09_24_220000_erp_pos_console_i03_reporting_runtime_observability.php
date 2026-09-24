<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_pipeline_runtime_status')) {
            Schema::create('report_pipeline_runtime_status', function (Blueprint $table): void {
                $table->string('pipeline', 20)->primary();
                $table->string('state', 32)->default('waiting_first_run');
                $table->timestamp('last_started_at')->nullable();
                $table->timestamp('last_finished_at')->nullable();
                $table->timestamp('last_succeeded_at')->nullable();
                $table->timestamp('last_failed_at')->nullable();
                $table->unsignedInteger('last_duration_ms')->nullable();
                $table->text('last_result')->nullable();
                $table->text('last_error')->nullable();
                $table->unsignedBigInteger('run_count')->default(0);
                $table->unsignedBigInteger('skip_count')->default(0);
                $table->timestamps();
            });
        }

        $now = now();
        foreach (['daily', 'hourly', 'monthly'] as $pipeline) {
            DB::table('report_pipeline_runtime_status')->updateOrInsert(
                ['pipeline' => $pipeline],
                ['state' => 'waiting_first_run', 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    public function down(): void
    {
        // Keep runtime history non-destructively; operational observability data is safe to retain.
    }
};
