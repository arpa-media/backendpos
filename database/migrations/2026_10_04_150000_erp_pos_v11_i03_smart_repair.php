<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_smart_repair_runs')) {
            Schema::create('report_smart_repair_runs', function (Blueprint $table): void {
                $table->char('id', 26)->primary();
                $table->char('reconciliation_run_id', 26)->nullable();
                $table->string('mode', 16)->default('repair');
                $table->string('status', 24)->default('running');
                $table->date('date_from');
                $table->date('date_to');
                $table->unsignedSmallInteger('outlet_count')->default(0);
                $table->unsignedInteger('candidate_rows')->default(0);
                $table->unsignedInteger('repaired_rows')->default(0);
                $table->unsignedInteger('verified_rows')->default(0);
                $table->unsignedInteger('race_changed_rows')->default(0);
                $table->unsignedInteger('failed_rows')->default(0);
                $table->unsignedInteger('already_resolved_rows')->default(0);
                $table->unsignedSmallInteger('monthly_refreshed')->default(0);
                $table->decimal('final_readiness_percent', 6, 2)->default(0);
                $table->boolean('final_ready')->default(false);
                $table->char('created_by_user_id', 26)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->longText('summary_json')->nullable();
                $table->timestamps();

                $table->index(['date_from', 'date_to'], 'rsrr_range_idx');
                $table->index(['status', 'created_at'], 'rsrr_status_idx');
            });
        }

        if (! Schema::hasTable('report_smart_repair_items')) {
            Schema::create('report_smart_repair_items', function (Blueprint $table): void {
                $table->char('id', 26)->primary();
                $table->char('run_id', 26);
                $table->char('outlet_id', 26);
                $table->date('business_date');
                $table->string('status', 24);
                $table->unsignedInteger('snapshot_touch_count')->default(0);
                $table->timestamp('snapshot_last_touched_at')->nullable();
                $table->boolean('daily_rebuilt')->default(false);
                $table->boolean('daily_verified')->default(false);
                $table->boolean('hourly_refreshed')->default(false);
                $table->boolean('queue_resolved')->default(false);
                $table->string('message', 700)->nullable();
                $table->longText('verify_json')->nullable();
                $table->timestamps();

                $table->index(['run_id', 'status'], 'rsri_run_status_idx');
                $table->index(['outlet_id', 'business_date'], 'rsri_outlet_date_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_smart_repair_items');
        Schema::dropIfExists('report_smart_repair_runs');
    }
};
